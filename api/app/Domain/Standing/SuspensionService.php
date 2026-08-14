<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\TransactionState;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Day 16 of the §7 clock and its reversal.
 *
 * Suspension is automatic — it is the only credit control, and a manual
 * suspension would make the exposure bound fictional. A merchant is overdue
 * when any payable_unfunded transaction sits past its due_at. Merchants have
 * no event table, so the status column is written directly; the merchant
 * notice row is the recorded outcome. Suspension stops cashback CREATION —
 * ManualCreditService already refuses non-active merchants.
 *
 * Reinstatement runs frequently (every 30 minutes) so settlement allocation
 * needs no coupling to it: once the overdue debt clears, the next run flips
 * the merchant back to active on its own.
 *
 * Reinstatement is deliberately NARROWER than suspension. It only reverses
 * suspensions this service itself imposed — evidenced by a 'suspended'
 * notice newer than the last 'reinstated' one — so a status an admin set by
 * hand is never quietly undone. And a merchant with written-off debt never
 * auto-reinstates: the 90-day write-off clears the overdue query by moving
 * the rows to written_off, but the debt was defaulted on, not paid, and
 * suspension is the only credit control (§7). Bringing a defaulted merchant
 * back is a human decision.
 */
final readonly class SuspensionService
{
    public function __construct(private NoticeRecorder $notices) {}

    /**
     * @return int the number of merchants suspended
     */
    public function suspendOverdue(): int
    {
        $now = CarbonImmutable::now('UTC');

        $merchants = Merchant::query()
            ->where('status', 'active')
            ->whereHas('transactions', fn (Builder $query) => $this->overdue($query, $now))
            ->orderBy('id')
            ->get();

        foreach ($merchants as $merchant) {
            $merchant->update(['status' => 'suspended']);

            $this->notices->record($merchant->id, 'suspended', [
                ...$this->overdueSummary($merchant->id, $now),
                'suspended_at' => $now->toIso8601String(),
            ]);
        }

        return $merchants->count();
    }

    /**
     * @return int the number of merchants reinstated
     */
    public function reinstate(): int
    {
        $now = CarbonImmutable::now('UTC');

        $merchants = Merchant::query()
            ->where('status', 'suspended')
            ->whereDoesntHave('transactions', fn (Builder $query) => $this->overdue($query, $now))
            // Defaulted debt is cleared by the write-off, never by payment —
            // it must not unlock the door it caused to be shut.
            ->whereDoesntHave('transactions', fn (Builder $query) => $query
                ->where('state', TransactionState::WrittenOff->value))
            // Only reverse our own automatic suspension: the latest
            // 'suspended' notice must postdate the latest 'reinstated' one.
            // A manual status change leaves no such notice and stays put.
            ->whereRaw(<<<'SQL'
                COALESCE((
                    SELECT MAX(sent_at) FROM merchant_notices
                    WHERE merchant_id = merchants.id AND type = 'suspended'
                ), 'epoch'::timestamptz) > COALESCE((
                    SELECT MAX(sent_at) FROM merchant_notices
                    WHERE merchant_id = merchants.id AND type = 'reinstated'
                ), 'epoch'::timestamptz)
                SQL)
            ->orderBy('id')
            ->get();

        foreach ($merchants as $merchant) {
            $merchant->update(['status' => 'active']);

            $this->notices->record($merchant->id, 'reinstated', [
                'reinstated_at' => $now->toIso8601String(),
            ]);
        }

        return $merchants->count();
    }

    private function overdue(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where('state', TransactionState::PayableUnfunded->value)
            ->where('due_at', '<', $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueSummary(int $merchantId, CarbonImmutable $now): array
    {
        $summary = DB::table('transactions')
            ->where('merchant_id', $merchantId)
            ->where('state', TransactionState::PayableUnfunded->value)
            ->where('due_at', '<', $now)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS overdue_count,
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) AS overdue_laari,
                MIN(due_at) AS oldest_due_at
                SQL)
            ->first();

        return [
            'overdue_transactions' => (int) $summary->overdue_count,
            'overdue_laari' => (int) $summary->overdue_laari,
            'oldest_due_at' => CarbonImmutable::parse($summary->oldest_due_at)->utc()->toIso8601String(),
        ];
    }
}
