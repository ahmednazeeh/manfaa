<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the owner-only subset of merchant panel routes: settings (profile,
 * bank account, branches, staff, preferences) plus the pre-existing
 * owner-only actions (rate change, promotions, settlement creation,
 * settlement submission, wallet settle). Runs behind auth:merchant. Staff
 * keep the operational surface — reads, manual credits, customer lookup.
 *
 * Machine-readable `code: owner_required` so the panel can distinguish
 * "your role" from any other 403.
 */
class EnsureMerchantOwner
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('merchant');

        if (! $user instanceof MerchantUser || $user->role !== 'owner') {
            return new JsonResponse([
                'message' => 'Merchant owner access required.',
                'code' => 'owner_required',
            ], 403);
        }

        return $next($request);
    }
}
