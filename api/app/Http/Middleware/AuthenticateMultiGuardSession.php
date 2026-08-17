<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum's stock AuthenticateSession gates its password-hash check on the
 * DEFAULT guard's user ($request->user()) and compares against that user's
 * password, so with three disjoint session guards only admin sessions were
 * ever invalidated by a password change — merchant and customer sessions
 * survived. This override runs the identical check per configured
 * sanctum.guard entry, against that guard's own session user, and is wired
 * in through Sanctum's published middleware hook in config/sanctum.php.
 */
class AuthenticateMultiGuardSession extends AuthenticateSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guards = Collection::make(Arr::wrap(config('sanctum.guard')))
            ->mapWithKeys(fn ($name) => [$name => $this->auth->guard($name)])
            ->filter(fn ($guard) => $guard instanceof SessionGuard);

        $stale = $guards->filter(
            fn (SessionGuard $guard) => $guard->user() !== null
        )->filter(
            fn (SessionGuard $guard, string $name) => $request->session()->has('password_hash_'.$name)
        )->filter(
            fn (SessionGuard $guard, string $name) => ! $this->validatePasswordHash(
                $guard,
                $guard->user()->getAuthPassword(),
                $request->session()->get('password_hash_'.$name),
            )
        );

        // A failed pair logs out ITS guard only — never the whole session.
        // The .manfaa.app cookie is shared by three disjoint surfaces, so
        // the previous flush-and-throw meant one stale admin or customer
        // pair killed a freshly minted merchant login on the very next
        // request: log in on the panel, bounce straight back to /login
        // (2026-08-17 production incident). With the offending guard
        // cleaned up, the route's own auth middleware answers for the
        // guard it actually needs.
        $stale->each(function (SessionGuard $guard, string $name) use ($request): void {
            $userId = $guard->id();
            $guard->logoutCurrentDevice();
            $request->session()->forget('password_hash_'.$name);

            Log::info("Session guard {$name} logged out: stored password hash no longer validates.", [
                'user_id' => $userId,
            ]);
        });

        return tap($next($request), function () use ($request, $guards) {
            // Post-response, so a login on this very request stores its hash
            // immediately — for every guard holding a user, not just the first.
            $guards
                ->filter(fn (SessionGuard $guard) => $guard->hasUser())
                ->keys()
                ->each(fn (string $name) => $this->storePasswordHashInSession($request, $name));
        });
    }
}
