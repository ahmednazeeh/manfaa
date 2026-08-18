<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTransactionResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One timeline (`Customer App Order Tracking.png`).
 *
 * The screen says it out loud — *"Track your marketplace and cashback orders
 * in one place"* — so this merges two genuinely different things: a
 * marketplace ORDER, which has shops and statuses, and a cashback
 * TRANSACTION, which has a store and a reward. They share only a customer
 * and a moment in time, and that is enough to sort by.
 *
 * The merge is done in SQL rather than by fetching both lists and sorting in
 * PHP: paging over an in-memory merge silently drops whichever source is
 * denser, and a customer who shops a lot at the till would stop seeing their
 * orders.
 */
final class ActivityController extends Controller
{
    /** What each tab means, on both sides of the timeline. */
    private const array FILTERS = [
        'active' => [
            'orders' => ['placed', 'under_review', 'partly_confirmed', 'confirmed'],
            'transactions' => ['tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold'],
        ],
        'completed' => [
            'orders' => ['completed'],
            'transactions' => ['confirmed', 'paid'],
        ],
        'cancelled' => [
            'orders' => ['cancelled'],
            'transactions' => ['reversed', 'written_off'],
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'in:active,completed,cancelled,all'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $customer = $this->customer($request);
        $tab = $validated['tab'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);

        $filter = self::FILTERS[$tab] ?? null;

        $orders = DB::table('orders')
            ->selectRaw("'order' as kind, id, placed_at as at")
            ->where('customer_id', $customer->getKey())
            ->when($filter !== null, fn ($query) => $query->whereIn('state', $filter['orders']));

        $keys = DB::table('transactions')
            ->selectRaw("'transaction' as kind, id, occurred_at as at")
            ->where('customer_id', $customer->getKey())
            ->when($filter !== null, fn ($query) => $query->whereIn('state', $filter['transactions']))
            ->union($orders)
            ->orderByDesc('at')
            ->orderByDesc('id');

        $total = DB::query()->fromSub($keys, 'timeline')->count();

        $rows = DB::query()->fromSub($keys, 'timeline')
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        // Two hydrations, one per kind — a JOIN across two unrelated shapes
        // would be worse than two indexed lookups.
        $orderIds = $rows->where('kind', 'order')->pluck('id');
        $txIds = $rows->where('kind', 'transaction')->pluck('id');

        $loadedOrders = Order::query()
            ->with('suborders.merchant:id,name', 'suborders.branch:id,name')
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $loadedTx = Transaction::query()
            ->with('merchant:id,name,slug')
            ->whereIn('id', $txIds)
            ->get()
            ->keyBy('id');

        $entries = $rows->map(function ($row) use ($loadedOrders, $loadedTx, $request): ?array {
            if ($row->kind === 'order') {
                $order = $loadedOrders->get($row->id);

                return $order === null ? null : [
                    'kind' => 'order',
                    'at' => $order->placed_at?->toIso8601String(),
                    'order' => $this->presentOrder($order),
                ];
            }

            $transaction = $loadedTx->get($row->id);

            return $transaction === null ? null : [
                'kind' => 'transaction',
                'at' => $transaction->occurred_at?->toIso8601String(),
                // The SAME resource the transactions endpoint serves, so a
                // cashback card reads identically wherever it appears.
                'transaction' => (new CustomerTransactionResource($transaction))->toArray($request),
            ];
        })->filter()->values();

        return new JsonResponse([
            'data' => $entries,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * The order card: the whole thing at a glance, then a line per shop —
     * because in a multi-vendor order the shops are the status, and one
     * summary word would hide that two are confirmed and one is not.
     *
     * @return array<string, mixed>
     */
    private function presentOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'state' => $order->state,
            'payment_state' => $order->payment_state,
            'total_payable_laari' => $order->total_payable_laari,
            'cashback_total_laari' => $order->cashback_total_laari,
            'store_count' => $order->suborders->count(),
            'stores' => $order->suborders->map(fn ($sub): array => [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'store_name' => $sub->merchant?->name,
                'branch_name' => $sub->branch?->name,
                'state' => $sub->state,
                'fulfilment' => $sub->fulfilment,
                'pickup_code' => $sub->pickup_code,
                'cashback_laari' => $sub->cashback_laari,
            ])->values(),
        ];
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
