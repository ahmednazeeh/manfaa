<?php

declare(strict_types=1);

namespace App\Domain\PosWaiver;

use App\Domain\Cashback\TransactionState;
use App\Domain\Settlement\OutstandingSummary;
use App\Models\Merchant;
use App\Models\PosWaiverEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The POS-fee waiver rule, in one place (owner, 2026-08-23):
 *
 *   qualified = standing rate ≥ 1.00% for the ENTIRE month
 *             ∧ no overdue outstanding at evaluation time
 *             ∧ merchant active
 *             ∧ (earning volume ≥ MVR 200,000 ∨ cashback ≥ MVR 5,000)
 *
 * "Earning" is the operative word: only amounts that actually produced
 * cashback count. An excluded-category line, a below-minimum sale, a
 * suspended-store sale all contribute nothing — they earn Manfaa nothing,
 * so they buy the merchant nothing (owner). Reversed and written-off sales
 * are out entirely. Marketplace orders are Manfaa sales and count.
 *
 * Months are CALENDAR months in the business timezone, evaluated after
 * they close (the scheduler runs on the 3rd) so late refunds land inside
 * the month they belong to. There is no clawback: a reversal after
 * evaluation simply reduces the month it happens in.
 */
final readonly class PosWaiverEvaluator
{
    public const int MIN_RATE_BP = 100;

    public const int VOLUME_THRESHOLD_LAARI = 20_000_000; // MVR 200,000

    public const int CASHBACK_THRESHOLD_LAARI = 500_000;  // MVR 5,000

    public function __construct(private OutstandingSummary $outstanding) {}

    /**
     * Evaluate one CLOSED month and persist the verdict. Idempotent:
     * re-running replaces the row (an admin re-run after a data fix must
     * win over a stale verdict).
     */
    public function evaluate(Merchant $merchant, CarbonImmutable $month): PosWaiverEvaluation
    {
        $figures = $this->figures($merchant, $month);
        $overdue = $this->overdueLaari($merchant);

        $qualified = $merchant->status === 'active'
            && $overdue === 0
            && $figures['min_rate_bp'] >= self::MIN_RATE_BP
            && ($figures['volume_laari'] >= self::VOLUME_THRESHOLD_LAARI
                || $figures['cashback_laari'] >= self::CASHBACK_THRESHOLD_LAARI);

        return PosWaiverEvaluation::query()->updateOrCreate(
            ['merchant_id' => $merchant->getKey(), 'month' => $month->toDateString()],
            [
                ...$figures,
                'overdue_laari' => $overdue,
                'merchant_status' => (string) $merchant->status,
                'qualified' => $qualified,
                'evaluated_at' => CarbonImmutable::now('UTC'),
            ],
        );
    }

    /**
     * The running numbers for a month still open — the merchant-facing
     * progress card. Same sums, no verdict row.
     *
     * @return array{volume_laari: int, cashback_laari: int, min_rate_bp: int, overdue_laari: int}
     */
    public function progress(Merchant $merchant, CarbonImmutable $month): array
    {
        return [
            ...$this->figures($merchant, $month),
            'overdue_laari' => $this->overdueLaari($merchant),
        ];
    }

    /**
     * @return array{volume_laari: int, cashback_laari: int, min_rate_bp: int}
     */
    private function figures(Merchant $merchant, CarbonImmutable $month): array
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $start = $month->setTimezone($timezone)->startOfMonth();
        $end = $start->addMonth();

        // Earning volume and cashback in ONE pass: per transaction, the
        // earning portion is the sum of its earning lines when it has
        // lines, and its whole eligible amount otherwise — but only while
        // the sale earned at all. Excluded-category lines price at
        // cashback 0 and drop out of the lined sum by the WHERE.
        $row = DB::table('transactions')
            ->selectRaw(
                'coalesce(sum(case when lined.total is not null then lined.total else transactions.eligible_laari end), 0) as volume_laari',
            )
            ->selectRaw('coalesce(sum(transactions.cashback_laari), 0) as cashback_laari')
            ->leftJoinSub(
                DB::table('transaction_lines')
                    ->selectRaw('transaction_id, sum(amount_laari) as total')
                    ->where('cashback_laari', '>', 0)
                    ->groupBy('transaction_id'),
                'lined',
                'lined.transaction_id',
                '=',
                'transactions.id',
            )
            ->where('transactions.merchant_id', $merchant->getKey())
            ->where('transactions.occurred_at', '>=', $start->utc())
            ->where('transactions.occurred_at', '<', $end->utc())
            ->where('transactions.cashback_laari', '>', 0)
            ->whereNotIn('transactions.state', [
                TransactionState::Reversed->value,
                TransactionState::WrittenOff->value,
            ])
            ->first();

        return [
            'volume_laari' => (int) $row->volume_laari,
            'cashback_laari' => (int) $row->cashback_laari,
            'min_rate_bp' => $this->minRateBp($merchant, $start, $end),
        ];
    }

    /**
     * The lowest standing rate in force at any point of the window — "offer
     * minimum 1%" means for the WHOLE month (owner), so one dip
     * disqualifies. Rate rows are contiguous by construction; a merchant
     * whose first rate starts mid-window is judged from that start (their
     * first month on IsleBooks is free anyway). No rate at all is 0.
     */
    private function minRateBp(Merchant $merchant, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $min = DB::table('merchant_rates')
            ->where('merchant_id', $merchant->getKey())
            ->where('effective_from', '<', $end->utc())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $start->utc()))
            ->min('rate_bp');

        return (int) ($min ?? 0);
    }

    private function overdueLaari(Merchant $merchant): int
    {
        $summary = $this->outstanding->forMerchant($merchant);

        return (int) ($summary['buckets']['overdue']['payable_laari'] ?? 0);
    }
}
