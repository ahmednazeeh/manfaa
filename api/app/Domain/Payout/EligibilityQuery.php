<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Cashback\TransactionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Selects what a payout batch may contain: confirmed transactions not yet
 * linked to a payout item whose confirmation happened at or before the
 * cutoff, grouped per customer, keeping only customers at or above the
 * MVR 100 minimum (§13). Below-minimum sums are simply not selected — they
 * stay unlinked and carry forward to the first month they clear the bar.
 *
 * Confirmation instant: transactions.confirmed_at where settlement stamped
 * it, else derived from the append-only event log (latest to_state =
 * 'confirmed' row). Both are supported so eligibility works whether or not
 * the allocation path that confirmed a given row recorded the column.
 */
final class EligibilityQuery
{
    /** MVR 100 (§13). */
    public const int MINIMUM_PAYOUT_LAARI = 10000;

    /**
     * @return list<EligibleCustomer>
     */
    public function eligibleAt(CarbonImmutable $cutoff): array
    {
        $rows = DB::table('transactions')
            ->select('id', 'customer_id', 'cashback_laari')
            ->where('state', TransactionState::Confirmed->value)
            ->whereNull('payout_item_id')
            ->whereNotNull('customer_id')
            ->where('cashback_laari', '>', 0)
            ->whereRaw(
                'coalesce(transactions.confirmed_at, (select max(created_at) from transaction_events where transaction_events.transaction_id = transactions.id and transaction_events.to_state = ?)) <= ?',
                [TransactionState::Confirmed->value, $cutoff->utc()],
            )
            ->orderBy('id')
            ->get();

        $amounts = [];
        $transactionIds = [];

        foreach ($rows as $row) {
            $customerId = (int) $row->customer_id;
            // Batch totals are sums of stored line integers — never recomputed.
            $amounts[$customerId] = ($amounts[$customerId] ?? 0) + (int) $row->cashback_laari;
            $transactionIds[$customerId][] = (int) $row->id;
        }

        $eligible = [];

        foreach ($amounts as $customerId => $amountLaari) {
            if ($amountLaari >= self::MINIMUM_PAYOUT_LAARI) {
                $eligible[] = new EligibleCustomer($customerId, $amountLaari, $transactionIds[$customerId]);
            }
        }

        return $eligible;
    }
}
