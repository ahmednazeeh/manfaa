<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Marketplace\CheckoutException;
use App\Domain\Marketplace\CheckoutService;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\Suborder;
use App\Models\SuborderItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Checkout and the customer's own orders
 * (`Payment Step.png`, `Order Received.png`, `Customer App Order
 * Tracking.png`).
 *
 * Payment is RECEIPT-FIRST, the same shape merchants already settle with:
 * we publish the exact amount and our account, the customer transfers and
 * uploads their proof, and the order is confirmed once a human has seen it.
 * No card rails, no pretending the money has arrived before it has.
 */
final class OrderController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /** Where to send the money. */
    public function paymentAccounts(): JsonResponse
    {
        return new JsonResponse([
            'data' => PlatformBankAccount::query()
                ->where('active', true)
                ->orderByDesc('is_primary')
                ->get(['id', 'bank_name', 'account_no', 'account_name', 'currency', 'is_primary']),
        ]);
    }

    public function place(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'payment_method' => ['required', Rule::in(['bml', 'mib'])],
        ]);

        $customer = $this->customer($request);

        $address = $validated['address_id'] ?? null
            ? $customer->addresses()->whereKey($validated['address_id'])->first()
            : $customer->addresses()->where('is_default', true)->first();

        $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->getKey()]);

        try {
            $order = $this->checkout->place($customer, $cart, $address, $validated['payment_method']);
        } catch (CheckoutException $e) {
            // The message names the shop wherever it can — "checkout failed"
            // is not something a shopper can act on.
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 422);
        }

        return new JsonResponse(['data' => $this->present($order->load('suborders.items'))], 201);
    }

    /** The transfer receipt. Until this lands, nothing is confirmed. */
    public function uploadReceipt(Request $request, int $order): JsonResponse
    {
        $request->validate([
            'receipt' => ['required', 'file', 'extensions:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $row = $this->customer($request)->orders()->whereKey($order)->firstOrFail();

        if ($row->payment_state === 'verified') {
            return new JsonResponse([
                'message' => 'This order is already paid.',
                'code' => 'already_verified',
            ], 409);
        }

        $file = $request->file('receipt');

        // Private disk: a payment receipt carries an account number.
        $path = $file->storeAs(
            sprintf('order-receipts/%d', $row->id),
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'local',
        );

        if ($row->receipt_path !== null) {
            Storage::disk('local')->delete($row->receipt_path);
        }

        $row->forceFill([
            'receipt_path' => $path,
            'payment_state' => 'proof_submitted',
            'proof_submitted_at' => CarbonImmutable::now(),
            'state' => 'under_review',
            'refused_reason' => null,
        ])->save();

        return new JsonResponse(['data' => $this->present($row->refresh()->load('suborders.items'))]);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->customer($request)->orders()
            ->with('suborders.items', 'suborders.merchant:id,name', 'suborders.branch:id,name')
            ->orderByDesc('placed_at')
            ->limit(100)
            ->get();

        return new JsonResponse(['data' => $orders->map($this->present(...))->values()]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $row = $this->customer($request)->orders()
            ->with('suborders.items', 'suborders.merchant:id,name', 'suborders.branch:id,name')
            ->whereKey($order)
            ->firstOrFail();

        return new JsonResponse(['data' => $this->present($row)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'state' => $order->state,
            'payment_state' => $order->payment_state,
            'payment_method' => $order->payment_method,
            'items_laari' => $order->items_laari,
            'delivery_laari' => $order->delivery_laari,
            'total_payable_laari' => $order->total_payable_laari,
            'cashback_total_laari' => $order->cashback_total_laari,
            'address' => $order->address_snapshot,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'store_count' => $order->suborders->count(),
            'suborders' => $order->suborders->map(fn (Suborder $sub): array => [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'store_name' => $sub->merchant?->name,
                'branch_name' => $sub->branch?->name,
                'fulfilment' => $sub->fulfilment,
                'state' => $sub->state,
                'reject_reason' => $sub->reject_reason,
                'pickup_code' => $sub->pickup_code,
                'items_laari' => $sub->items_laari,
                'delivery_laari' => $sub->delivery_laari,
                'subtotal_laari' => $sub->subtotal_laari,
                'cashback_laari' => $sub->cashback_laari,
                'items' => $sub->items->map(fn (SuborderItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'qty' => $item->qty,
                    // Both numbers, always — the strike-through in §3.5 is
                    // drawn from the gap between them.
                    'fulfilled_qty' => $item->fulfilled_qty,
                    'amended' => $item->wasAmended(),
                    'refund_laari' => $item->refundLaari(),
                    'unit_price_laari' => $item->unit_price_laari,
                    'line_total_laari' => $item->line_total_laari,
                ])->values(),
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
