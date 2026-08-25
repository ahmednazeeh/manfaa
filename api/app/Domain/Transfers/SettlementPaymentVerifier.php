<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Settlement\SettlementAllocator;
use App\Jobs\MarkBankCreditUsed;
use App\Models\PlatformBankAccount;
use App\Models\SettlementPayment;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Matching a merchant's settlement transfer against the bank's own history
 * (owner question 2026-08-19: does /settlements match automatically too?).
 *
 * The mirror of {@see PaymentVerifier}, with one real difference that makes
 * this side stronger: THE MERCHANT TYPES THEIR OWN TRANSFER REFERENCE into
 * `bank_ref` when they upload the slip. A reference we then find verbatim in
 * our own history is proof of a kind no name match can be, so it is tried
 * first and outranks everything.
 *
 * Falling back to the name, the name to beat is `bank_account_name` — what
 * the merchant's bank actually calls them — not the trading name. "Agromart"
 * is a shop; the transfer arrives from whoever owns the account.
 *
 * The account read is the one the merchant was TOLD to pay into, recorded on
 * the settlement at build time. Not a global account, and no fallback: a
 * merchant paying our BML account is not evidenced by MIB's ledger.
 *
 * The evidence rules themselves live in {@see TransferEvidence}, shared with
 * the wallet top-up verifier that walks the same ladder.
 *
 * THE TYPED AMOUNT IS A CLAIM, THE BANK CREDIT IS THE FACT (owner,
 * 2026-08-25). The ladder identifies WHICH transfer is theirs; the matched
 * row then says how much actually arrived, and that figure is stamped on
 * `received_laari` and is what {@see SettlementAllocator::matchPayment}
 * spends. A merchant who typed one number and sent another no longer has a
 * real transfer parked in a queue over a typo: paying short lands on the
 * EXISTING partially_settled path with the remainder still owed, and paying
 * over on the EXISTING wallet-remainder path. `amount_laari` keeps their
 * claim, unchanged, forever.
 *
 * The recorded figure comes from the STATEMENT ROW, never from the OCR'd
 * slip: OCR is matching evidence, the statement is the money.
 *
 * Two consequences of dropping equality are handled below rather than
 * assumed away (verifier round, 2026-08-25):
 *
 *  - equality was also the ROW SELECTOR, so the ladder is no longer walked
 *    first-hit — every row is scored and the best wins ({@see BankRowMatch});
 *  - equality was the second lock on the merchant-controlled NAME rungs, so
 *    those keep it: a name match still requires the row to be for exactly
 *    what was claimed. Only the bank-issued reference rungs are freed.
 */
