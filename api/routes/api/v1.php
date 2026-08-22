<?php

use App\Http\Controllers\V1\CustomerLookupController;
use App\Http\Controllers\V1\MeController;
use App\Http\Controllers\V1\MerchantRateController;
use App\Http\Controllers\V1\TransactionsController;
use App\Http\Controllers\V1\WebhooksController;
use App\Http\Middleware\EnsureVendorCredential;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

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

    // Amend a pending sale (owner, 2026-08-22): the partial-refund path.
    // Same ability as recording — the caller is correcting its own figure.
    Route::patch('transactions/{id}', [TransactionsController::class, 'amend'])
        ->whereNumber('id')
        ->middleware([CheckAbilities::class.':transactions:write', IdempotencyMiddleware::class]);

    // Read one sale back (owner, 2026-08-22): what a plugin adopts after
    // `409 duplicate_invoice`, and what its "Refresh status" polls. Either
    // writing ability reads — the one who posts, or the one who reverses.
    Route::get('transactions/{id}', [TransactionsController::class, 'show'])
        ->whereNumber('id')
        ->middleware(CheckForAnyAbility::class.':transactions:write,transactions:reverse');

    // Who am I: the token's own abilities and store (owner, 2026-08-22).
    // No ability gate — a live credential may always read what it is.
    Route::get('me', MeController::class);

    Route::get('merchants/me/rate', MerchantRateController::class)
        ->middleware(CheckAbilities::class.':rates:read');

    Route::get('customers/lookup', CustomerLookupController::class)
        ->middleware(CheckAbilities::class.':customers:lookup');

    // A credential's own webhook endpoints (owner, 2026-08-22): the
    // no-manual-setup path for plugins. Scoped to the calling credential.
    Route::get('webhooks', [WebhooksController::class, 'index'])
        ->middleware(CheckAbilities::class.':webhooks:manage');
    Route::post('webhooks', [WebhooksController::class, 'store'])
        ->middleware(CheckAbilities::class.':webhooks:manage');
    Route::delete('webhooks/{id}', [WebhooksController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware(CheckAbilities::class.':webhooks:manage');
});
