<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\TransactionState;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Settlement\SettlementState;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * Every status write here also drops the public discovery read model
 * (DiscoveryService::forgetMerchant). The storefront lists ACTIVE merchants
 * only, so a status flip changes what it renders as surely as a rate change
 * does: without the invalidation a just-suspended store keeps sitting on the
 * shelves — and inside the rail's merchant counts — for up to 60 seconds
 * while the uncached directory already answers that it is gone, and its
 * store page keeps quoting a percentage the till now refuses.
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
    public function __construct(
        private NoticeRecorder $notices,
        private WebhookDispatcher $webhooks,
    ) {}

    /**
     * @return int the number of merchants suspended
     */
    public function suspendOverdue(): int
    {
        $now = CarbonImmutable::now('UTC');

        $merchants = Merchant::query()
            ->where('status', 'active')
            ->whereHas('transactions', fn (Builder $query) => $this->unpaidOverdue($query, $now))
            ->orderBy('id')
            ->get();

        foreach ($merchants as $merchant) {
            $merchant->update(['status' => 'suspended']);

            // The storefront advertises ACTIVE stores only — drop the cached
            // shelves, rail counts and store page now, not 60 seconds from now.
            DiscoveryService::forgetMerchant($merchant);

            $this->notices->record($merchant->id, 'suspended', [
                ...$this->overdueSummary($merchant->id, $now),
                'suspended_at' => $now->toIso8601String(),
            ]);

            // §9.3: tills stop advertising cashback on receipt. This is the
            // automatic day-16 suspension — the only suspension this
            // service imposes — hence reason overdue_settlement.
            $this->webhooks->dispatch(WebhookEvents::MERCHANT_SUSPENDED, [
                'merchant_id' => $merchant->id,
                'reason' => 'overdue_settlement',
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

            // Mirror of suspension: the store is listable again this second,
            // not once the 60-second read model happens to expire.
            DiscoveryService::forgetMerchant($merchant);

            $this->notices->record($merchant->id, 'reinstated', [
                'reinstated_at' => $now->toIso8601String(),
            ]);

            // §9.3: tills may resume advertising the offer.
            $this->webhooks->dispatch(WebhookEvents::MERCHANT_REINSTATED, [
                'merchant_id' => $merchant->id,
                'reinstated_at' => $now->toIso8601String(),
            ]);
        }

        return $merchants->count();
    }

    /**
     * The manual reinstatement an admin performs by hand — the only path back
     * for a merchant whose overdue debt was cleared by the 90-day write-off,
     * which reinstate() above deliberately skips forever.
     *
     * OPEN POLICY (PLAN.md §7): whether a written-off merchant should ever be
     * reinstated automatically — and on what terms (repayment of the
     * defaulted debt, a probation window) — is an undecided product question.
     * Until it is decided, the safe default implemented here is manual-only:
     * a deliberate admin action carrying a required note, recorded in the
     * append-only notice trail as {manual: true, note, admin_id}.
     *
     * The 'reinstated' notice this writes also postdates any 'suspended'
     * notice, so the automatic sweep's own-suspension guard stays coherent.
     */
    public function reinstateManually(Merchant $merchant, string $note, int $adminId): void
    {
        $now = CarbonImmutable::now('UTC');

        $merchant->update(['status' => 'active']);

        DiscoveryService::forgetMerchant($merchant);

        $this->notices->record($merchant->id, 'reinstated', [
            'manual' => true,
            'note' => $note,
            'admin_id' => $adminId,
            'reinstated_at' => $now->toIso8601String(),
        ]);

        // §9.3: the manual path emits the same merchant.reinstated event as
        // the automatic sweep — the vendor cares that cashback resumed, not
        // which door it came back through.
        $this->webhooks->dispatch(WebhookEvents::MERCHANT_REINSTATED, [
            'merchant_id' => $merchant->id,
            'reinstated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function overdue(Builder|QueryBuilder $query, CarbonImmutable $now): Builder|QueryBuilder
    {
        return $query
            ->where('state', TransactionState::PayableUnfunded->value)
            ->where('due_at', '<', $now);
    }

    /**
     * What suspension actually acts on: overdue transactions the merchant has
     * NOT already transferred for. A line frozen on a batch carrying a
     * PENDING receipt is money the merchant has paid and evidenced; it still
     * reads payable_unfunded only because nothing leaves that state until an
     * admin matches the payment (LineAllocator runs at match time). Counting
     * it would make the platform's own review latency the credit control —
     * §7's day-16 rule is aimed at a merchant who has not paid.
     *
     * The shield lasts exactly as long as the review does, and no longer:
     * Reject releases the lines with no payment left pending, and Match
     * leaves any uncovered lines on a partially_settled batch with nothing
     * pending either — both put the merchant straight back in scope.
     *
     * Reinstatement deliberately keeps the WIDER overdue() definition: an
     * unreviewed slip must never unlock a door the merchant's own lateness
     * already shut.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function unpaidOverdue(Builder|QueryBuilder $query, CarbonImmutable $now): Builder|QueryBuilder
    {
        return $this->overdue($query, $now)
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('settlement_lines')
                ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
                ->join('settlement_payments', 'settlement_payments.settlement_id', '=', 'settlements.id')
                ->whereColumn('settlement_lines.transaction_id', 'transactions.id')
                ->where('settlements.state', '!=', SettlementState::Cancelled->value)
                ->where('settlement_payments.state', 'pending'));
    }

    /**
     * The notice payload describes what triggered the suspension, so it
     * counts the same unpaid-overdue set the decision was made on.
     *
     * @return array<string, mixed>
     */
    private function overdueSummary(int $merchantId, CarbonImmutable $now): array
    {
        $summary = $this->unpaidOverdue(DB::table('transactions')->where('merchant_id', $merchantId), $now)
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
