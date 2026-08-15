<?php

use App\Http\Controllers\Admin\StoreCategoriesController;
use App\Http\Controllers\Admin\StoreOffersController;
use App\Http\Controllers\Admin\StoreReviewController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// Store onboarding review domain (§1 decision 2026-08-15): the admin
// approval queue over self-signed-up stores, and CRUD over the curated
// store categories the wizard picks from.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('store-reviews', [StoreReviewController::class, 'index']);

    // Approve/reject is the single trust gate that makes a self-signed-up
    // store publicly live (§1: "SUPERADMIN approval queue activates it") —
    // superadmin only, like platform bank-account writes and admin account
    // management. Any admin may still read the queue.
    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::post('store-reviews/{merchant}/approve', [StoreReviewController::class, 'approve'])->whereNumber('merchant');
        Route::post('store-reviews/{merchant}/reject', [StoreReviewController::class, 'reject'])->whereNumber('merchant');
    });

    Route::get('store-categories', [StoreCategoriesController::class, 'index']);
    Route::post('store-categories', [StoreCategoriesController::class, 'store']);
    Route::patch('store-categories/{id}', [StoreCategoriesController::class, 'update'])->whereNumber('id');

    // The icon is a FILE, so it gets its own endpoints rather than riding
    // the JSON PATCH: a multipart body cannot carry the rest of the row,
    // and clearing an icon has to be distinguishable from not sending one.
    Route::post('store-categories/{id}/icon', [StoreCategoriesController::class, 'uploadIcon'])->whereNumber('id');
    Route::delete('store-categories/{id}/icon', [StoreCategoriesController::class, 'destroyIcon'])->whereNumber('id');

    // Curated featured offers — editorial placement on the storefront, so
    // superadmin only, like the approval queue and the platform's own bank
    // accounts. Any admin may still read the list.
    Route::get('store-offers', [StoreOffersController::class, 'index']);
    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::post('store-offers', [StoreOffersController::class, 'store']);
        Route::patch('store-offers/{id}', [StoreOffersController::class, 'update'])->whereNumber('id');
        Route::post('store-offers/{id}/image', [StoreOffersController::class, 'uploadImage'])->whereNumber('id');
        // Removing the artwork turns an image banner back into a text one.
        Route::delete('store-offers/{id}/image', [StoreOffersController::class, 'destroyImage'])->whereNumber('id');
    });
});
