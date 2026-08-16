<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\MerchantAccess\Permission;
use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The merchant panel's permission gate, parameterised by the ONE permission
 * a route needs (PLAN §13b staff permissions):
 *
 *   middleware('merchant.can:settlements.create')
 *
 * The argument is required and has no default. Its predecessor defaulted to
 * the strictest tier, which meant a gate written without an argument still
 * gated something — a permission catalogue has no strictest member, so
 * there is nothing safe to fall back to and a bare `merchant.can` must be a
 * programming error, not a guess.
 *
 * A slug outside the catalogue throws, exactly as the tier gate threw on an
 * unknown tier. That property is the whole defence against a typo: a
 * misspelt gate has to be a 500 on the first request, never a route that
 * quietly matches nothing and lets everyone through.
 *
 * The 403 names what is missing — `permission_required` plus the slug in
 * `permission` — so the panel can say which permission the user would need
 * rather than "forbidden".
 *
 * Runs behind auth:merchant, and BEFORE EnsureMerchantApproved wherever
 * both apply: permission answers who, approval answers whether the store
 * may trade at all, and a user who may not act must not learn the store's
 * commercial standing from the refusal.
 */
class EnsureMerchantPermission
{
    public const string CODE = 'permission_required';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $required = Permission::tryFrom($permission);

        if ($required === null) {
            throw new InvalidArgumentException("Unknown merchant permission gate [{$permission}].");
        }

        $user = $request->user('merchant');

        if (! $user instanceof MerchantUser || ! $user->can($required)) {
            return self::deny($required);
        }

        return $next($request);
    }

    /**
     * The refusal, in one place. A handful of checks are finer than a route
     * — a single FIELD on the credit form, say — and the panel must not
     * have to tell those apart from the route gate's answer.
     */
    public static function deny(Permission $permission): JsonResponse
    {
        return new JsonResponse([
            'message' => sprintf('You do not have permission to %s.', lcfirst($permission->label())),
            'code' => self::CODE,
            'permission' => $permission->value,
        ], 403);
    }
}
