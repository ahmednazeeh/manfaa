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

Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('credentials', [MerchantCredentialController::class, 'index']);
});
