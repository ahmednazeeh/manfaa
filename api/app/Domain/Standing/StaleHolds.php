<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\TransactionState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stale holds: on_hold transactions whose state has not changed in over 30
 * days. A hold marks a fraud/dispute review — a human matter — so nothing
 * here (or anywhere automatic) transitions or writes them off. This class
 * only FINDS them, so the Reconciler can surface them in its issues and the
 * admin standing list can show a per-merchant count/total; releasing or
 * reversing a hold stays a deliberate act through the state machine.
 *
 * "Last state change" is the newest transaction_events row — the append-only
 * evidence every transition writes — falling back to the row's updated_at
 * for transactions with no events.
 */
final readonly class StaleHolds
{
    public const int STALE_AFTER_DAYS = 30;

    public function query(CarbonImmutable $now): Builder
    {
        return DB::table('transactions')
            ->where('state', TransactionState::OnHold->value)
            ->whereRaw(<<<'SQL'
                COALESCE((
                    SELECT MAX(created_at) FROM transaction_events
                    WHERE transaction_events.transaction_id = transactions.id
                ), transactions.updated_at) < ?
                SQL, [$now->subDays(self::STALE_AFTER_DAYS)]);
    }

    /**
     * One row per merchant with a stale hold: merchant_id, stale_hold_count,
     * stale_hold_laari (cashback + fee + gst, stored line integers).
     */
    public function perMerchant(CarbonImmutable $now): Builder
    {
        return $this->query($now)
            ->groupBy('merchant_id')
            ->selectRaw(<<<'SQL'
                merchant_id,
                COUNT(*) AS stale_hold_count,
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) AS stale_hold_laari
                SQL);
    }
}
