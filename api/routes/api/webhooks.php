<?php

use App\Http\Controllers\Admin\WebhookEndpointController;
use App\Http\Controllers\Merchant\RateController;
use App\Http\Middleware\EnsureMerchantApproved;
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

// Merchant panel: read the standing rate (rate.view, seeded to every role —
// the till quotes it) and change it (rate.update, seeded to Manager and
// above; also enforced in the controller, §7 increase/decrease semantics;
// TRADING stores only —
// pre-approval the wizard's rate step is the sole write path, with
// replace-the-initial-row semantics this endpoint lacks, a pending_review
// store must not reprice what the queue reviewed, and a SUSPENDED store
// creates no cashback at all, so a change here would only fire
// merchant.rate_changed and move the till's quoted rate to a number that
// accrues nothing).
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('rate', [RateController::class, 'show'])
        ->middleware('merchant.can:rate.view');

    Route::post('rate', [RateController::class, 'store'])
        ->middleware(['merchant.can:rate.update', EnsureMerchantApproved::class.':trading']);
});
