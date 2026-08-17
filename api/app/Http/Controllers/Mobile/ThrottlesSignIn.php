<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A per-ACCOUNT attempt counter for the two device sign-in endpoints.
 *
 * WHY, when the routes already carry a throttle: the route throttle keys on
 * `$request->ip()`, and an address is a weak identity for this purpose.
 * It was briefly worse than weak — `trustProxies(at: '*')` had Laravel take
 * the leftmost, client-supplied X-Forwarded-For, so one rotating header
 * bought a fresh bucket per request; that is fixed in bootstrap/app.php and
 * pinned by TrustedProxyTest. But even a truthful address is shared by a
 * whole shop behind one NAT and is cheap for an attacker to vary across a
 * botnet. A counter on the submitted IDENTITY is the control that tracks
 * the thing actually under attack: one account's password.
 *
 * THE TRADE, stated plainly: any per-account lockout is a denial-of-service
 * lever — someone who knows a cashier's email can spend wrong passwords to
 * keep that till signed out. The window is deliberately short and successes
 * clear the counter, so a legitimate operator recovers in minutes and never
 * accumulates a penalty for getting it right. Unbounded guessing against a
 * 90-day credential is the worse of the two, and this is the trade the rest
 * of the codebase already makes (OtpAuthController's per-phone limiter).
 */
trait ThrottlesSignIn
{
    /** Attempts allowed per identity before the window has to drain. */
    private const int SIGN_IN_MAX_ATTEMPTS = 10;

    /** How long the counter takes to drain, in seconds. */
    private const int SIGN_IN_DECAY_SECONDS = 900;

    /**
     * @param  string  $field  the request field the refusal is reported against
     *
     * @throws ThrottleRequestsException
     */
    protected function assertNotThrottled(string $key, string $field): void
    {
        if (! RateLimiter::tooManyAttempts($key, self::SIGN_IN_MAX_ATTEMPTS)) {
            return;
        }

        $retryAfter = RateLimiter::availableIn($key);

        // A real 429, not a ValidationException wearing one. The envelope
        // (MobileError) maps this to `rate_limited` and surfaces Retry-After
        // in the meta, so a client knows to WAIT rather than to redraw the
        // form with a field error against a password that may be correct.
        throw new ThrottleRequestsException(
            'Too many sign-in attempts.',
            null,
            ['Retry-After' => (string) $retryAfter],
        );
    }

    /**
     * Count a failed attempt. Only failures count: a busy shop signing tills
     * in all morning must never throttle itself out.
     */
    protected function recordFailedSignIn(string $key): void
    {
        RateLimiter::hit($key, self::SIGN_IN_DECAY_SECONDS);
    }

    /** A correct credential wipes the slate for that identity. */
    protected function clearSignInAttempts(string $key): void
    {
        RateLimiter::clear($key);
    }
}
