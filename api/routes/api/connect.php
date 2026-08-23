<?php

use App\Http\Controllers\Admin\PlatformClientController;
use App\Http\Controllers\Merchant\ConnectConsentController;
use App\Http\Controllers\V1\ConnectTokenController;
use App\Http\Controllers\V1\ConnectWaiversController;
use App\Http\Controllers\V1\ConnectWebhooksController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

/*
 * PLATFORM CONNECT — "IsleBooks would like to … Authorise / Deny".
 *
 * Three doors, three audiences:
 *
 *  1. A SUPERADMIN registers the platform. This is the gate the whole
 *     design rests on: holding a client credential means being able to put
 *     a consent screen in front of any shopkeeper on Manfaa. A developer
 *     without one is not blocked — they use the per-merchant key, which the
 *     merchant issues themselves and which reaches exactly one shop.
 *
 *  2. A MERCHANT reads what is being asked and answers it.
 *
 *  3. The PLATFORM swaps the resulting code for a token, server to server.
 */

Route::prefix('admin/platform-clients')
    ->middleware(['auth:admin', EnsureSuperadmin::class])
    ->group(function (): void {
        Route::get('/', [PlatformClientController::class, 'index']);
        Route::post('/', [PlatformClientController::class, 'store']);
        Route::patch('{vendor}', [PlatformClientController::class, 'update'])->whereNumber('vendor');
        // Rotating cuts every token the old secret produced — see the
        // controller for why that is the only honest behaviour.
        Route::post('{vendor}/rotate', [PlatformClientController::class, 'rotate'])->whereNumber('vendor');
    });

Route::prefix('merchant/connect')
    ->middleware('auth:merchant')
    ->group(function (): void {
        // Reading the question is a view.
        Route::get('authorize', [ConnectConsentController::class, 'show'])
            ->middleware('merchant.can:api_credentials.view');

        // ANSWERING it mints a credential, so it needs the permission that
        // issuing one needs — pressing Authorise is issuing a key, just
        // without anybody seeing it.
        Route::post('authorize', [ConnectConsentController::class, 'approve'])
            ->middleware([
                'merchant.can:api_credentials.create',
                EnsureMerchantApproved::class,
                'throttle:30,1',
            ]);

        Route::post('deny', [ConnectConsentController::class, 'deny'])
            ->middleware(['merchant.can:api_credentials.view', 'throttle:30,1']);
    });

/*
 * Unauthenticated by design: the caller has no token yet. The client secret
 * and the PKCE verifier are the proof, and the throttle is what stops the
 * code space being guessed at.
 */
Route::post('v1/connect/token', ConnectTokenController::class)
    ->middleware('throttle:60,1');

/*
 * A platform READS the webhook endpoint Manfaa registered for it (owner,
 * 2026-08-22) — for when it has forgotten the URL or the events.
 * Authenticated with its client credentials over HTTP Basic: the platform
 * is acting for itself, not for any merchant. Registering stays with the
 * superadmin registry; per-merchant endpoints are /v1/webhooks.
 */
Route::get('v1/connect/webhooks', [ConnectWebhooksController::class, 'index'])
    ->middleware('throttle:30,1');

/*
 * A platform reads which of its merchants earned the month's POS-fee
 * waiver (owner, 2026-08-23) — IsleBooks' invoice job, once a month.
 * Same client-credential Basic auth as the webhook read-back.
 */
Route::get('v1/connect/waivers', [ConnectWaiversController::class, 'index'])
    ->middleware('throttle:30,1');
