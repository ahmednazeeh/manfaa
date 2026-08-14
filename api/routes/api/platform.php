<?php

use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\PlatformBankAccountsController;
use App\Http\Controllers\Admin\PlatformFeeTiersController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// Platform settings domain: the platform's own bank accounts (what merchant
// settlement instructions point at), the admin-manageable §4 fee tier
// schedule (append-only effective dating), typed key-value platform
// settings, and — superadmin only — admin account management.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('platform/bank-accounts', [PlatformBankAccountsController::class, 'index']);

    // Writes to the platform's settlement-receiving accounts redirect every
    // merchant's payment instructions — superadmin only, like admin
    // account management.
    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::post('platform/bank-accounts', [PlatformBankAccountsController::class, 'store']);
        Route::patch('platform/bank-accounts/{id}', [PlatformBankAccountsController::class, 'update'])->whereNumber('id');
    });

    Route::get('platform/fee-tiers', [PlatformFeeTiersController::class, 'index']);
    Route::post('platform/fee-tiers', [PlatformFeeTiersController::class, 'store']);

    Route::get('platform/settings', [PlatformSettingsController::class, 'index']);
    Route::patch('platform/settings/{key}', [PlatformSettingsController::class, 'update']);

    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::get('admins', [AdminUsersController::class, 'index']);
        Route::post('admins', [AdminUsersController::class, 'store']);
        Route::patch('admins/{id}', [AdminUsersController::class, 'update'])->whereNumber('id');
    });
});
