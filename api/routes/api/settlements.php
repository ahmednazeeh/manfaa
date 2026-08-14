<?php

use App\Http\Controllers\Admin\SettlementController as AdminSettlementController;
use App\Http\Controllers\Admin\SettlementPaymentController;
use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Merchant\SettlementController as MerchantSettlementController;
use App\Http\Middleware\EnsureMerchantOwner;
use Illuminate\Support\Facades\Route;

// Settlement domain (§6, §7, §12 Phase 1): outstanding ageing, the batch
// builder, wallet settlement, and the admin matching queue. Every merchant
// {id} route resolves through the authenticated merchant's own relations —
// another merchant's settlement is indistinguishable from a missing one.
// Every settlement MUTATION (create, submit, wallet-settle) is owner-only:
// submit freezes the lines irreversibly, and on a fully credit-netted
// draft it allocates every line and settles the batch on the spot — not
// staff work. Reads stay staff-accessible.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('outstanding', [MerchantSettlementController::class, 'outstanding']);
    Route::get('wallet', [MerchantSettlementController::class, 'wallet']);

    Route::get('settlements', [MerchantSettlementController::class, 'index']);
    Route::post('settlements', [MerchantSettlementController::class, 'store'])->middleware(EnsureMerchantOwner::class);
    Route::get('settlements/{id}', [MerchantSettlementController::class, 'show'])->whereNumber('id');
    Route::post('settlements/{id}/submit', [MerchantSettlementController::class, 'submit'])->whereNumber('id')->middleware(EnsureMerchantOwner::class);
    Route::post('settlements/{id}/wallet-settle', [MerchantSettlementController::class, 'walletSettle'])->whereNumber('id')->middleware(EnsureMerchantOwner::class);
});

Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('settlements', [AdminSettlementController::class, 'index']);
    Route::get('settlements/{id}', [AdminSettlementController::class, 'show'])->whereNumber('id');
    Route::post('settlements/{id}/payments', [AdminSettlementController::class, 'storePayment'])->whereNumber('id');
    Route::post('payments/{id}/match', [SettlementPaymentController::class, 'match'])->whereNumber('id');
    Route::post('merchants/{merchant}/wallet/top-ups', [WalletController::class, 'storeTopUp'])->whereNumber('merchant');
});
