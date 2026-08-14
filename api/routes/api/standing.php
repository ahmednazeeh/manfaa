<?php

use App\Http\Controllers\Admin\MerchantsController;
use App\Http\Controllers\Admin\ReconciliationController;
use Illuminate\Support\Facades\Route;

// Merchant standing and daily reconciliation read models for the admin
// panel — the §7 clock's observable surface.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('merchants', [MerchantsController::class, 'index']);
    Route::get('merchants/{merchant}/notices', [MerchantsController::class, 'notices']);
    Route::get('reconciliation', [ReconciliationController::class, 'index']);
});
