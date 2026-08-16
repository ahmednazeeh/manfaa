<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Money\Laari;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\InsufficientWalletBalanceException;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\InvalidSlipException;
use App\Domain\Settlement\NotEligibleForSettlementException;
use App\Domain\Settlement\OutstandingSummary;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementLockedException;
use App\Domain\Settlement\SettlementPreview;
use App\Domain\Settlement\SlipStorage;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementResource;
use App\Http\Resources\WalletResource;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Merchant-facing settlement surface (§10), receipt-first (PLAN §1): the
 * merchant selects transactions, PREVIEWS what they owe and where to send
 * it, transfers at their own bank, then SUBMITS the slip and the bank
 * reference — which is the only act that creates a settlement at all. The
 * batch lands in payment_review and an admin reviews the slip.
 *
 * There is deliberately no draft-then-submit pair here any more, and no way
 * to reach an awaiting_payment batch: a settlement with no receipt would put
 * the merchant back at the dead end this flow exists to remove, and would
 * make the admin queue a list of claims nobody can verify. The old
 * `POST /settlements/{id}/submit` and `POST /settlements/{id}/wallet-settle`
 * routes are gone; wallet settlement is now a single create-and-settle call.
 *
 * Every {id} lookup is scoped through the authenticated merchant's own
 * relations — another merchant's settlement is a plain 404.
 */
class SettlementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SettlementResource::collection(
            $this->merchantUser($request)->merchant->settlements()
                // The merchant list must be able to say "Manfaa is verifying
                // your transfer" and, on a rejected batch, WHY — both read
                // off the payments, so they load with the list.
                ->with('payments')
                ->orderByDesc('id')
                ->paginate(25),
        );
    }

    public function show(Request $request, int $id): SettlementResource
    {
        return new SettlementResource(
            $this->settlement($request, $id)->load(['lines.transaction', 'payments']),
        );
    }

    /**
     * What this selection costs and where to send it — before any commitment
     * (PLAN §1: "see amount due + platform bank account (copy button) +
     * reference"). Claims nothing: no draft, no reference reserved.
     */
    public function preview(Request $request, SettlementPreview $preview): JsonResponse
    {
        $validated = $request->validate([
            'settle_all' => ['required_without:transaction_ids', 'boolean'],
            'transaction_ids' => ['required_without:settle_all', 'array', 'min:1'],
            'transaction_ids.*' => ['integer'],
        ]);

        try {
            $data = $preview->for(
                $this->merchantUser($request)->merchant,
                $this->selection($validated),
            );
        } catch (NotEligibleForSettlementException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $data]);
    }

    /**
     * The receipt-first submission (PLAN §1). Multipart: the selection, the
     * amount actually transferred, the bank reference, and the slip. One
     * database transaction builds the batch, freezes its lines, stores the
     * slip privately and records the payment — the batch is created in
     * payment_review or not at all.
     */
    public function store(Request $request, SettlementBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'settle_all' => ['required_without:transaction_ids', 'boolean'],
            'transaction_ids' => ['required_without:settle_all', 'array', 'min:1'],
            'transaction_ids.*' => ['integer'],
            'amount' => ['required', 'integer', 'min:1'],
            'bank_ref' => ['required', 'string', 'max:128'],
            // First-pass gate only. The authority on what this file IS lives
            // in SlipStorage, which reads the magic bytes — `mimes` here
            // trusts finfo/extension and would happily pass a renamed SVG.
            'slip' => ['required', 'file', 'max:'.intdiv(SlipStorage::MAX_BYTES, 1024)],
            'platform_bank_account_id' => [
                'sometimes', 'integer',
                Rule::exists('platform_bank_accounts', 'id')->where('active', true),
            ],
        ]);

        $user = $this->merchantUser($request);

        try {
            $settlement = $builder->createAndSubmitWithReceipt(
                $user->merchant,
                $user,
                $this->selection($validated),
                Laari::of((int) $validated['amount']),
                $validated['bank_ref'],
                $request->file('slip'),
                isset($validated['platform_bank_account_id']) ? (int) $validated['platform_bank_account_id'] : null,
            );
        } catch (InvalidSlipException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (NotEligibleForSettlementException $e) {
            abort(422, $e->getMessage());
        } catch (DuplicateBankRefException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'duplicate_bank_ref'], 409);
        } catch (InvalidSettlementStateException|SettlementLockedException $e) {
            abort(409, $e->getMessage());
        }

        return (new SettlementResource($settlement->load(['lines.transaction', 'payments'])))
            ->response($request)
            ->setStatusCode(201);
    }

    /**
     * A further receipt against a batch that is still owed money — a partial
     * payment's remainder (§7 leaves the uncovered lines frozen here, so a
     * new batch cannot pick them up), or the transfer for a batch an admin
     * built as the fallback.
     */
    public function storeReceipt(Request $request, int $id, SettlementBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'bank_ref' => ['required', 'string', 'max:128'],
            'slip' => ['required', 'file', 'max:'.intdiv(SlipStorage::MAX_BYTES, 1024)],
            // No destination here on purpose: a batch's account is chosen
            // once, when it is created. A further receipt pays down the same
            // batch, so accepting a second bank would either overwrite the
            // account the first transfer actually went to or record two, and
            // both make the batch harder to reconcile than knowing one.
        ]);

        $user = $this->merchantUser($request);
        $settlement = $this->settlement($request, $id);

        try {
            $builder->addReceipt(
                $settlement,
                $user,
                Laari::of((int) $validated['amount']),
                $validated['bank_ref'],
                $request->file('slip'),
            );
        } catch (InvalidSlipException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (DuplicateBankRefException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'duplicate_bank_ref'], 409);
        } catch (InvalidSettlementStateException|SettlementLockedException $e) {
            abort(409, $e->getMessage());
        }

        return (new SettlementResource($settlement->refresh()->load(['lines.transaction', 'payments'])))
            ->response($request)
            ->setStatusCode(201);
    }

    /**
     * Wallet settlement (§7: same path, only the funding source differs) —
     * build and settle in one call. No receipt: the money is already on the
     * platform, evidenced by the top-up that put it there. A batch fully
     * netted by §7 credits also settles here, drawing nothing.
     */
    public function walletSettle(Request $request, SettlementBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'settle_all' => ['required_without:transaction_ids', 'boolean'],
            'transaction_ids' => ['required_without:settle_all', 'array', 'min:1'],
            'transaction_ids.*' => ['integer'],
        ]);

        $user = $this->merchantUser($request);

        try {
            $settlement = $builder->createAndSettleFromWallet(
                $user->merchant,
                $user,
                $this->selection($validated),
            );
        } catch (InsufficientWalletBalanceException $e) {
            abort(422, $e->getMessage());
        } catch (NotEligibleForSettlementException $e) {
            abort(422, $e->getMessage());
        } catch (InvalidSettlementStateException|SettlementLockedException $e) {
            abort(409, $e->getMessage());
        }

        return (new SettlementResource($settlement->load(['lines.transaction', 'payments'])))
            ->response($request)
            ->setStatusCode(201);
    }

    public function outstanding(Request $request, OutstandingSummary $summary): JsonResponse
    {
        return response()->json([
            'data' => $summary->forMerchant($this->merchantUser($request)->merchant),
        ]);
    }

    public function wallet(Request $request): WalletResource
    {
        $wallet = MerchantWallet::query()->firstOrCreate(
            ['merchant_id' => $this->merchantUser($request)->merchant_id],
            ['balance_laari' => 0, 'currency' => 'MVR'],
        );

        return new WalletResource(
            $wallet->load(['transactions' => fn ($query) => $query->orderByDesc('id')]),
        );
    }

    /**
     * null means "everything eligible"; an explicit list is claimed exactly.
     *
     * @param  array<string, mixed>  $validated
     * @return list<int>|null
     */
    private function selection(array $validated): ?array
    {
        if ((bool) ($validated['settle_all'] ?? false)) {
            return null;
        }

        return array_map(intval(...), $validated['transaction_ids'] ?? []);
    }

    private function settlement(Request $request, int $id): Settlement
    {
        /** @var Settlement */
        return $this->merchantUser($request)->merchant->settlements()->findOrFail($id);
    }

    private function merchantUser(Request $request): MerchantUser
    {
        /** @var MerchantUser */
        return $request->user('merchant');
    }
}
