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
 * stay unlinked and carry forward to the first batch they clear the bar in.
 *
 * Confirmation instant: transactions.confirmed_at where settlement stamped
 * it, else derived from the append-only event log (latest to_state =
 * 'confirmed' row). Both are supported so eligibility works whether or not
 * the allocation path that confirmed a given row recorded the column.
 */
final class EligibilityQuery
{
    /** MVR 100 (§13) — the default when no explicit minimum is passed. */
    public const int MINIMUM_PAYOUT_LAARI = 10000;

    /**
     * The confirmation instant as described above, in one place because two
     * callers ask the question. The single placeholder binds the confirmed
     * state for the event-log fallback.
     */
    private const string CONFIRMED_AT = 'coalesce(transactions.confirmed_at, (select max(created_at) from transaction_events where transaction_events.transaction_id = transactions.id and transaction_events.to_state = ?))';

    /**
     * $minimumLaari lets PayoutBatchBuilder pass the admin-managed
     * min_payout_laari setting; null keeps the §13 default.
     *
     * @return list<EligibleCustomer>
     */
    public function eligibleAt(CarbonImmutable $cutoff, ?int $minimumLaari = null): array
    {
        $minimumLaari ??= self::MINIMUM_PAYOUT_LAARI;

        $rows = DB::table('transactions')
            ->select('id', 'customer_id', 'cashback_laari')
            ->where('state', TransactionState::Confirmed->value)
            ->whereNull('payout_item_id')
            ->whereNotNull('customer_id')
            ->where('cashback_laari', '>', 0)
            ->whereRaw(
                self::CONFIRMED_AT.' <= ?',
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
            if ($amountLaari >= $minimumLaari) {
                $eligible[] = new EligibleCustomer($customerId, $amountLaari, $transactionIds[$customerId]);
            }
        }

        return $eligible;
    }

    /**
     * The oldest confirmation the platform has on record, or null when
     * nothing has ever been confirmed. PayoutBatchBuilder opens the very
     * first batch's display period here, having no earlier batch to start
     * from; a never-confirmed transaction has no instant on either side of
     * the coalesce and drops out of the minimum on its own.
     */
    public function earliestConfirmationAt(): ?CarbonImmutable
    {
        $earliest = DB::table('transactions')
            ->selectRaw('min('.self::CONFIRMED_AT.') as confirmed_at', [TransactionState::Confirmed->value])
            ->value('confirmed_at');

        return $earliest === null ? null : CarbonImmutable::parse($earliest)->utc();
    }
}
