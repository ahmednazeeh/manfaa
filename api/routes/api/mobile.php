<?php

declare(strict_types=1);

use App\Http\Controllers\Devices\CustomerDevicesController;
use App\Http\Controllers\Devices\CustomerPushTokenController;
use App\Http\Controllers\Devices\MerchantDevicesController;
use App\Http\Controllers\Devices\MerchantPushTokenController;
use App\Http\Controllers\Mobile\ConfigController;
use App\Http\Controllers\Mobile\CreditController;
use App\Http\Controllers\Mobile\CustomerOtpController;
use App\Http\Controllers\Mobile\CustomerTokenController;
use App\Http\Controllers\Mobile\HomeController;
use App\Http\Controllers\Mobile\MerchantTokenController;
use App\Http\Controllers\Mobile\PayoutAccountController;
use App\Http\Controllers\Mobile\PayoutsController;
use App\Http\Controllers\Mobile\SessionController;
use App\Http\Controllers\Mobile\TransactionsController;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\NormalisesMobileErrors;
use Illuminate\Support\Facades\Route;

/**
 * The mobile API (PLAN-mobile-api.md). Serves the two Flutter apps and
 * nothing else.
 *
 * WHY A SEPARATE TREE, and not versioned copies of the panel routes: an
 * installed app three months old still calls these exact paths, so they must
 * be freezable — while /api/customer/* and /api/merchant/* deploy in lockstep
 * with the panels and must stay free to change. The shapes differ too: mobile
 * wants aggregates and cursors, the panels want per-resource reads and page
 * numbers.
 *
 * WHAT IS NOT DUPLICATED: any business rule. Controllers here are thin and
 * call the same domain services as the web tree — BalanceQuery, CreditRecorder,
 * SettlementBuilder. Two HTTP surfaces, one domain.
 *
 * EVERY authenticated route carries BOTH `auth:sanctum` and `mobile.token`.
 * auth:sanctum resolves the bearer token; mobile.token is what proves it is
 * the RIGHT KIND of token — see EnsureMobileToken, and do not add a route
 * here that skips it.
 */
