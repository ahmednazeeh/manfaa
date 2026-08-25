<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\WalletTopUps;
use App\Jobs\MarkBankCreditUsed;
use App\Models\PlatformBankAccount;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Matching a merchant's WALLET TOP-UP transfer against the bank's own
 * history (owner, 2026-08-24) — the sibling of
 * {@see SettlementPaymentVerifier}, walking the same rows with the same
 * evidence rules ({@see TransferEvidence}) — but a SHORTER ladder:
 *
 *   reference → receipt_reference
 *
 * Bank-issued identifiers only. A settlement payment may also match on the
 * payer's NAME (on the slip, or against the registered account name) — but
 * only for a credit of EXACTLY the claimed figure, which is the form that
 * rung's old justification survives in now that equality is no longer a gate
 * in general (verifier round, 2026-08-25): a stranger's credit of exactly the
 * batch's due is a coincidence. A top-up fixes nothing — the
 * merchant chooses the amount AND writes the slip the OCR reads AND edits
 * the account name the last rung compares — so each of the name rungs
 * would become a merchant-controlled oracle over every unclaimed credit on
 * the platform account (verifier round, 2026-08-24: a slip listing common
 * names, a claim for a round amount, and the poll re-asking every minute).
 * A transfer that leaves no reference on the slip and none typed waits for
 * the admin queue, which is the designed fallback.
 *
 * THE TYPED AMOUNT IS NOT EVIDENCE AND NEVER WAS (owner, 2026-08-25, from
 * top-up #2 in production: the merchant typed MVR 20.00, the slip and BML
 * both said MVR 10.00, and requiring the two to be EQUAL parked a perfectly
 * good transfer in the admin queue for a typo). The merchant's figure is a
 * CLAIM; the bank credit is the FACT. So the ladder above identifies WHICH
 * transfer is theirs, and the matched row then says how much arrived — that
 * is what is credited, and it is stamped on `received_laari` beside the
 * untouched claim.
 *
 * Which makes the evidence the ONLY thing between a merchant and somebody
 * else's transfer, so none of it is relaxed to compensate: the 6-character
 * minimum on a typed reference and the 5-character minimum on a receipt
 * needle ({@see TransferEvidence}) stand, and the payer-name rungs stay off
 * this ladder entirely.
 *
 * The money must be INCOMING and the credit POSITIVE — a debit is us paying
 * somebody and a zero is not money — the credit must not already be spent on
 * an order, a settlement payment, another top-up or a wallet movement
 * ({@see BankCreditClaim}, now the primary guard that one credit funds one
 * thing), and the account read is the one the merchant said they paid into —
 * no fallback.
 *
 * The credited figure comes from the STATEMENT ROW, never from the OCR'd
 * slip. OCR is matching evidence; a misread digit must not move money.
 *
 * What differs is only what a match FUNDS: here the wallet is credited
 * through {@see WalletTopUps::credit}, the one path the admin's manual
 * match also takes.
 */
