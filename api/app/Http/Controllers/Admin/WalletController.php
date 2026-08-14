<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Laari;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\WalletFunding;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Models\Merchant;
use App\Models\MerchantWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin recording of merchant wallet top-ups: a bank transfer into the
 * wallet, keyed by its bank reference. The unique (wallet_id, bank_ref)
 * index makes the same transfer unrepeatable — a duplicate is a 409, and
 * the wallet is credited exactly once.
 */
class WalletController extends Controller
{
    public function storeTopUp(Request $request, Merchant $merchant, WalletFunding $wallet): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'bank_ref' => ['required', 'string', 'max:128'],
        ]);

        try {
            $wallet->recordTopUp($merchant, Laari::of((int) $validated['amount']), $validated['bank_ref']);
        } catch (DuplicateBankRefException $e) {
            abort(409, $e->getMessage());
        }

        /** @var MerchantWallet $merchantWallet */
        $merchantWallet = MerchantWallet::query()->where('merchant_id', $merchant->id)->firstOrFail();

        return (new WalletResource(
            $merchantWallet->load(['transactions' => fn ($query) => $query->orderByDesc('id')]),
        ))
            ->response($request)
            ->setStatusCode(201);
    }
}
