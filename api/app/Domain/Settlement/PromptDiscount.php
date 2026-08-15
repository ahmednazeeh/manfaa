<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\TransactionState;
use App\Domain\Platform\PlatformConfig;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The prompt-payment discount (PLAN §1, decision 2026-08-15).
 *
 * **What it discounts.** The PLATFORM FEE component only. The customer's
 * cashback is never reduced by a merchant's promptness — the reward the
 * customer was promised is the reward the customer gets, and the incentive
 * comes out of our own revenue as a sales discount.
 *
 * **When it is granted.** Both conditions, together:
 *
 *   1. the batch covers EVERY outstanding `payable_unfunded` transaction the
 *      merchant has — nothing left behind. A line frozen on some earlier,
 *      still-unpaid batch counts as outstanding: it is money the merchant
 *      owes and has not paid, and the incentive is for clearing the board,
 *      not for clearing part of it;
 *   2. every included line is strictly UNDER `prompt_discount_max_age_days`
 *      old, measured in whole business-timezone days from `clock_start_at`
 *      (§13) at the moment of the check;
 *   3. every included line HAS a `clock_start_at` to measure from. A payable
 *      row whose clock never started (§13b) has no provable age at all, and
 *      an incentive refuses what it cannot prove.
 *
 * **When it is checked.** Twice, independently. The preview evaluates it so
 * the merchant knows what to transfer, and the submit path evaluates it again
 * inside the database transaction, against the locked row set, and that
 * second answer is the only one that touches money. A preview answer is never
 * passed in, trusted, or accepted from a client: between preview and submit a
 * clock ticks past midnight, another till POSTs a sale, or a second panel tab
 * starts its own batch — and each of those legitimately withdraws the
 * discount.
 *
 * **The arithmetic** (§4 integer laari, ceiling, merchant-favourable):
 *
 *     discount = intdiv(fee_total_laari * rate_bp + 9999, 10000)
 *
 * Rounding UP is deliberate: every fractional laari of discount goes to the
 * merchant, which is the same direction §4 rounds cashback and fees (always
 * against the platform, never against the counterparty).
 */
final class PromptDiscount
{
    public function __construct(private readonly PlatformConfig $config) {}

    /**
     * Evaluate the discount for a candidate batch.
     *
     * @param  Collection<int, Transaction>|list<int>  $lines  the transactions the batch covers, or their ids
     */
    public function evaluate(Merchant $merchant, Collection|array $lines, CarbonImmutable $at): PromptDiscountResult
    {
        $transactions = $this->resolve($lines);

        $rateBp = $this->config->promptDiscountRateBp();
        $maxAgeDays = $this->config->promptDiscountMaxAgeDays();

        // Totals are SUMS of the stored per-transaction integers (§4: round
        // at the line, then sum). The rate is applied to that sum ONCE — the
        // discount is a property of the batch, not of each line.
        $feeTotal = (int) $transactions->sum('fee_laari');
        $gstTotal = (int) $transactions->sum('fee_gst_laari');

        if ($rateBp <= 0) {
            return PromptDiscountResult::refused(PromptDiscountReason::Disabled, $rateBp, $maxAgeDays, $feeTotal);
        }

        if ($this->leavesSomethingBehind($merchant, $transactions)) {
            return PromptDiscountResult::refused(PromptDiscountReason::NotAllOutstanding, $rateBp, $maxAgeDays, $feeTotal);
        }

        if ($this->hasUnstartedClock($transactions)) {
            return PromptDiscountResult::refused(PromptDiscountReason::ClockNotStarted, $rateBp, $maxAgeDays, $feeTotal);
        }

        if ($this->oldestAgeDays($transactions, $at) >= $maxAgeDays) {
            return PromptDiscountResult::refused(PromptDiscountReason::LineTooOld, $rateBp, $maxAgeDays, $feeTotal);
        }

        $feeDiscount = self::ceilingBp($feeTotal, $rateBp);

        return PromptDiscountResult::granted(
            rateBp: $rateBp,
            maxAgeDays: $maxAgeDays,
            feeTotalLaari: $feeTotal,
            feeDiscountLaari: $feeDiscount,
            gstReliefLaari: self::gstRelief($gstTotal, $feeDiscount, $feeTotal),
        );
    }

