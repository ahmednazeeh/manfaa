<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\TransactionState;
use App\Domain\Standing\StaleHolds;
use App\Domain\Standing\SuspensionService;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantsController extends Controller
{
    /**
     * Merchant standing for the admin panel: status plus outstanding and
     * overdue unfunded-payable totals, summed from the STORED line integers —
     * and the stale-hold backlog (on_hold unchanged for 30+ days), so held
     * rows under fraud/dispute review stay visible instead of ageing quietly.
     */
    public function index(StaleHolds $staleHolds): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');

        $rows = Merchant::query()
            ->leftJoin('transactions', function (JoinClause $join) {
                $join->on('transactions.merchant_id', '=', 'merchants.id')
                    ->where('transactions.state', '=', TransactionState::PayableUnfunded->value);
            })
            ->leftJoinSub($staleHolds->perMerchant($now), 'stale_holds', 'stale_holds.merchant_id', '=', 'merchants.id')
            ->groupBy('merchants.id')
            ->orderBy('merchants.name')
            ->selectRaw(<<<'SQL'
                merchants.id,
                merchants.name,
                merchants.slug,
                merchants.status,
                COUNT(transactions.id) AS open_count,
                COALESCE(SUM(transactions.cashback_laari + transactions.fee_laari + transactions.fee_gst_laari), 0) AS outstanding_laari,
                COALESCE(SUM(transactions.cashback_laari + transactions.fee_laari + transactions.fee_gst_laari)
                    FILTER (WHERE transactions.due_at < ?), 0) AS overdue_laari,
                MIN(transactions.due_at) AS oldest_due_at,
                COALESCE(MAX(stale_holds.stale_hold_count), 0) AS stale_hold_count,
                COALESCE(MAX(stale_holds.stale_hold_laari), 0) AS stale_hold_laari
                SQL, [$now])
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'status' => $row->status,
                'open_payable_count' => (int) $row->open_count,
                'outstanding_laari' => (int) $row->outstanding_laari,
                'overdue_laari' => (int) $row->overdue_laari,
                'oldest_due_at' => $row->oldest_due_at !== null
                    ? CarbonImmutable::parse($row->oldest_due_at)->utc()->toIso8601String()
                    : null,
                'stale_hold_count' => (int) $row->stale_hold_count,
                'stale_hold_laari' => (int) $row->stale_hold_laari,
            ])->values(),
        ]);
    }

    /**
     * The manual path back for a suspended merchant — including one whose
     * overdue debt was cleared by the 90-day write-off, which the automatic
     * manfaa:reinstate sweep deliberately never touches. Whether such
     * merchants should ever come back automatically is an open PLAN.md (§7)
     * product decision; until it is made, this note-carrying admin action is
     * the safe default and the only way back.
     */
    public function reinstate(Request $request, Merchant $merchant, SuspensionService $suspensions): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        if ($merchant->status !== 'suspended') {
            abort(409, sprintf('Merchant #%d is %s — only a suspended merchant can be reinstated.', $merchant->id, $merchant->status));
        }

        $suspensions->reinstateManually($merchant, $validated['note'], (int) $request->user()->getKey());

        return response()->json([
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->refresh()->status,
            ],
        ]);
    }

    /**
     * The append-only notice trail for one merchant — the evidence that every
     * §7 clock step was recorded.
     */
    public function notices(Merchant $merchant): JsonResponse
    {
        $notices = MerchantNotice::query()
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $notices->map(fn (MerchantNotice $notice) => [
                'id' => $notice->id,
                'type' => $notice->type,
                'channel' => $notice->channel,
                'payload' => $notice->payload,
                'sent_at' => $notice->sent_at->toIso8601String(),
            ])->values(),
        ]);
    }
}
