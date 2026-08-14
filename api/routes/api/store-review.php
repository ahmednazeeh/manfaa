<?php

use App\Http\Controllers\Admin\StoreCategoriesController;
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
});
