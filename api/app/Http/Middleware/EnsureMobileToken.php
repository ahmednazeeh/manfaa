<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenSubject;
use App\Http\Responses\MobileError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate on every /api/mobile/v1 route. Sibling of EnsureVendorCredential,
 * for the same reason and against the same hazard.
 *
 * SANCTUM DOES NOT CHECK TOKEN TYPE. Guard::__invoke resolves a bearer token
 * to its tokenable and calls supportsTokens(), which asks only whether that
 * class uses HasApiTokens. The trait is already on Customer, MerchantUser,
 * AdminUser and Merchant, and the guard's own $provider is never consulted on
 * the bearer path — so `auth:sanctum` alone means "some token, from someone",
 * and a customer token would otherwise reach the merchant app's routes, and a
 * POS vendor token would reach both.
 *
 * Compounding it: sanctum.guard lists admin, merchant and customer, and those
 * SESSION guards are tried first. A session user is returned carrying a
 * TransientToken, whose can() answers true for EVERY ability — so an ability
 * check alone proves nothing at all. The mobile tree is bearer-only, exactly
 * like /v1, and a browser session must not reach it.
 *
 * Four things are therefore asserted, and all four must hold:
 *
 *   1. the tokenable is EXACTLY this audience's model;
 *   2. it authenticated with a real PersonalAccessToken, not a session's
 *      TransientToken;
 *   3. that token carries this audience's ability;
 *   4. the account is still permitted to use the apps at all.
 *
 * Every failure answers the same bare 401 as an absent credential. Which of
 * the four failed is not the caller's business — a distinguishable refusal
 * for "suspended" would turn this into an account-state oracle for anyone
 * holding a stale token.
 *
 * On success the user is set on its SESSION guard. That is what lets the
 * whole existing stack run unchanged behind this: every
 * $request->user('customer'), every $request->user('merchant'),
 * EnsureMerchantPermission, EnsureMerchantApproved and the controllers all
 * resolve exactly as they do on the web. setUser() is in-memory only — it
 * writes no session, and a bearer request never had one to write.
 */
final class EnsureMobileToken
{
    public function handle(Request $request, Closure $next, string $audience): Response
    {
        $resolved = MobileAudience::tryFrom($audience);

        // An unknown audience is a typo in a route file, never a request for
        // a future app. It must be a 500 on the first request rather than a
        // gate that quietly matches nothing — the same stance
        // EnsureMerchantPermission takes on an unknown permission slug.
        if ($resolved === null) {
            throw new InvalidArgumentException("Unknown mobile audience gate [{$audience}].");
        }

        $audience = $resolved;

        $user = $request->user();
        $model = $audience->model();

        if (! $user instanceof $model) {
            return self::deny();
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return self::deny();
        }

        if (! $token->can($audience->ability())) {
            return self::deny();
        }

        if (! $user instanceof MobileTokenSubject || ! $user->mayUseMobileApp()) {
            return self::deny();
        }

        $guard = Auth::guard($audience->guard());

        $guard->setUser($user);

        // setUser() fires the Authenticated event, and TWO providers listen
        // for it and call logout() on an account they judge invalid
        // (MerchantSettingsServiceProvider, PlatformServiceProvider). Today
        // neither can fire here — the mayUseMobileApp() check above is the
        // complement of their predicates — but that is an ordering
        // dependency nothing else records, and a listener added later need
        // not be anyone's complement. If a listener ever does log this
        // request out, the controllers behind us would receive a null user
        // and die on a type error: a 500 where this middleware promises a
        // 401. Re-ask, and refuse identically to every other failure.
        if ($guard->user() === null) {
            return self::deny();
        }

        return $next($request);
    }

    /**
     * The auth-layer 401, in the mobile envelope (M2) and byte-identical to
     * what an entirely unauthenticated request receives. Which of the four
     * checks failed is never disclosed — a distinguishable refusal for
     * "suspended" would be an account-state oracle for anyone holding a
     * stale token.
     */
    private static function deny(): JsonResponse
    {
        return MobileError::response('unauthenticated', 401);
    }
}
