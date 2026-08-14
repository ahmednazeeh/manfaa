<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Settlement;
use App\Models\SettlementLine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared line queries. Allocation order is the §7 rule made concrete:
 * oldest first by the underlying transaction's due_at, ties broken by
 * transaction id, so two matchers can never disagree on which line a
 * laari of payment covers.
 */
final class SettlementLines
{
    /**
     * @return Collection<int, SettlementLine>
     */
    public static function inAllocationOrder(Settlement $settlement): Collection
    {
        return SettlementLine::query()
            ->where('settlement_lines.settlement_id', $settlement->id)
            ->join('transactions', 'transactions.id', '=', 'settlement_lines.transaction_id')
            ->orderBy('transactions.due_at')
            ->orderBy('transactions.id')
            ->select('settlement_lines.*')
            ->with('transaction')
            ->get();
    }

    /**
     * A line's due is the sum of its own stored snapshot integers — never
     * recomputed from the transaction or a rate.
     */
    public static function due(SettlementLine $line): int
    {
        return $line->cashback_laari + $line->fee_laari + $line->fee_gst_laari;
    }
}