Route::prefix('mobile/v1')
    // OUTERMOST on the tree: guarantees one error shape leaves it, including
    // for refusals that middleware RETURNS rather than throws (M2).
    ->middleware(NormalisesMobileErrors::class)
    ->group(function (): void {

        // Read on launch, before anyone signs in — the version gate has to reach
        // a build too old to authenticate. Conditional (ETag): unchanged config
        // costs a 304, not a payload.
        Route::get('config', ConfigController::class)->middleware('throttle:60,1,mobile-config');

        /*
         * Sign-in. Unauthenticated by definition, and throttled at the same 5/min
         * as the web logins — the tighter of the two limits a credential-stuffing
         * attempt would meet.
         */
        // The third throttle argument is a KEY PREFIX. Without one, Laravel keys
        // an unnamed throttle on sha1(domain|ip) alone — no route, no method —
        // so every unnamed throttle in this repo shares ONE counter per IP, and
        // a visitor whose browser just painted a storefront (logos.php allows
        // 240/min) would arrive here with the bucket already spent and be
        // refused sign-in at 5. Each endpoint gets its own bucket.
        Route::post('customer/auth/token', [CustomerTokenController::class, 'store'])
            ->middleware('throttle:5,1,mobile-customer-signin');

        /*
         * Passwordless OTP sign-in/signup (R1) — the customer app's real
         * flow; the password endpoint above remains for accounts that hold
         * one. request carries its own dual limiter (3/h per phone + 10/h
         * per IP, SHARED with the web signup so the SMS budget cannot be
         * doubled by alternating surfaces); these route throttles are the
         * coarse backstop. The OTP attempt cap itself lives in OtpService,
         * inside a row lock.
         */
        Route::post('customer/auth/otp/request', [CustomerOtpController::class, 'request'])
            ->middleware('throttle:30,1,mobile-otp-request');
        Route::post('customer/auth/otp/verify', [CustomerOtpController::class, 'verify'])
            ->middleware('throttle:10,1,mobile-otp-verify');
        Route::post('customer/auth/register', [CustomerOtpController::class, 'register'])
            ->middleware('throttle:10,1,mobile-otp-register');

        Route::post('merchant/auth/token', [MerchantTokenController::class, 'store'])
            ->middleware('throttle:5,1,mobile-merchant-signin');

        /*
         * Customer app.
         */
        Route::middleware(['auth:sanctum', 'mobile.token:customer'])
            ->prefix('customer')
            ->group(function (): void {
                Route::delete('auth/token', [CustomerTokenController::class, 'destroy']);
                Route::delete('auth/tokens', [CustomerTokenController::class, 'destroyAll']);

                // Fresh identity on launch/resume.
                Route::get('me', [SessionController::class, 'customer']);

                // The whole first screen in ONE request, conditional so a
                // resume with nothing changed costs a 304 (M3).
                Route::get('home', [HomeController::class, 'customer']);

                // Cursor-paged: this list grows at the TOP, and offset paging
                // duplicates and skips rows while sales are landing.
                Route::get('transactions', [TransactionsController::class, 'customer']);

                // Payout history (R3): the money that reached the bank, and
                // what each transfer covered. Same rules as the web —
                // pending excluded, failed included, own rows only.
                Route::get('payouts', [PayoutsController::class, 'index']);
                Route::get('payouts/{id}', [PayoutsController::class, 'show'])->whereNumber('id');

                // Payout account (R5). Reading and clearing move no money to a
                // new destination and carry no gate; CHANGING the bank the
                // platform pays to demands a fresh code to the number on file
                // (the SIM-swap / stolen-token mitigation). The otp request
                // carries its own shared SMS budget; the route throttle is a
                // coarse backstop.
                Route::get('payout-account', [PayoutAccountController::class, 'show']);
                Route::post('payout-account/otp', [PayoutAccountController::class, 'requestOtp'])
                    ->middleware('throttle:10,1,mobile-payout-otp');
                Route::put('payout-account', [PayoutAccountController::class, 'update'])
                    ->middleware('throttle:10,1,mobile-payout-update');

                // Also mounted on the WEBSITE (routes/api/customer.php). See the
                // DevicesController docblock: a device list reachable only from a
                // live token cannot cut off the phone that was lost.
                Route::get('devices', [CustomerDevicesController::class, 'index']);
                Route::delete('devices/{id}', [CustomerDevicesController::class, 'destroy'])->whereNumber('id');
                Route::delete('devices', [CustomerDevicesController::class, 'destroyAll']);

                // Push registration. Bound to THIS auth token, so it dies
                // with it — see PushTokenController (M4).
                Route::put('push-token', [CustomerPushTokenController::class, 'update']);
                Route::delete('push-token', [CustomerPushTokenController::class, 'destroy']);
            });

        /*
         * Merchant app.
         */
        Route::middleware(['auth:sanctum', 'mobile.token:merchant'])
            ->prefix('merchant')
            ->group(function (): void {
                Route::delete('auth/token', [MerchantTokenController::class, 'destroy']);
                Route::delete('auth/tokens', [MerchantTokenController::class, 'destroyAll']);

                // Fresh identity AND fresh permissions. Without this the till's
                // navigation was frozen for the 90-day life of the token.
                Route::get('me', [SessionController::class, 'merchant']);

                // Home stays open to every till — today's takings and the
                // store's trading status are what a cashier is there for —
                // but the two settlement-gated blocks inside it are withheld
                // unless the account may see them (see HomeController).
                Route::get('home', [HomeController::class, 'merchant']);

                // The SAME gate the panel applies (routes/api/merchant-panel.php:13).
                // Dropping it here would hand a credits-only cashier the full
                // history: invoice numbers, frozen rates, fees and GST.
                Route::get('transactions', [TransactionsController::class, 'merchant'])
                    ->middleware('merchant.can:transactions.view');

                /*
                 * The till's whole reason to be signed in.
                 *
                 * Idempotency-Key REQUIRED: a till that loses signal queues
                 * its sales and drains them on reconnect, and a request that
                 * timed out after the server committed must replay the
                 * original response rather than book a second sale.
                 *
                 * 60/min rather than the panel's 30 — a draining offline
                 * queue is a burst by definition, and a human at a till is
                 * not. Still bounded: manual entry is the exposed fraud
                 * surface (§11).
                 *
                 * A NAMED limiter (AppServiceProvider), not `throttle:60,1`.
                 * The inline form keys on sha1(getAuthIdentifier()) with no
                 * model discriminator, and ThrottleRequests sorts ABOVE
                 * EnsureMobileToken in the middleware priority — so a
                 * customer token spending 60 rejected requests here would
                 * lock out the merchant user who happens to share its
                 * numeric id at an unrelated store.
                 */
                Route::post('credits', [CreditController::class, 'store'])
                    ->middleware([
                        'merchant.can:credits.create',
                        IdempotencyMiddleware::class,
                        'throttle:mobile-credits',
                    ]);

                Route::get('devices', [MerchantDevicesController::class, 'index']);
                Route::delete('devices/{id}', [MerchantDevicesController::class, 'destroy'])->whereNumber('id');
                Route::delete('devices', [MerchantDevicesController::class, 'destroyAll']);

                Route::put('push-token', [MerchantPushTokenController::class, 'update']);
                Route::delete('push-token', [MerchantPushTokenController::class, 'destroy']);
            });
    });
