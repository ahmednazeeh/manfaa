<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Settlement\SettlementAllocator;
use App\Models\Order;
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
 */
final readonly class SettlementPaymentVerifier
{
    public function __construct(
        private BankHistoryClient $history,
        private NameMatcher $names,
        private SettlementAllocator $allocator,
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

        $expected = $this->expectedName($payment);
        $reference = trim((string) $payment->bank_ref);

        foreach ($rows as $row) {
            if (! $row->incoming || $row->amountLaari !== (int) $payment->amount_laari) {
                continue;
            }

            if ($this->claimed($row->reference, $payment)) {
                continue;
            }

            // The merchant's own reference, seen in our history. Proof, and
            // it does not need a name to agree — a company transfer often
            // arrives under an accountant's name or a bare IPS label.
            if ($reference !== '' && self::sameReference($reference, $row)) {
                return $this->match($payment, $row, 100, 'reference');
            }

            if ($expected === null) {
                continue;
            }

            $score = $this->names->score($expected, $row->name);

            if ($score === null || $score < $settings->verify_min_score) {
                continue;
            }

            return $this->match($payment, $row, $score, 'name');
        }

        return false;
    }

    /**
     * The merchant's reference against the row's.
     *
     * Compared loosely on purpose — a person retyping a reference from a
     * banking app drops spaces and hyphens, and MIB carries both a composite
     * `trxNumber` and the short `trxNumber2`. A containment test in either
     * direction catches the honest transcription without accepting a
     * different transfer: these strings are long and bank-issued.
     */
    private static function sameReference(string $typed, BankRow $row): bool
    {
        $normalise = static fn (?string $value): string => preg_replace(
            '/[^A-Z0-9]/',
            '',
            mb_strtoupper((string) $value),
        ) ?? '';

        $typed = $normalise($typed);

        if (mb_strlen($typed) < 6) {
            // Too short to be evidence. Somebody typed "123" or the batch
            // number, and a containment test on that would match anything.
            return false;
        }

        foreach ([$row->reference, $row->raw['trxNumber'] ?? null, $row->raw['descr2'] ?? null] as $candidate) {
            $candidate = $normalise(is_string($candidate) ? $candidate : null);

            if ($candidate === '') {
                continue;
            }

            if ($candidate === $typed
                || str_contains($candidate, $typed)
                || str_contains($typed, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** What the merchant's bank calls them, falling back to the shop's name. */
    private function expectedName(SettlementPayment $payment): ?string
    {
        $merchant = $payment->merchant ?? $payment->settlement?->merchant;

        foreach ([$merchant?->bank_account_name, $merchant?->name] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Has this bank credit already been spent on something?
     *
     * Checked across BOTH tables. One platform account takes customer order
     * payments and merchant settlements alike, so a credit that verified an
     * order must not also settle a batch.
     */
    private function claimed(?string $reference, SettlementPayment $payment): bool
    {
        if ($reference === null || $reference === '') {
            return true;
        }

        return SettlementPayment::query()
            ->where('matched_trx_id', $reference)
            ->whereKeyNot($payment->getKey())
            ->exists()
            || Order::query()->where('matched_trx_id', $reference)->exists();
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

                $locked->forceFill([
                    'auto_matched' => true,
                    'matched_trx_id' => $row->reference,
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
            });
        } catch (UniqueConstraintViolationException) {
            // Another payment claimed this credit first. Losing here is the
            // index doing its job.
            Log::info('Bank credit already claimed', [
                'payment' => $payment->id,
                'trx' => $row->reference,
            ]);

            return false;
        }

        $matched = $payment->fresh()?->state === 'matched';

        if ($matched) {
            Log::info('Settlement payment auto-matched', [
                'payment' => $payment->id,
                'trx' => $row->reference,
                'rule' => $rule,
                'score' => $score,
            ]);
        }

        return $matched;
    }
}
