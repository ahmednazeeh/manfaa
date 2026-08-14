<?php

use App\Http\Controllers\Admin\WebhookEndpointController;
use App\Http\Controllers\Merchant\RateController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureMerchantOwner;
use Illuminate\Support\Facades\Route;

// §9.3 outbound webhooks + the §7 rate-change rules.
//
// Admin: per-vendor webhook endpoint registry (secret shown once at
// creation, stored encrypted, never retrievable).
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::post('pos-vendors/{vendor}/webhook-endpoints', [WebhookEndpointController::class, 'store']);
    Route::get('pos-vendors/{vendor}/webhook-endpoints', [WebhookEndpointController::class, 'index']);
    Route::delete('pos-vendors/{vendor}/webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'destroy']);
});

// Merchant panel: read the standing rate (any merchant user) and change it
// (owner only — enforced in the controller, §7 increase/decrease semantics;
// approved stores only — pre-approval the wizard's rate step is the sole
// write path, with replace-the-initial-row semantics this endpoint lacks,
// and a pending_review store must not reprice what the queue reviewed).
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('rate', [RateController::class, 'show']);
    Route::post('rate', [RateController::class, 'store'])->middleware([EnsureMerchantOwner::class, EnsureMerchantApproved::class]);
});
