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

        return $next($request);
    }
}
