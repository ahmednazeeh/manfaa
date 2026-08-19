<?php

use App\Http\Controllers\Admin\ClaimsController as AdminClaimsController;
use App\Http\Controllers\Customer\AccountDeletionController;
use App\Http\Controllers\Customer\AvatarController;
use App\Http\Controllers\Customer\BalanceController;
use App\Http\Controllers\Customer\ClaimsController;
use App\Http\Controllers\Customer\DiscoveryController;
use App\Http\Controllers\Customer\OtpAuthController;
use App\Http\Controllers\Customer\PayoutAccountController;
use App\Http\Controllers\Customer\PayoutsController;
use App\Http\Controllers\Customer\TransactionsController;
use App\Http\Controllers\CustomerAvatarController;
use App\Http\Controllers\Devices\CustomerDevicesController;
use App\Http\Controllers\ThemeController;
use Illuminate\Support\Facades\Route;

// Customer platform (§10 apps/web, §12 Phase 3): OTP signup, balance,
// history, payout account, claims — plus the admin claims queue and the
// public discovery feed. The existing password login in routes/api.php is
// untouched.

Route::prefix('customer/auth')->group(function () {
    // request-otp carries its own dual limiter (3/h per phone + 10/h per IP)
    // inside the controller; this route throttle is a coarse backstop.
    Route::post('request-otp', [OtpAuthController::class, 'requestOtp'])->middleware('throttle:30,1');
    Route::post('verify-otp', [OtpAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    // Passwordless sign-in for the web (owner decision 2026-08-18): one
    // code either signs a known number in or hands back a signup token.
    Route::post('otp/verify', [OtpAuthController::class, 'verifyAccess'])->middleware('throttle:10,1');
    Route::post('register', [OtpAuthController::class, 'register'])->middleware('throttle:10,1');
});

// Self-service account deletion (store-readiness 2026-08-17): public on
// purpose — the person deleting may have lost the app; possession of the
// registered phone, proven by OTP, is the credential. Same limiter budget
// as sign-up inside the controller; these throttles are the backstop.
Route::prefix('customer/account-deletion')->group(function () {
    Route::post('request-otp', [AccountDeletionController::class, 'requestOtp'])->middleware('throttle:30,1');
    Route::post('verify', [AccountDeletionController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('confirm', [AccountDeletionController::class, 'confirm'])->middleware('throttle:10,1');
});

Route::prefix('customer')->middleware('auth:customer')->group(function () {
    Route::get('balance', [BalanceController::class, 'show']);
    Route::get('transactions', [TransactionsController::class, 'index']);

    // The payout account carries the app's fresh-OTP gate on the WEB too
    // (2026-08-17): a session cookie is not proof of the phone. Keyed
    // throttles for the same reason the avatar routes carry them.
    Route::get('payout-account', [PayoutAccountController::class, 'show']);
    Route::post('payout-account/otp', [PayoutAccountController::class, 'requestOtp'])
        ->middleware('throttle:10,1,customer-payout-otp');
    Route::post('payout-account', [PayoutAccountController::class, 'store'])
        ->middleware('throttle:10,1,customer-payout-update');

    // Profile picture — set/replace and remove. The same two actions are
    // mounted on the app in routes/api/mobile.php; reading the picture is
    // the public capability URL at the bottom of this file. Throttled with
    // its OWN key (see the keyed-throttle note in mobile.php): an unnamed
    // throttle shares one per-IP bucket with every other unnamed throttle.
    Route::post('avatar', [AvatarController::class, 'store'])
        ->middleware('throttle:10,1,customer-avatar-upload');
    Route::delete('avatar', [AvatarController::class, 'destroy'])
        ->middleware('throttle:10,1,customer-avatar-remove');

    // Signed-in app devices, reachable from the WEBSITE with nothing but the
    // session — because the phone is what went missing. The same three
    // actions are mounted on the app itself in routes/api/mobile.php.
    Route::get('devices', [CustomerDevicesController::class, 'index']);
    Route::delete('devices/{id}', [CustomerDevicesController::class, 'destroy'])->whereNumber('id');
    Route::delete('devices', [CustomerDevicesController::class, 'destroyAll']);

    // The money that actually reached the bank, and what each payment
    // covered. Scoped to the authenticated customer inside the controller.
    Route::get('payouts', [PayoutsController::class, 'index']);
    Route::get('payouts/{id}', [PayoutsController::class, 'show'])->whereNumber('id');

    // Self-serve claims are feature-flagged OFF (config/features.php):
    // customers contact the merchant, who credits the missed sale manually.
    if (config('features.customer_claims')) {
        Route::get('claims', [ClaimsController::class, 'index']);
        Route::post('claims', [ClaimsController::class, 'store'])->middleware('throttle:10,1');
    }
});

if (config('features.customer_claims')) {
    Route::prefix('admin')->middleware('auth:admin')->group(function () {
        Route::get('claims', [AdminClaimsController::class, 'index']);
        Route::post('claims/{id}/approve', [AdminClaimsController::class, 'approve'])->whereNumber('id');
        Route::post('claims/{id}/reject', [AdminClaimsController::class, 'reject'])->whereNumber('id');
    });
}

// PUBLIC — no auth, throttled per IP (named `discovery` limiter: 60/min,
// with an internal-token exemption for the Next SSR origin — see
// AppServiceProvider), dataset cached 60s in the service.
Route::get('discover', [DiscoveryController::class, 'index'])->middleware('throttle:discovery');
Route::get('discover/zones', [DiscoveryController::class, 'zones'])->middleware('throttle:discovery');

// The admin-chosen storefront accent — public, tiny, ETagged; null until a
// superadmin picks a colour.
Route::get('theme', [ThemeController::class, 'show'])
    ->middleware('throttle:discovery');

// Storefront: paginated directory + per-slug store page. Same cache and
// throttle discipline; the slug pattern is constrained so junk never reaches
// the handler as a route match.
Route::get('discover/merchants', [DiscoveryController::class, 'directory'])->middleware('throttle:discovery');
Route::get('discover/merchants/{slug}', [DiscoveryController::class, 'show'])
    ->where('slug', '[a-z0-9-]{1,80}')
    ->middleware('throttle:discovery');

// Customer profile pictures. No auth middleware ON PURPOSE: the uuid
// filename segment is the authorisation — minted at upload, told only to
// the account's own clients, dead on replace/remove. That is what lets one
// URL render as a plain <img> on the website AND through the app's image
// loader, which sends no bearer token. See CustomerAvatarController.
// Throttle mirrors logos.php: generous, per-IP, keyed to its own bucket;
// the response is immutable-cacheable so a real client fetches it once.
Route::get('customers/{id}/avatar/{file}', CustomerAvatarController::class)
    ->whereNumber('id')
    ->where('file', '[0-9a-f-]{36}\.(?:jpg|png|webp)')
    ->middleware('throttle:240,1,customer-avatar');
