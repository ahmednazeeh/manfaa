<?php

use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\AppReleasesController;
use App\Http\Controllers\Admin\BrandThemeController;
use App\Http\Controllers\Admin\PlatformBankAccountsController;
use App\Http\Controllers\Admin\PlatformFeeTiersController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\TaxSettingsController;
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

    // GST on the platform fee (owner, 2026-08-24). Its own table rather
    // than a typed setting: PlatformConfig stores integers, and a TIN, a
    // business name and an activity number are strings. Readable by any
    // admin; only a superadmin may write — the same gating as the
    // platform's own bank accounts, and for a stronger reason: this switch
    // changes what every merchant owes on every sale from the moment it is
    // thrown.
    Route::get('platform/tax-settings', [TaxSettingsController::class, 'index']);

    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::patch('platform/tax-settings', [TaxSettingsController::class, 'updateSettings']);
    });

    // Mobile release gates (min/latest build, store URL per app+platform),
    // served to the apps by the public /api/mobile/v1/config endpoint.
    // Same gating as the typed settings above: any admin — raising the
    // minimum build is an emergency lever, and an emergency does not wait
    // for a superadmin.
    Route::get('platform/app-releases', [AppReleasesController::class, 'index']);
    Route::put('platform/app-releases', [AppReleasesController::class, 'update']);

    // The storefront accent colour — read by any admin, changed only by a
    // superadmin (a whole-storefront repaint is not an emergency lever).
    Route::get('platform/brand', [BrandThemeController::class, 'show']);

    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::put('platform/brand', [BrandThemeController::class, 'update']);
        Route::get('admins', [AdminUsersController::class, 'index']);
        Route::post('admins', [AdminUsersController::class, 'store']);
        Route::patch('admins/{id}', [AdminUsersController::class, 'update'])->whereNumber('id');
    });
});
