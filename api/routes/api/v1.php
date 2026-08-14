<?php

use App\Http\Controllers\V1\CustomerLookupController;
use App\Http\Controllers\V1\MerchantRateController;
use App\Http\Controllers\V1\TransactionsController;
use App\Http\Middleware\EnsureVendorCredential;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
 * §9.2 vendor-facing API. Auth is a per-merchant Sanctum personal access
 * token; every operation requires its declared ability (docs/openapi.yaml,
 * x-required-ability), enforced by Sanctum's CheckAbilities middleware —
 * referenced by class so this file stays self-contained. Writes carry the
 * mandatory Idempotency-Key (IdempotencyMiddleware). Throttle: 120/min
 * PER TOKEN — keyed on the personal access token id, so one credential of
 * a merchant flooding never starves the merchant's other credentials.
 */
RateLimiter::for('vendor-api', function (Request $request) {
    $token = $request->user()?->currentAccessToken();

    return Limit::perMinute(120)->by(
        $token !== null && method_exists($token, 'getKey')
            ? 'v1-token:'.$token->getKey()
            : 'v1-ip:'.$request->ip(),
    );
});

// EnsureVendorCredential sits directly after auth:sanctum: Sanctum resolves
// first-party SESSIONS before bearer tokens and hands them a TransientToken
// that passes every ability check, so /v1 must additionally insist on a real
// per-merchant personal access token — vendors only, never a panel session.
Route::prefix('v1')->middleware(['auth:sanctum', EnsureVendorCredential::class, 'throttle:vendor-api'])->group(function () {
    Route::post('transactions', [TransactionsController::class, 'store'])
        ->middleware([CheckAbilities::class.':transactions:write', IdempotencyMiddleware::class]);

    Route::post('transactions/{id}/reverse', [TransactionsController::class, 'reverse'])
        ->whereNumber('id')
        ->middleware([CheckAbilities::class.':transactions:reverse', IdempotencyMiddleware::class]);

    Route::get('merchants/me/rate', MerchantRateController::class)
        ->middleware(CheckAbilities::class.':rates:read');

    Route::get('customers/lookup', CustomerLookupController::class)
        ->middleware(CheckAbilities::class.':customers:lookup');
});
