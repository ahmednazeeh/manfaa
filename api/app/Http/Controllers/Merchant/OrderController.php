<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Marketplace\AmendmentException;
use App\Domain\Marketplace\FulfilmentException;
use App\Domain\Marketplace\FulfilmentService;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Suborder;
use App\Models\SuborderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shop's order queue (`Orders.png`, `Order Details.png`).
 *
 * Quick actions only, by design and by the banner in the mockup: the app
 * accepts, prepares and hands over; the catalogue and the reports live on
 * the desktop.
 */
final class OrderController extends Controller
{
    public function __construct(private readonly FulfilmentService $fulfilment) {}

    /** New / Preparing / Ready / Completed, as the tabs read. */
    public function index(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        $states = match ((string) $request->query('tab', 'new')) {
            'preparing' => ['accepted', 'preparing'],
            'ready' => ['ready', 'out_for_delivery'],
            'completed' => ['delivered', 'rejected', 'cancelled'],
            default => ['new'],
        };

        $orders = Suborder::query()
            ->with(['order.customer:id,name,phone', 'items', 'branch:id,name,address'])
            ->where('merchant_id', $merchant->getKey())
            ->whereIn('state', $states)
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return new JsonResponse([
            'data' => $orders->map($this->present(...))->values(),
            'meta' => [
                // The two tiles at the top of Orders.png.
                'new_count' => Suborder::query()
                    ->where('merchant_id', $merchant->getKey())
                    ->where('state', 'new')->count(),
                'awaiting_action_count' => Suborder::query()
                    ->where('merchant_id', $merchant->getKey())
                    ->where('state', 'new')
                    ->whereHas('order', fn ($query) => $query->where('payment_state', 'verified'))
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, int $suborder): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($this->find($request, $suborder))]);
    }

    public function accept(Request $request, int $suborder): JsonResponse
    {
        return $this->act(fn (Suborder $row): Suborder => $this->fulfilment->accept($row), $request, $suborder);
    }

    public function reject(Request $request, int $suborder): JsonResponse
    {
        $validated = $request->validate([
            // Required, always. A refusal the customer cannot understand is
            // worse than a slow one.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->act(
            fn (Suborder $row): Suborder => $this->fulfilment->reject($row, $validated['reason']),
            $request,
            $suborder,
        );
    }

    public function advance(Request $request, int $suborder): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', Rule::in(['preparing', 'ready', 'out_for_delivery', 'delivered'])],
        ]);

        return $this->act(
            fn (Suborder $row): Suborder => $this->fulfilment->advance($row, $validated['state']),
            $request,
            $suborder,
        );
    }

    /** Reduce what will be supplied, and owe the difference. */
    public function amend(Request $request, int $suborder): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.suborder_item_id' => ['required', 'integer'],
            'lines.*.fulfilled_qty' => ['required', 'integer', 'min:0'],
            'reason' => ['required', Rule::in(['out_of_stock', 'damaged', 'customer_request', 'other'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $this->find($request, $suborder);

        $fulfilled = [];
        foreach ($validated['lines'] as $line) {
            $fulfilled[(int) $line['suborder_item_id']] = (int) $line['fulfilled_qty'];
        }

        /** @var MerchantUser $actor */
        $actor = $request->user('merchant');

        try {
            $updated = $this->fulfilment->amend(
                $row,
                $actor,
                $fulfilled,
                $validated['reason'],
                $validated['note'] ?? null,
            );
        } catch (AmendmentException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        return new JsonResponse(['data' => $this->present($updated->load('items', 'order.customer'))]);
    }

    private function act(callable $action, Request $request, int $suborder): JsonResponse
    {
        $row = $this->find($request, $suborder);

        try {
            $updated = $action($row);
        } catch (FulfilmentException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => 'invalid_transition',
            ], 409);
        }

        return new JsonResponse(['data' => $this->present($updated->load('items', 'order.customer'))]);
    }

    private function find(Request $request, int $suborder): Suborder
    {
        return Suborder::query()
            ->with(['order.customer:id,name,phone', 'items', 'branch:id,name,address'])
            ->where('merchant_id', $this->merchant($request)->getKey())
            ->whereKey($suborder)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Suborder $suborder): array
    {
        $order = $suborder->order;

        return [
            'id' => $suborder->id,
            'reference' => $suborder->reference,
            'state' => $suborder->state,
            'fulfilment' => $suborder->fulfilment,
            'pickup_code' => $suborder->pickup_code,
            'reject_reason' => $suborder->reject_reason,
            'placed_at' => $order?->placed_at?->toIso8601String(),
            // What the shop needs to know before touching the order: has the
            // customer actually paid us?
            'payment_state' => $order?->payment_state,
            'customer' => [
                'name' => $order?->customer?->name,
                'phone' => $order?->customer?->phone,
            ],
            'address' => $order?->address_snapshot,
            'branch_name' => $suborder->branch?->name,
            'items_laari' => $suborder->items_laari,
            'delivery_laari' => $suborder->delivery_laari,
            'subtotal_laari' => $suborder->subtotal_laari,
            'cashback_laari' => $suborder->cashback_laari,
            'order_fee_laari' => $suborder->order_fee_laari,
            // The tax on that fee, so the deduction on screen adds up:
            // payable = subtotal − cashback − fee − GST. Zero (and absent
            // from every surface that hides zero tax) until GST is on.
            'order_fee_gst_laari' => $suborder->order_fee_gst_laari,
            'payable_to_merchant_laari' => $suborder->payable_to_merchant_laari,
            'items' => $suborder->items->map(fn (SuborderItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'qty' => $item->qty,
                'fulfilled_qty' => $item->fulfilled_qty,
                'amended' => $item->wasAmended(),
                'refund_laari' => $item->refundLaari(),
                'unit_price_laari' => $item->unit_price_laari,
                'line_total_laari' => $item->line_total_laari,
            ])->values(),
        ];
    }

    private function merchant(Request $request): Merchant
    {
        $user = $request->user('merchant');
        abort_unless($user instanceof MerchantUser, 403);

        $merchant = $user->merchant;
        abort_if($merchant === null, 403);

        return $merchant;
    }
}
