<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The retired three-tier role gate (`merchant.role:owner|manager`),
 * replaced by EnsureMerchantPermission (PLAN §13b staff permissions).
 *
 * It survives only as a tripwire, and stays registered under its old alias
 * for the same reason: a route file the refactor missed would otherwise
 * resolve `merchant.role` to nothing at all and serve the route ungated —
 * silently opening the surface the gate existed to close. Refusing to run
 * turns that into a 500 on the first request.
 *
 * Delete once no route names it.
 */
class EnsureMerchantRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $minimum = 'owner'): Response
    {
        throw new LogicException(
            "The merchant.role:{$minimum} gate is retired — use merchant.can:<permission> (App\\Domain\\MerchantAccess\\Permission)."
        );
    }
}