final readonly class SettlementPaymentVerifier
{
    public function __construct(
        private BankHistoryClient $history,
        private TransferEvidence $evidence,
        private SettlementAllocator $allocator,
        private BankCreditClaim $claims,
    ) {}

    /** Look once. Returns true when the payment was matched. */
    public function attempt(SettlementPayment $payment): bool
    {
        $settings = TransferSetting::current();

        if (! $settings->auto_verify_enabled) {
            return false;
        }

        if ($payment->state !== 'pending') {
            return false;
        }

        $settlement = $payment->settlement;

        if ($settlement === null) {
            return false;
        }

        $destination = $this->destination($settlement->platform_bank_account_id);

        if ($destination === null) {
            return false;
        }

        [$profile, $account] = $destination;

        $rows = $this->history->history($profile, $account);

        $expected = $this->expectedNames($payment);
        $reference = trim((string) $payment->bank_ref);
        $receipt = (string) $payment->receipt_text;

        // EVERY row is scored and the BEST one wins — never the first row
        // that answers a rung. See {@see BankRowMatch}: the amount used to be
        // what skipped the rows that were not this merchant's transfer, and
        // without it a weak name hit on an earlier row would beat the
        // merchant's own typed reference on a later one.
        $best = null;

        foreach ($rows as $row) {
            // THE AMOUNT IS NOT A GATE ANY MORE (owner, 2026-08-25). The
            // evidence below says WHICH transfer is theirs; the matched row
            // then says how much arrived, and the funding stack takes that
            // figure through the partial / over-payment paths it already
            // has. Direction and positivity remain absolute: an outgoing row
            // is us paying somebody, and a zero is not money.
            if (! $row->incoming || $row->amountLaari <= 0) {
                continue;
            }

            if ($this->claims->taken($row, exceptPayment: (int) $payment->getKey())) {
                continue;
            }

            $candidate = $this->evidenceFor($payment, $row, $reference, $receipt, $expected, (int) $settings->verify_min_score);

            if ($candidate !== null && $candidate->beats($best, (int) $payment->amount_laari, $payment->created_at?->toImmutable())) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return false;
        }

        return $this->match($payment, $best->row, $best->score, $best->rule);
    }

    /**
     * The rung this row answers, if any.
     *
     * THE NAME RUNGS STILL REQUIRE THE AMOUNT TO AGREE (verifier round,
     * 2026-08-25). Dropping equality is right for the two REFERENCE rungs:
     * those are bank-issued identifiers, the merchant cannot invent one, and
     * a transfer whose reference we can prove is theirs is theirs whatever
     * they typed on the form — which is the round's whole point.
     *
     * The name rungs are a different kind of thing. `receipt_name` and
     * `receipt_name_fuzzy` compare the bank's payer against OCR of a slip the
     * MERCHANT uploaded, and `name` against `bank_account_name`, a field the
     * merchant edits at will (Merchant\BankAccountController, no
     * verification). The reason those rungs were ever allowed to credit is
     * written on {@see WalletTopUpVerifier}: "a settlement payment may also
     * match on the payer's NAME ... because the platform fixes the amount
     * there: a stranger's credit of exactly the batch's due is a
     * coincidence." Remove equality and that sentence stops being true — a
     * merchant could claim MVR 1.00, name any payer on their own slip, and be
     * credited an unclaimed transfer of any size, with the surplus landing in
     * their wallet as spendable settlement credit.
     *
     * So they keep the gate they always had: a name rung matches only a row
     * for exactly what was claimed. Nothing that matched before this round
     * stops matching; a transfer whose figure is not the claimed one needs
     * bank-issued proof or a person.
     *
     * @param  list<string>  $expected
     */
    private function evidenceFor(
        SettlementPayment $payment,
        BankRow $row,
        string $reference,
        string $receipt,
        array $expected,
        int $minimumScore,
    ): ?BankRowMatch {
        // The merchant's own reference, seen in our history. Proof, and
        // it does not need a name to agree — a company transfer often
        // arrives under an accountant's name or a bare IPS label.
        if ($reference !== '' && TransferEvidence::sameReference($reference, $row)) {
            return new BankRowMatch($row, 'reference', BankRowMatch::RULE_REFERENCE, 100);
        }

        // The same proof, taken off the RECEIPT instead of a form.
        // Banking apps print the transaction number on the slip — the
        // real one for settlement 5 reads "Transaction# 90863389", which
        // is the history row's own reference. A merchant who typed
        // nothing has still handed us the strongest evidence there is.
        //
        // Every identifier the row carries is tried, not just the one we
        // kept: BML files its FT statement id as the reference and prints
        // a DIFFERENT one on the merchant's slip (settlement 8).
        foreach ($row->identifiers() as $identifier) {
            if (TransferEvidence::receiptQuotes($receipt, $identifier)) {
                return new BankRowMatch($row, 'receipt_reference', BankRowMatch::RULE_RECEIPT_REFERENCE, 100);
            }
        }

        // Below here the evidence is merchant-controlled text, so the
        // claimed figure is required to agree. See the docblock.
        if ($row->amountLaari !== (int) $payment->amount_laari) {
            return null;
        }

        // The bank's payer, named on the receipt. "From Interbridge Pvt
        // Ltd" contains the "INTERBRIDGE" the history gives, and that
        // agreement is the merchant's own document confirming who paid —
        // which is precisely what a person checks by eye.
        if (TransferEvidence::receiptMentions($receipt, $row->name)) {
            return new BankRowMatch($row, 'receipt_name', BankRowMatch::RULE_RECEIPT_NAME, 100);
        }

        // The same payer, allowing for how the slip spells them.
        //
        // BML's app prints "AHMD.NAZEEH" on the receipt for the "AHMED
        // NAZEEH" it files the row under — one dropped vowel, which
        // containment cannot forgive but {@see NameMatcher} was
        // calibrated for on exactly this pair. Settlement 8 sat in review
        // over it.
        //
        // This matters more than one settlement: BML does not reliably
        // carry the slip's reference in `narrative2`, so the receipt name
        // is often the only evidence a BML transfer leaves us.
        $fuzzy = $this->evidence->receiptNames($receipt, $row->name, $minimumScore);

        if ($fuzzy !== null) {
            return new BankRowMatch($row, 'receipt_name_fuzzy', BankRowMatch::RULE_RECEIPT_NAME_FUZZY, $fuzzy);
        }

        if ($expected === []) {
            return null;
        }

        // Last: the names we hold on file. A merchant who uploads an
        // unreadable slip is no worse off than before any of this.
        $score = $this->evidence->bestNameScore($expected, $row->name);

        if ($score < $minimumScore) {
            return null;
        }

        return new BankRowMatch($row, 'name', BankRowMatch::RULE_NAME, $score);
    }

    /**
     * Every name this payment could plausibly have arrived under, best
     * evidence first.
     *
     * The RECEIPT's name leads. A merchant reading their own transfer slip
     * knows who it went out as, and companies routinely pay under a parent
     * or sister entity — settlement 5 was Tea Plus settling MVR 59.50 with
     * the money arriving from INTERBRIDGE. Comparing only against the
     * store's registered account name scored that 0 and left a plainly
     * correct payment waiting for a person.
     *
     * The registered account name and the trading name stay as fallbacks,
     * so a merchant who types nothing is no worse off than before.
     *
     * @return list<string>
     */
    private function expectedNames(SettlementPayment $payment): array
    {
        $merchant = $payment->merchant ?? $payment->settlement?->merchant;

        return TransferEvidence::merchantNames($merchant?->bank_account_name, $merchant?->name);
    }

    /** The profile whose feed this payment was matched against, if any. */
    private function profileIdFor(SettlementPayment $payment): ?int
    {
        $destination = $this->destination($payment->settlement?->platform_bank_account_id);

        return $destination === null ? null : (int) $destination[0]->getKey();
    }

    /**
     * @return array{TransferProfile, string}|null
     */
    private function destination(?int $platformAccountId): ?array
    {
        if ($platformAccountId === null) {
            return null;
        }

        $platform = PlatformBankAccount::query()->find($platformAccountId);

        if ($platform === null || $platform->verify_profile_id === null) {
            return null;
        }

        $profile = TransferProfile::query()->where('active', true)->find($platform->verify_profile_id);
        $account = trim((string) $platform->account_no);

        if ($profile === null || $account === '') {
            return null;
        }

        return [$profile, $account];
    }

    private function match(SettlementPayment $payment, BankRow $row, int $score, string $rule): bool
    {
        try {
            DB::transaction(function () use ($payment, $row, $score, $rule): void {
                /** @var SettlementPayment $locked */
                $locked = SettlementPayment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-checked under the lock: an admin may have matched it
                // while we were reading the bank.
                if ($locked->state !== 'pending') {
                    return;
                }

                // Cross-table: serialise on the credit itself, then ask
                // again. Another worker may have spent it on a wallet
                // top-up or an order between our read and this lock.
                $this->claims->lock($row);

                if ($this->claims->taken($row, exceptPayment: (int) $locked->getKey())) {
                    return;
                }

                $locked->forceFill([
                    'auto_matched' => true,
                    // WHAT ACTUALLY ARRIVED, written BEFORE matchPayment
                    // below so the funding stack spends the bank's figure
                    // rather than the claim. Their claim stays in
                    // `amount_laari`, untouched.
                    'received_laari' => $row->amountLaari,
                    // Keyed on the merchant-facing reference: for BML that is
                    // the one on their slip, not the internal statement id.
                    'matched_trx_id' => $row->key(),
                    // Every name this credit answers to, so spending it here
                    // spends it everywhere. See BankCreditClaim.
                    'matched_trx_refs' => $row->identifiers(),
                    // What the bank said, not our conclusion about it.
                    'matched_payer_name' => $row->name,
                    'matched_score' => $score,
                    'matched_by_rule' => $rule,
                    'poll_until' => null,
                ])->save();

                // Everything else — allocation oldest-first, the wallet
                // remainder, the journals, the notification — goes down the
                // one path an admin's click uses. A second allocation path
                // would be a second version of the truth.
                $this->allocator->matchPayment($locked, null);

                // Hide the credit from the shared BML feed so IsleBooks
                // cannot claim it too. AFTER the commit: hiding money for a
                // match that then rolled back would lose it to both sides.
                $profileId = $this->profileIdFor($payment);

                if ($profileId !== null) {
                    DB::afterCommit(fn () => MarkBankCreditUsed::dispatch($profileId, $row->key()));
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Another payment claimed this credit first. Losing here is the
            // index doing its job.
            Log::info('Bank credit already claimed', [
                'payment' => $payment->id,
                'trx' => $row->key(),
            ]);

            return false;
        }

        $matched = $payment->fresh()?->state === 'matched';

        if ($matched) {
            Log::info('Settlement payment auto-matched', [
                'payment' => $payment->id,
                'trx' => $row->key(),
                'rule' => $rule,
                'score' => $score,
            ]);
        }

        return $matched;
    }
}
