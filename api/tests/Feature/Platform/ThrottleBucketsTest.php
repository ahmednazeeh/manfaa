<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Inline throttle:n,1 buckets are PER ROUTE (ThrottlePerRoute, 2026-08-23).
 *
 * The framework default keyed every inline throttle on the caller alone, so
 * all inline-throttled routes shared one counter: draining any one of them
 * drained them all, and the lowest ceiling a caller touched became the
 * ceiling on everything. These tests would fail under the stock middleware.
 */

// A cheap guest route at 240/min (logo fetch; 404 for a nonsense slug is
// fine — ThrottleRequests counts the HIT, not the outcome) and a guest OTP
// route at 30/min. Under a shared bucket, 31 logo fetches would 429 the OTP.
const LOGO_ROUTE = '/api/merchants/no-such-store/logo';
const OTP_ROUTE = '/api/merchant/signup/request-otp';

it('keeps every inline-throttled route in its own guest bucket', function () {
    foreach (range(1, 31) as $i) {
        $this->get(LOGO_ROUTE)->assertStatus(404);
    }

    // 31 hits elsewhere must not have touched the OTP route's 30/min budget.
    $this->postJson(OTP_ROUTE, [])->assertStatus(422);
});

it('still enforces the declared limit on the route itself', function () {
    foreach (range(1, 30) as $i) {
        $this->postJson(OTP_ROUTE, [])->assertStatus(422);
    }

    $this->postJson(OTP_ROUTE, [])->assertStatus(429);
});

it('scopes authenticated buckets by model class, never the bare id', function () {
    // Customer #N and MerchantUser #N are different callers; the stock
    // signature (bare auth identifier) merged them. Prove the signatures
    // differ for equal ids on the same route.
    $middleware = app(App\Http\Middleware\ThrottlePerRoute::class);
    $signature = new ReflectionMethod($middleware, 'resolveRequestSignature');

    $route = new Illuminate\Routing\Route(['GET'], 'api/example', fn () => null);

    $requestAs = function (object $user) use ($route) {
        $request = Illuminate\Http\Request::create('/api/example');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    };

    $customer = new App\Models\Customer;
    $customer->id = 7;
    $merchantUser = new App\Models\MerchantUser;
    $merchantUser->id = 7;

    expect($signature->invoke($middleware, $requestAs($customer)))
        ->not->toBe($signature->invoke($middleware, $requestAs($merchantUser)));
});
