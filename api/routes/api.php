<?php

use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\CustomerAuthController;
use App\Http\Controllers\Auth\MerchantAuthController;
use App\Http\Controllers\Merchant\CreditController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [AdminAuthController::class, 'me']);
    });
});

Route::prefix('merchant/auth')->group(function () {
    Route::post('login', [MerchantAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:merchant')->group(function () {
        Route::post('logout', [MerchantAuthController::class, 'logout']);
        Route::get('me', [MerchantAuthController::class, 'me']);
    });
});

Route::prefix('customer/auth')->group(function () {
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:customer')->group(function () {
        Route::post('logout', [CustomerAuthController::class, 'logout']);
        Route::get('me', [CustomerAuthController::class, 'me']);
    });
});

Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    // Manual credits are the exposed fraud surface (§11) — throttled per
    // merchant user. `credits.create` is the till's whole reason to be
    // logged in, so every seeded role holds it; the finer authority — a
    // per-sale rate override — is `credits.custom_rate`, checked inside the
    // controller because it gates a FIELD, not the route.
    Route::post('credits', [CreditController::class, 'store'])
        ->middleware(['merchant.can:credits.create', 'throttle:30,1']);
});

// Per-domain route files — each domain owns exactly one file in routes/api/.
foreach (glob(__DIR__.'/api/*.php') as $domainRoutes) {
    require $domainRoutes;
}
