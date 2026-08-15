<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The merchant panel's role gate, parameterised by the MINIMUM tier a route
 * requires (PLAN §1 staff roles):
 *
 *   middleware('merchant.role:owner')    owner only — bank account, staff
 *                                        management, preferences, profile
 *                                        writes, logo, API credentials, the
 *                                        setup wizard.
 *   middleware('merchant.role:manager')  manager or owner — rate changes,
 *                                        promotions, settlement mutations,
 *                                        branches, product categories.
 *
 * Routes with no gate stay open to every authenticated merchant user: the
 * credit screen, the customer lookup and the read models are staff work.
 *
 * Replaces the old EnsureMerchantOwner one-off. The 403 keeps a
 * machine-readable `code` — `owner_required` / `manager_required` — so the
 * panel can tell "your role" from any other refusal and name the tier the
 * user would need.
 *
 * Runs behind auth:merchant.
 */
class EnsureMerchantRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $minimum = 'owner'): Response
    {
        if (! in_array($minimum, MerchantUser::ROLES, true)) {
            throw new InvalidArgumentException("Unknown merchant role gate [{$minimum}].");
        }

        $user = $request->user('merchant');

        if (! $user instanceof MerchantUser || ! $user->hasRoleAtLeast($minimum)) {
            return new JsonResponse([
                'message' => $minimum === 'owner'
                    ? 'Merchant owner access required.'
                    : 'Merchant manager access required.',
                'code' => $minimum.'_required',
            ], 403);
        }

        return $next($request);
    }
}
