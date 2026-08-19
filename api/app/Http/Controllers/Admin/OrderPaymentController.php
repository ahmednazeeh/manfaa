<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Payment verification (deferred from MP5, `Payment Step.png`).
 *
 * The customer transferred to our account and uploaded proof; nothing is
 * confirmed until a human has looked. Same discipline as settlement
 * receipts, and for the same reason: a screenshot is not a bank statement.
 */
final class OrderPaymentController extends Controller
{
    /** Everything waiting on a pair of eyes, oldest first. */
    public function index(Request $request): JsonResponse
    {
        $state = (string) $request->query('payment_state', 'proof_submitted');

        $orders = Order::query()
            ->with('customer:id,name,phone', 'suborders.merchant:id,name')
            ->when($state !== 'all', fn ($query) => $query->where('payment_state', $state))
            ->orderBy('proof_submitted_at')
            ->limit(200)
            ->get();

        return new JsonResponse([
            'data' => $orders->map(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'customer_name' => $order->customer?->name,
                'customer_phone' => $order->customer?->phone,
                'total_payable_laari' => $order->total_payable_laari,
                'payment_method' => $order->payment_method,
                'payment_state' => $order->payment_state,
                'has_receipt' => $order->receipt_path !== null,
                'proof_submitted_at' => $order->proof_submitted_at?->toIso8601String(),
                // Who decided. A machine-verified payment is still auditable
                // as one — verified_by is deliberately left null, so this
                // flag is the only thing that says so.
                'auto_verified' => (bool) $order->auto_verified,
                'matched_trx_id' => $order->matched_trx_id,
                'matched_payer_name' => $order->matched_payer_name,
                'matched_score' => $order->matched_score,
                // Set while the bank is still being watched.
                'poll_until' => $order->poll_until?->toIso8601String(),
                'stores' => $order->suborders->map(fn ($sub): ?string => $sub->merchant?->name)->values(),
            ])->values(),
        ]);
    }

    /** Stream the proof. Never a public URL — it carries an account number. */
    public function receipt(Order $order)
    {
        abort_if($order->receipt_path === null, 404);
        abort_unless(Storage::disk('local')->exists($order->receipt_path), 404);

        return Storage::disk('local')->download($order->receipt_path);
    }

    public function verify(Request $request, Order $order): JsonResponse
    {
        if ($order->payment_state !== 'proof_submitted') {
            return new JsonResponse([
                'message' => 'This order has no payment proof waiting.',
                'code' => 'no_proof_waiting',
            ], 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $order->forceFill([
            'payment_state' => 'verified',
            'verified_by' => $admin->getKey(),
            'verified_at' => CarbonImmutable::now(),
            // The shops can start work. Their own suborders stay `new` until
            // each accepts — verification is about the MONEY, not about
            // anybody agreeing to fulfil.
            'state' => 'under_review',
        ])->save();

        return new JsonResponse(['data' => ['payment_state' => 'verified']]);
    }

    public function refuse(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($order->payment_state === 'verified') {
            return new JsonResponse([
                'message' => 'This payment has already been accepted.',
                'code' => 'already_verified',
            ], 409);
        }

        $order->forceFill([
            'payment_state' => 'refused',
            'refused_reason' => $validated['reason'],
            // Back to awaiting a receipt, not cancelled: a wrong screenshot
            // is a fixable mistake, and cancelling the order would throw
            // away a basket somebody built.
            'state' => 'placed',
        ])->save();

        return new JsonResponse(['data' => [
            'payment_state' => 'refused',
            'refused_reason' => $validated['reason'],
        ]]);
    }
}
