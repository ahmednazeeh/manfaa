<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Laari;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\ReceiptSlip;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementLockedException;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\SlipStorage;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementPaymentResource;
use App\Http\Resources\SettlementResource;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin side of the settlement matching queue (§10): batch listing by
 * state, batch detail with lines, the merchant's uploaded slip, and the two
 * review outcomes — Match (on SettlementPaymentController) or Reject.
 *
 * The receipt-first flow (PLAN §1) makes this queue a review of EVIDENCE:
 * every merchant-created batch arrives in payment_review with a slip
 * attached. Admin-side recording (storePayment) remains, for a transfer
 * reconciled off a bank statement.
 *
 * Admin-side batch CREATION (storeForMerchant) was removed on 2026-08-20:
 * only merchants create settlements (owner). It had no client — nothing in
 * the api-client or the panel ever called it — and no settlement has ever
 * reached awaiting_payment, the state it alone produced. Its notification
 * went with it.
 */
class SettlementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'state' => ['sometimes', Rule::enum(SettlementState::class)],
        ]);

        return SettlementResource::collection(
            Settlement::query()
                ->when(isset($validated['state']), fn ($query) => $query->where('state', $validated['state']))
                ->with('payments')
                ->orderByDesc('id')
                ->paginate(25),
        );
    }

    public function show(int $id): SettlementResource
    {
        return new SettlementResource(
            Settlement::query()->findOrFail($id)->load(['lines.transaction', 'payments']),
        );
    }

    public function storePayment(Request $request, int $id, SettlementAllocator $allocator): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'bank_ref' => ['required', 'string', 'max:128'],
            'slip_path' => ['nullable', 'string', 'max:255'],
        ]);

        $settlement = Settlement::query()->findOrFail($id);

        try {
            $payment = $allocator->recordBankPayment(
                $settlement,
                Laari::of((int) $validated['amount']),
                $validated['bank_ref'],
                isset($validated['slip_path']) ? ReceiptSlip::pathOnly($validated['slip_path']) : null,
            );
        } catch (DuplicateBankRefException|InvalidSettlementStateException $e) {
            abort(409, $e->getMessage());
        }

        return (new SettlementPaymentResource($payment))
            ->response($request)
            ->setStatusCode(201);
    }

    /**
     * Reject the receipt (PLAN §1): the transfer could not be verified, so
     * the batch cancels, its lines release — the transactions go straight
     * back to payable_unfunded eligibility and the merchant simply creates a
     * new settlement — and the reason is recorded on the payment, where the
     * merchant's own batch view reads it.
     */
    public function reject(Request $request, int $id, SettlementBuilder $builder): SettlementResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $settlement = Settlement::query()->findOrFail($id);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $settlement = $builder->reject($settlement, $admin, $validated['reason']);
        } catch (InvalidSettlementStateException|SettlementLockedException $e) {
            abort(409, $e->getMessage());
        }

        return new SettlementResource($settlement->refresh()->load(['lines.transaction', 'payments']));
    }

    /**
     * Streams the merchant's uploaded slip to the reviewing admin. This is
     * the ONLY way a slip is ever read: the `slips` disk has no URL and is
     * not served, so there is no public path, signed or otherwise, that
     * reaches the file — authorisation is the admin guard on this route.
     *
     * Defaults to the newest slip on the batch (the one under review);
     * ?payment_id= names an earlier one when a batch carries several.
     */
    public function slip(Request $request, int $id): StreamedResponse
    {
        $validated = $request->validate([
            'payment_id' => ['sometimes', 'integer'],
        ]);

        $settlement = Settlement::query()->findOrFail($id);

        /** @var SettlementPayment|null $payment */
        $payment = $settlement->payments()
            ->whereNotNull('slip_path')
            ->when(isset($validated['payment_id']), fn ($query) => $query->whereKey($validated['payment_id']))
            ->orderByDesc('id')
            ->first();

        if ($payment === null) {
            abort(404, 'No payment slip is attached to this settlement.');
        }

        $disk = Storage::disk(SlipStorage::DISK);

        if (! $disk->exists($payment->slip_path)) {
            abort(404, 'The payment slip file is missing from storage.');
        }

        // Inline, with the stored (magic-byte derived) mime — never the
        // client's claimed Content-Type — and nosniff, so a file that somehow
        // got past the signature check still cannot be interpreted as
        // something executable by the reviewing browser.
        return $disk->response($payment->slip_path, headers: [
            'Content-Type' => $payment->slip_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf('inline; filename="slip-%s%s"', $settlement->reference, $this->extension($payment->slip_path)),
        ]);
    }

    private function extension(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.'.$extension;
    }
}
