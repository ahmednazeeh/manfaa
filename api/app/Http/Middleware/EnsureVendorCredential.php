<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * §9.1: the /v1 vendor API is BEARER-TOKEN ONLY. Sanctum's guard checks the
 * first-party session guards (admin / merchant / customer) before the bearer
 * token, and a session-authenticated user carries a TransientToken whose
 * can() returns true for EVERY ability — so without this middleware any
 * logged-in panel or customer-web user would sail through CheckAbilities and
 * reach vendor endpoints (most sensitively GET /v1/customers/lookup) with no
 * vendor credential and no ability grant.
 *
 * A /v1 caller must therefore be a Merchant authenticated by a real personal
 * access token. Anything else — a customer session, a merchant-user session,
 * an admin session — is refused with the auth-layer 401 body, exactly as if
 * no credential had been presented (docs/openapi.yaml AuthLayerMessage).
 */
final class EnsureVendorCredential
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Merchant || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        // The token proves WHO, never that they may still trade. A store
        // that closed or was rejected keeps its Sanctum tokens — closure
        // revokes staff tokens and not the store's own — and those tokens
        // never expire. Without this an ex-merchant's credential kept
        // reading customer names and reversing transactions forever:
        // TransactionsController refused a non-trading store on its own, but
        // reverse, the rate read and the customer lookup did not, so the
        // check belongs here, once, in front of all four.
        //
        // Suspended is deliberately still allowed in: a suspended store must
        // be able to settle what it owes, and §7 leniency records its sales
        // as ineligible rather than refusing them.
        //
        // The refusal is the PUBLISHED one — 403 forbidden_ability with the
        // exact wording quoted in docs/openapi.yaml and the integration
        // guide — not a 401. The token is perfectly valid; what is missing
        // is the merchant's standing, and telling a vendor their credential
        // is bad sends them rotating a key that was never the problem.
        if (! in_array($user->status, ['active', 'suspended'], true)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'forbidden_ability',
                    'message' => 'This merchant account is not active on the platform.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
