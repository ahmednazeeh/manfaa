<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Cashback\TransactionState;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer balance read model (§10 apps/web). Confirmed is the HEADLINE
 * figure and is never summed with pending — pending is conditional money and
 * is reported as its own separate integer. All figures are sums of stored
 * line integers; nothing is recomputed from rates.
 */
final class BalanceQuery
{
    /**
     * @return array{confirmed_laari: int, pending_laari: int, paid_this_month_laari: int}
     */
    public function balances(Customer $customer, CarbonImmutable $now, string $businessTimezone): array
    {
        $sums = DB::table('transactions')
            ->selectRaw(
                'coalesce(sum(cashback_laari) filter (where state = ?), 0) as confirmed_laari',
                [TransactionState::Confirmed->value],
            )
            ->selectRaw(
                'coalesce(sum(cashback_laari) filter (where state in (?, ?, ?, ?)), 0) as pending_laari',
                CustomerFacingStatus::PENDING_STATES,
            )
            ->where('customer_id', $customer->id)
            ->first();

        // "This month" is the business-timezone calendar month, evaluated
        // from the append-only event log: the instant a row became paid is
        // its to_state = 'paid' event, not a mutable column.
        $monthStart = $now->setTimezone($businessTimezone)->startOfMonth()->utc();
        $nextMonthStart = $now->setTimezone($businessTimezone)->startOfMonth()->addMonthsNoOverflow(1)->utc();

        $paidThisMonth = (int) DB::table('transaction_events')
            ->join('transactions', 'transactions.id', '=', 'transaction_events.transaction_id')
            ->where('transactions.customer_id', $customer->id)
            ->where('transactions.state', TransactionState::Paid->value)
            ->where('transaction_events.to_state', TransactionState::Paid->value)
            ->where('transaction_events.created_at', '>=', $monthStart)
            ->where('transaction_events.created_at', '<', $nextMonthStart)
            ->sum('transactions.cashback_laari');

        return [
            'confirmed_laari' => (int) $sums->confirmed_laari,
            'pending_laari' => (int) $sums->pending_laari,
            'paid_this_month_laari' => $paidThisMonth,
        ];
    }
}
