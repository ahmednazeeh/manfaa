<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Settlement\InsufficientWalletBalanceException;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\NotEligibleForSettlementException;
use App\Domain\Settlement\OutstandingSummary;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementLockedException;
use App\Domain\Settlement\WalletFunding;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementResource;
use App\Http\Resources\WalletResource;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Merchant-facing settlement surface (§10): outstanding by age bucket, the
 * settlement builder, batch history with line detail, wallet settlement and
 * the wallet view. Every {id} lookup is scoped through the authenticated
 * merchant's own relations — another merchant's settlement is a plain 404.
 */
class SettlementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SettlementResource::collection(
            $this->merchantUser($request)->merchant->settlements()->orderByDesc('id')->paginate(25),
        );
    }

    public function show(Request $request, int $id): SettlementResource
    {
        return new SettlementResource(
            $this->settlement($request, $id)->load(['lines.transaction', 'payments']),
        );
    }

    public function store(Request $request, SettlementBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'settle_all' => ['required_without:ids', 'boolean'],
            'ids' => ['required_without:settle_all', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $settleAll = (bool) ($validated['settle_all'] ?? false);

        try {
            $settlement = $builder->createDraft(
                $this->merchantUser($request)->merchant,
                $settleAll ? null : array_map(intval(...), $validated['ids'] ?? []),
            );
        } catch (NotEligibleForSettlementException $e) {
            abort(422, $e->getMessage());
        }

        return (new SettlementResource($settlement->load('lines.transaction')))
            ->response($request)
            ->setStatusCode(201);
    }

    public function submit(Request $request, int $id, SettlementBuilder $builder): SettlementResource
    {
        $settlement = $this->settlement($request, $id);

        try {
            $builder->submit($settlement);
        } catch (NotEligibleForSettlementException $e) {
            abort(422, $e->getMessage());
        } catch (InvalidSettlementStateException|SettlementLockedException $e) {
            abort(409, $e->getMessage());
        }

        return new SettlementResource($settlement->refresh()->load('lines.transaction'));
    }

    public function walletSettle(Request $request, int $id, WalletFunding $wallet): SettlementResource
    {
        $settlement = $this->settlement($request, $id);

        try {
            $wallet->settleFromWallet($settlement, $this->merchantUser($request));
        } catch (InsufficientWalletBalanceException $e) {
            abort(422, $e->getMessage());
        } catch (InvalidSettlementStateException|SettlementLockedException $e) {
            abort(409, $e->getMessage());
        }

        return new SettlementResource($settlement->refresh()->load('lines.transaction'));
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
