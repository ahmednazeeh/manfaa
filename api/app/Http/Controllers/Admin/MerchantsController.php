<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\TransactionState;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;

class MerchantsController extends Controller
{
    /**
     * Merchant standing for the admin panel: status plus outstanding and
     * overdue unfunded-payable totals, summed from the STORED line integers.
     */
    public function index(): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');

        $rows = Merchant::query()
            ->leftJoin('transactions', function (JoinClause $join) {
                $join->on('transactions.merchant_id', '=', 'merchants.id')
                    ->where('transactions.state', '=', TransactionState::PayableUnfunded->value);
            })
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
                MIN(transactions.due_at) AS oldest_due_at
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
            ])->values(),
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
