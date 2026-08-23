<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\TransactionState;
use App\Domain\Money\Laari;
use App\Domain\Money\MerchantMoneyCache;
use App\Models\Adjustment;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * The merchant dashboard's "outstanding by age bucket" (§3): every
 * payable_unfunded transaction bucketed by whole days since it became
 * payable, evaluated in the business timezone against the 15-day clock —
 * 0–5 / 6–10 / 11–15, with anything past its due_at counted overdue
 * regardless of bucket. All totals are SUMS of stored integers.
 */
final class OutstandingSummary
{
    public function __construct(private readonly MerchantMoneyCache $cache) {}

    private const array BUCKETS = ['0_5', '6_10', '11_15', 'overdue'];

    /**
     * @return array{
     *     as_of: string,
     *     total: array<string, int|string>,
     *     buckets: array<string, array<string, int|string>>,
     *     pending_adjustments: array<string, int|string>,
     * }
     */
    public function forMerchant(Merchant $merchant): array
    {
        // Version-keyed (MerchantMoneyCache): any money event orphans this
        // entry, so what is served is event-fresh; the TTL only bounds the
        // clock-driven drift (a row ageing across a bucket boundary).
        return $this->cache->remember(
            (int) $merchant->getKey(),
            'outstanding',
            fn (): array => $this->compute($merchant),
        );
    }

    /** @return array<string, mixed> */
    private function compute(Merchant $merchant): array
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();

        $transactions = Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->where('state', TransactionState::PayableUnfunded->value)
            ->get(['id', 'cashback_laari', 'fee_laari', 'fee_gst_laari', 'clock_start_at', 'due_at', 'state']);

        $empty = ['count' => 0, 'cashback_laari' => 0, 'fee_laari' => 0, 'fee_gst_laari' => 0, 'payable_laari' => 0];
        $buckets = array_fill_keys(self::BUCKETS, $empty);
        $total = $empty;

        foreach ($transactions as $transaction) {
            $bucket = $this->bucketFor($transaction, $now, $today, $timezone);

            foreach (['cashback_laari', 'fee_laari', 'fee_gst_laari'] as $column) {
                $buckets[$bucket][$column] += $transaction->{$column};
                $total[$column] += $transaction->{$column};
            }

            $payable = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;
            $buckets[$bucket]['payable_laari'] += $payable;
            $buckets[$bucket]['count']++;
            $total['payable_laari'] += $payable;
            $total['count']++;
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['payable_mvr'] = Laari::of($bucket['payable_laari'])->formatMvr();
        }

        $total['payable_mvr'] = Laari::of($total['payable_laari'])->formatMvr();
        $total['cashback_mvr'] = Laari::of($total['cashback_laari'])->formatMvr();
        $total['fee_mvr'] = Laari::of($total['fee_laari'])->formatMvr();

        return [
            'as_of' => $now->toIso8601String(),
            'total' => $total,
            'buckets' => $buckets,
            'pending_adjustments' => $this->pendingAdjustments($merchant),
        ];
    }

    /**
     * §7 reversal credits not yet netted into a batch: the (negative) sum
     * of the merchant's pending adjustments, shown so the dashboard's
     * outstanding total and the next batch's amount due reconcile.
     *
     * @return array{count: int, credit_laari: int, credit_mvr: string}
     */
    private function pendingAdjustments(Merchant $merchant): array
    {
        $pending = Adjustment::query()
            ->join('transactions', 'transactions.id', '=', 'adjustments.transaction_id')
            ->where('transactions.merchant_id', $merchant->id)
            ->where('adjustments.state', 'pending')
            ->selectRaw('COUNT(*) AS pending_count, COALESCE(SUM(adjustments.amount_laari), 0) AS credit_total')
            ->first();

        $creditLaari = (int) $pending->credit_total;

        return [
            'count' => (int) $pending->pending_count,
            'credit_laari' => $creditLaari,
            'credit_mvr' => Laari::of($creditLaari)->formatMvr(),
        ];
    }

    private function bucketFor(Transaction $transaction, CarbonImmutable $now, CarbonImmutable $today, string $timezone): string
    {
        if ($transaction->due_at !== null && $transaction->due_at->isBefore($now)) {
            return 'overdue';
        }

        $clockStart = $transaction->clock_start_at ?? $transaction->due_at?->subDays(15);

        if ($clockStart === null) {
            return '0_5';
        }

        // The one day-count definition, shared with the settlement picker's
        // presets and the PLAN §1 discount window (TransactionAge), so the
        // dashboard and the settlement screen can never disagree about
        // whether a line is "10 days old". $today is already the business
        // timezone's start of day; TransactionAge truncates both sides.
        $days = TransactionAge::days($clockStart, $today, $timezone);

        return match (true) {
            $days <= 5 => '0_5',
            $days <= 10 => '6_10',
            default => '11_15',
        };
    }
}