final readonly class WalletTopUpVerifier
{
    public function __construct(
        private BankHistoryClient $history,
        private WalletTopUps $topUps,
        private BankCreditClaim $claims,
    ) {}

    /** Look once. Returns true when the top-up was matched and credited. */
    public function attempt(WalletTopUp $topUp): bool
    {
        $settings = TransferSetting::current();

        if (! $settings->auto_verify_enabled) {
            return false;
        }

        if ($topUp->state !== 'pending') {
            return false;
        }

        $destination = $this->destination($topUp->platform_bank_account_id);

        if ($destination === null) {
            return false;
        }

        [$profile, $account] = $destination;

        $rows = $this->history->history($profile, $account);

        $reference = trim((string) $topUp->bank_ref);
        $receipt = (string) $topUp->receipt_text;

        // EVERY row is scored and the BEST one wins — never the first row
        // that answers a rung. See {@see BankRowMatch}: the amount used to be
        // what skipped the rows that were not this merchant's transfer, and
        // without it the bank's own ordering would otherwise decide which
        // credit becomes their money.
        $best = null;

        foreach ($rows as $row) {
            // THE AMOUNT IS NOT A GATE ANY MORE (owner, 2026-08-25). It is
            // read off the matched row afterwards; see the class doc.
            // Direction and positivity remain absolute: an outgoing row is
            // us paying somebody, and a zero or negative credit is money
            // that did not arrive.
            if (! $row->incoming || $row->amountLaari <= 0) {
                continue;
            }

            if ($this->claims->taken($row, exceptTopUp: (int) $topUp->getKey())) {
                continue;
            }

            $candidate = $this->evidence($row, $reference, $receipt);

            if ($candidate !== null && $candidate->beats($best, (int) $topUp->amount_laari, $topUp->created_at?->toImmutable())) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return false;
        }

        return $this->match($topUp, $best->row, $best->score, $best->rule);
    }

    /**
     * The rung this row answers, if any — bank-issued identifiers only.
     * No name rungs; see the class doc.
     */
    private function evidence(BankRow $row, string $reference, string $receipt): ?BankRowMatch
    {
        // The merchant's own reference, seen in our history.
        if ($reference !== '' && TransferEvidence::sameReference($reference, $row)) {
            return new BankRowMatch($row, 'reference', BankRowMatch::RULE_REFERENCE, 100);
        }

        // The same proof read off the slip: banking apps print the
        // transaction number, and every identifier the row carries is
        // tried (BML files one and prints another).
        foreach ($row->identifiers() as $identifier) {
            if (TransferEvidence::receiptQuotes($receipt, $identifier)) {
                return new BankRowMatch($row, 'receipt_reference', BankRowMatch::RULE_RECEIPT_REFERENCE, 100);
            }
        }

        return null;
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

    private function match(WalletTopUp $topUp, BankRow $row, int $score, string $rule): bool
    {
        try {
            DB::transaction(function () use ($topUp, $row, $score, $rule): void {
                /** @var WalletTopUp $locked */
                $locked = WalletTopUp::query()
                    ->whereKey($topUp->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-checked under the lock: an admin may have matched or
                // rejected it while we were reading the bank.
                if ($locked->state !== 'pending') {
                    return;
                }

                // Cross-table: serialise on the credit itself, then ask
                // again. Another worker may have spent it on a settlement
                // payment or an order between our read and this lock.
                $this->claims->lock($row);

                if ($this->claims->taken($row, exceptTopUp: (int) $locked->getKey())) {
                    return;
                }

                $locked->forceFill([
                    'auto_matched' => true,
                    // WHAT ACTUALLY ARRIVED, from the statement row and from
                    // nowhere else. `amount_laari` — their claim — is left
                    // exactly as they typed it, so a discrepancy stays
                    // readable for as long as the row exists.
                    'received_laari' => $row->amountLaari,
                    // Keyed on the merchant-facing reference: for BML that is
                    // the one on their slip, not the internal statement id.
                    'matched_trx_id' => $row->key(),
                    // Every name this credit answers to, so spending it here
                    // spends it everywhere. See BankCreditClaim.
                    'matched_trx_refs' => $row->identifiers(),
                    'matched_payer_name' => $row->name,
                    'matched_score' => $score,
                    'matched_by_rule' => $rule,
                ])->save();

                // The wallet movement is keyed on the merchant's own
                // reference when they typed one — that is what they quote —
                // and on the bank's key otherwise. Either way the
                // (wallet, bank_ref) index makes the credit unrepeatable.
                $bankRef = trim((string) $locked->bank_ref);

                $this->topUps->credit($locked, $bankRef !== '' ? $bankRef : $row->key(), null);

                // Hide the credit from the shared BML feed so IsleBooks
                // cannot claim it too. AFTER the commit: hiding money for a
                // match that then rolled back would lose it to both sides.
                $destination = $this->destination($topUp->platform_bank_account_id);

                if ($destination !== null) {
                    $profileId = (int) $destination[0]->getKey();
                    DB::afterCommit(fn () => MarkBankCreditUsed::dispatch($profileId, $row->key()));
                }
            });
        } catch (UniqueConstraintViolationException|DuplicateBankRefException $exception) {
            // Another claim spent this credit first, or the wallet already
            // holds a movement under this reference. Losing here is the
            // index doing its job; the claim stays pending for a person.
            Log::info('Bank credit already claimed', [
                'top_up' => $topUp->id,
                'trx' => $row->key(),
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        $matched = $topUp->fresh()?->state === 'matched';

        if ($matched) {
            Log::info('Wallet top-up auto-matched', [
                'top_up' => $topUp->id,
                'trx' => $row->key(),
                'rule' => $rule,
                'score' => $score,
            ]);
        }

        return $matched;
    }
}
