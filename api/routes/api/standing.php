<?php

use App\Http\Controllers\Admin\MerchantsController;
use App\Http\Controllers\Admin\MerchantStaffController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// Merchant standing and daily reconciliation read models for the admin
// panel — the §7 clock's observable surface — plus the superadmin's
// merchant controls: profile edit, manual suspend/reinstate, and staff
// account rescue (password reset, activate/deactivate).
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    // Reads are ordinary admin work, like the review queue and holds.
    Route::get('merchants', [MerchantsController::class, 'index']);
    Route::get('merchants/{merchant}', [MerchantsController::class, 'show'])->whereNumber('merchant');
    Route::get('merchants/{merchant}/notices', [MerchantsController::class, 'notices'])->whereNumber('merchant');
    Route::put('merchants/{merchant}/featured', [MerchantsController::class, 'featured'])->whereNumber('merchant');
    Route::get('reconciliation', [ReconciliationController::class, 'index']);

    // Rewriting a store's public identity, shutting it (or reopening it) by
    // hand, and taking over its staff accounts are superadmin-only, like
    // store approval and platform bank-account writes. Featured stays with
    // every admin: editorial placement, money-free, instantly reversible.
    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::patch('merchants/{merchant}', [MerchantsController::class, 'update'])->whereNumber('merchant');
        Route::post('merchants/{merchant}/suspend', [MerchantsController::class, 'suspend'])->whereNumber('merchant');
        Route::post('merchants/{merchant}/reinstate', [MerchantsController::class, 'reinstate'])->whereNumber('merchant');
        Route::post('merchants/{merchant}/staff/{user}/reset-password', [MerchantStaffController::class, 'resetPassword'])
            ->whereNumber('merchant')->whereNumber('user');
        Route::patch('merchants/{merchant}/staff/{user}', [MerchantStaffController::class, 'update'])
            ->whereNumber('merchant')->whereNumber('user');
    });
});
