<?php

use App\Http\Controllers\Admin\WebhookEndpointController;
use App\Http\Controllers\Merchant\RateController;
use App\Http\Controllers\Merchant\WebhookEndpointController as MerchantWebhookEndpointController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// §9.3 outbound webhooks + the §7 rate-change rules.
//
// Admin: per-vendor webhook endpoint registry (secret shown once at
// creation, stored encrypted, never retrievable).
// Superadmin, like the platform registry these endpoints belong to: an
// endpoint receives every connected merchant's events for that platform.
Route::prefix('admin')->middleware(['auth:admin', EnsureSuperadmin::class])->group(function () {
    Route::post('pos-vendors/{vendor}/webhook-endpoints', [WebhookEndpointController::class, 'store']);
    Route::get('pos-vendors/{vendor}/webhook-endpoints', [WebhookEndpointController::class, 'index']);
    Route::delete('pos-vendors/{vendor}/webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'destroy']);
    Route::post('pos-vendors/{vendor}/webhook-endpoints/{endpoint}/test', [WebhookEndpointController::class, 'test'])
        ->middleware('throttle:6,1');
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
    // A merchant's OWN webhook endpoints (owner, 2026-08-22) — Settings ›
    // API access. Same permissions as the credentials they sit beside:
    // view to list, create to add (owner by preset, approved store only),
    // revoke to remove. "Send test" is a read-level action: it proves a
    // URL the merchant already owns and changes nothing.
    Route::get('webhook-endpoints', [MerchantWebhookEndpointController::class, 'index'])
        ->middleware('merchant.can:api_credentials.view');
    Route::post('webhook-endpoints', [MerchantWebhookEndpointController::class, 'store'])
        ->middleware(['merchant.can:api_credentials.create', EnsureMerchantApproved::class, 'throttle:30,1']);
    Route::delete('webhook-endpoints/{endpoint}', [MerchantWebhookEndpointController::class, 'destroy'])
        ->middleware('merchant.can:api_credentials.revoke')
        ->whereNumber('endpoint');
    Route::post('webhook-endpoints/{endpoint}/test', [MerchantWebhookEndpointController::class, 'test'])
        ->middleware(['merchant.can:api_credentials.view', 'throttle:30,1'])
        ->whereNumber('endpoint');

    Route::get('rate', [RateController::class, 'show'])
        ->middleware('merchant.can:rate.view');

    Route::post('rate', [RateController::class, 'store'])
        ->middleware(['merchant.can:rate.update', EnsureMerchantApproved::class.':trading']);
});
