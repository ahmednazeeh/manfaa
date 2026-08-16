<?php

use App\Http\Controllers\Merchant\ProductCategoriesController;
use App\Http\Controllers\V1\ProductCategoriesController as V1ProductCategoriesController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureVendorCredential;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Per-store product categories (Task #25). `product_categories.view` seeds
// to every role — the list feeds the credit form, and posting credits is
// till work — while creating and changing categories reprices future sales
// and seeds to Manager and above (PLAN §1: the line-item rate card is a
// pricing surface), on TRADING stores only (EnsureMerchantApproved:trading
// — pre-approval the setup wizard is the sole write path, and a suspended
// store's offer is frozen with its rate and promotions: the rate card is
// the same commercial surface, priced per line).
//
// Permission before approval, so a refusal never leaks the store's standing.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('product-categories', [ProductCategoriesController::class, 'index'])
        ->middleware('merchant.can:product_categories.view');

    Route::post('product-categories', [ProductCategoriesController::class, 'store'])
        ->middleware(['merchant.can:product_categories.create', EnsureMerchantApproved::class.':trading']);

    Route::patch('product-categories/{id}', [ProductCategoriesController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['merchant.can:product_categories.edit', EnsureMerchantApproved::class.':trading']);
});

// Vendor-facing listing (§9.2 family): the ACTIVE slugs a till may submit
// in POST /v1/transactions lines[].category. Same bearer-token-only stack
// as the rest of /v1; read ability `rates:read` — categories ARE the
// per-line rate card.
Route::prefix('v1')->middleware(['auth:sanctum', EnsureVendorCredential::class, 'throttle:vendor-api'])->group(function () {
    Route::get('merchants/me/product-categories', V1ProductCategoriesController::class)
        ->middleware(CheckAbilities::class.':rates:read');
});
