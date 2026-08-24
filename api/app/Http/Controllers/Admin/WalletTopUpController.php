<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\InvalidWalletTopUpStateException;
use App\Domain\Settlement\SlipStorage;
use App\Domain\Settlement\WalletTopUps;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTopUpResource;
use App\Models\AdminUser;
use App\Models\WalletTopUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin side of the wallet top-up queue (owner, 2026-08-24): the
 * fallback when the bank-history verifier could not find a merchant's
 * transfer. Listing by state, the slip, and the two review outcomes —
 * Match (credits the wallet through the same path the verifier uses) or
 * Reject (with a reason the merchant sees).
 *
 * Any admin, like the settlement payment match — reconciling a transfer
 * against a statement is ordinary queue work, not a superadmin lever.
 */
class WalletTopUpController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'state' => ['sometimes', Rule::in(['pending', 'matched', 'rejected'])],
        ]);

        return WalletTopUpResource::collection(
            WalletTopUp::query()
                ->when(isset($validated['state']), fn ($query) => $query->where('state', $validated['state']))
                ->with(['merchant', 'platformBankAccount'])
                ->orderByDesc('id')
                ->paginate(25),
        );
    }

    public function match(Request $request, int $id, WalletTopUps $topUps): WalletTopUpResource
    {
        $topUp = WalletTopUp::query()->findOrFail($id);

        $validated = $request->validate([
            // Required when the merchant typed none: the wallet movement's
            // idempotency key is the reference, and a credit without one
            // could be booked twice.
            'bank_ref' => [$topUp->bank_ref === null ? 'required' : 'sometimes', 'nullable', 'string', 'max:128'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $topUp = $topUps->match($topUp, $admin, $validated['bank_ref'] ?? null);
        } catch (InvalidWalletTopUpStateException $e) {
            abort(409, $e->getMessage());
        } catch (DuplicateBankRefException $e) {
            abort(409, $e->getMessage());
        }

        return new WalletTopUpResource($topUp->load(['merchant', 'platformBankAccount']));
    }

    public function reject(Request $request, int $id, WalletTopUps $topUps): WalletTopUpResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $topUp = WalletTopUp::query()->findOrFail($id);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $topUp = $topUps->reject($topUp, $admin, $validated['reason']);
        } catch (InvalidWalletTopUpStateException $e) {
            abort(409, $e->getMessage());
        }

        return new WalletTopUpResource($topUp->load(['merchant', 'platformBankAccount']));
    }

    /**
     * Streams the merchant's uploaded slip to the reviewing admin — the
     * ONLY way one is ever read (the `slips` disk has no URL). Mirrors
     * Admin\SettlementController::slip: stored mime, nosniff, inline.
     */
    public function slip(int $id): StreamedResponse
    {
        $topUp = WalletTopUp::query()->findOrFail($id);

        if ($topUp->slip_path === null) {
            abort(404, 'No slip is attached to this top-up.');
        }

        $disk = Storage::disk(SlipStorage::DISK);

        if (! $disk->exists($topUp->slip_path)) {
            abort(404, 'The slip file is missing from storage.');
        }

        $extension = pathinfo($topUp->slip_path, PATHINFO_EXTENSION);

        return $disk->response($topUp->slip_path, headers: [
            'Content-Type' => $topUp->slip_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf(
                'inline; filename="top-up-%d%s"',
                $topUp->id,
                $extension === '' ? '' : '.'.$extension,
            ),
        ]);
    }
}