    /**
     * Does this merchant still have payable money the batch does not cover?
     *
     * Deliberately measured against the transaction STATE, not against what
     * a new batch could claim: a line sitting frozen on a half-paid batch is
     * unavailable to this one, and that is exactly the situation the rule
     * refuses to reward. The merchant clears that batch first.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function leavesSomethingBehind(Merchant $merchant, Collection $transactions): bool
    {
        return Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->where('state', TransactionState::PayableUnfunded->value)
            ->whereNotIn('id', $transactions->pluck('id')->all())
            ->exists();
    }

    /**
     * Does any included line have no settlement clock at all?
     *
     * TransactionAge scores a null `clock_start_at` as age 0 — correct for a
     * dashboard bucket (nothing has elapsed since an instant that never
     * happened) and fatal for an age GATE: the `>= maxAgeDays` test can never
     * refuse such a line, so it earns the discount on every batch, forever.
     * These rows are real production data (§13b: an earlier manual hold
     * release landed in payable_unfunded with clock_start_at and due_at both
     * null), and they are exactly the rows that never age out — the
     * escalation ladder, the day-16 suspension and the 90-day write-off all
     * filter on the same missing columns, so the line sits payable_unfunded
     * indefinitely and the grant repeats indefinitely with it.
     *
     * So the answer is a refusal, not an invented start date. The incentive
     * pays for provable promptness; a line whose clock never started proves
     * nothing, and the merchant's remedy is an admin fixing the row — which
     * is what it needed anyway.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function hasUnstartedClock(Collection $transactions): bool
    {
        foreach ($transactions as $transaction) {
            if ($transaction->clock_start_at === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The age of the OLDEST line, in whole business-timezone days (§13).
     * Every line is known to have a clock by the time this runs.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function oldestAgeDays(Collection $transactions, CarbonImmutable $at): int
    {
        $timezone = TransactionAge::timezone();
        $oldest = 0;

        foreach ($transactions as $transaction) {
            $oldest = max($oldest, TransactionAge::days($transaction->clock_start_at, $at, $timezone));
        }

        return $oldest;
    }

    /**
     * Transactions, however the caller had them to hand: already loaded (the
     * preview and the submit path both hold the rows they just locked), or as
     * bare ids.
     *
     * @param  Collection<int, Transaction>|list<int>|list<Transaction>  $lines
     * @return Collection<int, Transaction>
     */
    private function resolve(Collection|array $lines): Collection
    {
        if (is_array($lines)) {
            $lines = collect($lines);
        }

        if ($lines->first() instanceof Transaction) {
            return $lines;
        }

        return Transaction::query()
            ->whereIn('id', $lines->map(intval(...))->all())
            ->get(['id', 'merchant_id', 'fee_laari', 'fee_gst_laari', 'clock_start_at', 'state']);
    }

    /**
     * §4 ceiling in the merchant's favour: every fractional laari of discount
     * rounds UP. Integer arithmetic only — no float ever touches money.
     */
    public static function ceilingBp(int $laari, int $rateBp): int
    {
        if ($laari <= 0 || $rateBp <= 0) {
            return 0;
        }

        return intdiv($laari * $rateBp + 9999, 10000);
    }

    /**
     * Fee GST recomputed proportionally on the DISCOUNTED fee: the tax
     * follows the taxable amount, so a fee reduced by `feeDiscount` carries
     * GST reduced in exactly that proportion, rounded up (again in the
     * merchant's favour — a larger relief). Zero everywhere today; correct
     * the day GST is switched on.
     */
    public static function gstRelief(int $gstTotal, int $feeDiscount, int $feeTotal): int
    {
        if ($gstTotal <= 0 || $feeDiscount <= 0 || $feeTotal <= 0) {
            return 0;
        }

        return min($gstTotal, intdiv($gstTotal * $feeDiscount + $feeTotal - 1, $feeTotal));
    }

    /**
     * The two ledger legs of a settlement's STORED discount, re-derived from
     * the batch's own integers so the posting never depends on a platform
     * setting that has moved since submit: the fee-revenue leg first, the
     * GST-payable leg second (the same order cappedTo() consumes them).
     *
     * @return array{0: int, 1: int} [fee revenue leg, fee GST leg]
     */
    public static function reliefLegs(Settlement $settlement): array
    {
        $relief = (int) $settlement->discount_laari;

        if ($relief <= 0) {
            return [0, 0];
        }

        $rateBp = (int) ($settlement->discount_rate_bp ?? 0);

        // Defensive: a relief with no stamped rate can only be a fee
        // discount — GST relief exists solely as a proportion of one.
        $feeUncapped = $rateBp > 0
            ? self::ceilingBp((int) $settlement->fee_total_laari, $rateBp)
            : $relief;

        $fee = min($feeUncapped, $relief);

        return [$fee, $relief - $fee];
    }
}
