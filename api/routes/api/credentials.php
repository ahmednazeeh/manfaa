<?php

use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\PosVendorController;
use App\Http\Controllers\Merchant\CredentialController as MerchantCredentialController;
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

// The merchant's own credential read model is OWNER-only (PLAN §1: API
// credentials are explicitly outside the manager tier) — it names the POS
// vendors holding write tokens against the store, which is integration
// governance, not shop-floor work.
Route::prefix('merchant')->middleware(['auth:merchant', 'merchant.role:owner'])->group(function () {
    Route::get('credentials', [MerchantCredentialController::class, 'index']);
});
