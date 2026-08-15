<?php

use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\PosVendorController;
use App\Http\Controllers\Merchant\CredentialController as MerchantCredentialController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Vendor credential management (§9.1): POS vendor registry, per-merchant
// token issuance/revocation (admin), and the merchant's own read model.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::post('pos-vendors', [PosVendorController::class, 'store']);
    Route::get('pos-vendors', [PosVendorController::class, 'index']);

    Route::post('merchants/{merchant}/credentials', [CredentialController::class, 'store']);
    Route::get('merchants/{merchant}/credentials', [CredentialController::class, 'index']);
    Route::delete('credentials/{credential}', [CredentialController::class, 'destroy']);
});

// The merchant's own credentials are OWNER-only throughout (PLAN §1: API
// credentials are explicitly outside the manager tier) — they name the POS
// vendors holding write tokens against the store, which is integration
// governance, not shop-floor work.
//
// Self-serve issuance (§13b task #21) additionally needs an APPROVED store
// (EnsureMerchantApproved, 409 store_not_approved): a token minted before
// approval could not record one eligible sale, and the wizard is the only
// screen a pre-approval owner sees. Suspended and closed stores clear that
// middleware and are refused inside the controller instead (409
// store_not_trading), where the refusal can say what it means for an
// integration rather than for a rate card.
//
// REVOCATION carries no approval gate on purpose: killing a leaked token is
// a security action, and it must never be blocked by the store's commercial
// standing.
Route::prefix('merchant')->middleware(['auth:merchant', 'merchant.role:owner'])->group(function () {
    Route::get('credentials', [MerchantCredentialController::class, 'index']);

    Route::post('credentials', [MerchantCredentialController::class, 'store'])
        ->middleware(EnsureMerchantApproved::class);

    Route::delete('credentials/{id}', [MerchantCredentialController::class, 'destroy'])
        ->whereNumber('id');
});
