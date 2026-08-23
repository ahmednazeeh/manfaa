<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:n,1` with a bucket PER ROUTE instead of the framework's one
 * bucket per caller (review backlog, fixed 2026-08-23).
 *
 * Laravel's ThrottleRequests keys an inline numeric throttle on the user id
 * alone (or domain|ip for guests) — the route is not part of the key. Every
 * inline `throttle:n,1` in this API therefore shared ONE counter per
 * caller: browsing logos at 240/min burned the same bucket the credit form
 * checks at 30/min, and a guest's OTP request/verify/confirm trio drained
 * each other. The lowest ceiling on any route a caller touched was the
 * ceiling on all of them.
 *
 * This subclass scopes the signature by method|domain|uri, so each route
 * declaration gets the bucket its number promises. Two deliberate choices:
 *
 *  - The authenticated key includes the MODEL CLASS, not the bare numeric
 *    id: Customer 7 and MerchantUser 7 are different callers (the same
 *    collision the named mobile-* limiters already guard against — see
 *    AppServiceProvider).
 *  - NAMED limiters (throttle:login, throttle:map-tiles, …) never reach
 *    resolveRequestSignature — they key themselves via Limit::by() — so
 *    they are untouched by this override.
 *
 * Registered over the framework's 'throttle' alias in bootstrap/app.php;
 * no route file changes, all 70+ inline declarations inherit the fix.
 */
final class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route === null
            ? ''
            : implode('|', $route->methods()).'|'.$route->getDomain().'|'.$route->uri();

        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return sha1($user::class.'#'.$user->getAuthIdentifier().'|'.$scope);
        }

        if ($route === null) {
            return parent::resolveRequestSignature($request); // throws, same contract
        }

        return sha1('guest|'.$request->ip().'|'.$scope);
    }
}
