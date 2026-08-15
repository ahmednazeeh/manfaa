<?php

use App\Http\Controllers\Merchant\ProductCategoriesController;
use App\Http\Controllers\V1\ProductCategoriesController as V1ProductCategoriesController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureMerchantOwner;
use App\Http\Middleware\EnsureVendorCredential;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Per-store product categories (Task #25). The GET is STAFF-readable — it
// feeds the credit form, and posting credits is staff work — while creating
// and changing categories reprices future sales and is OWNER-only, on
// APPROVED stores only (pre-approval the setup wizard is the sole write
// path).
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('product-categories', [ProductCategoriesController::class, 'index']);

    Route::middleware([EnsureMerchantOwner::class, EnsureMerchantApproved::class])->group(function () {
        Route::post('product-categories', [ProductCategoriesController::class, 'store']);
        Route::patch('product-categories/{id}', [ProductCategoriesController::class, 'update'])->whereNumber('id');
    });
});

// Vendor-facing listing (§9.2 family): the ACTIVE slugs a till may submit
// in POST /v1/transactions lines[].category. Same bearer-token-only stack
// as the rest of /v1; read ability `rates:read` — categories ARE the
// per-line rate card.
Route::prefix('v1')->middleware(['auth:sanctum', EnsureVendorCredential::class, 'throttle:vendor-api'])->group(function () {
    Route::get('merchants/me/product-categories', V1ProductCategoriesController::class)
        ->middleware(CheckAbilities::class.':rates:read');
});
