<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Connect\ConnectException;
use App\Domain\Connect\ConnectService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's side: swap the one-time code for the token.
 *
 * Server to server, authenticated by the client secret — the browser that
 * carried the code never sees what it becomes. UNAUTHENTICATED as far as
 * Manfaa's guards are concerned, because the caller has no token yet; the
 * client secret and the PKCE verifier are the proof. A PUBLIC client (a
 * plugin on the merchant's own server) has no secret: PKCE alone is its
 * proof, and sending a secret is refused.
 *
 * The token that comes back does not expire (owner decision). It ends when
 * the merchant revokes it, when the platform's secret is rotated, or when
 * the shop closes.
 */
final class ConnectTokenController extends Controller
{
    public function __construct(private readonly ConnectService $connect) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // RFC 6749 spells this `grant_type`; accepted for the sake of
            // libraries that always send it, and required to be the only
            // flow we run.
            'grant_type' => ['required', 'in:authorization_code'],
            'client_id' => ['required', 'string', 'max:64'],
            // Required for a confidential platform, FORBIDDEN for a public
            // client (a plugin that thinks it has a secret is misconfigured)
            // — both decided in ConnectService, which knows the client.
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:128'],
            'redirect_uri' => ['required', 'string', 'max:255'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
        ]);

        try {
            $issued = $this->connect->exchange(
                $validated['client_id'],
                $validated['client_secret'] ?? null,
                $validated['code'],
                $validated['redirect_uri'],
                $validated['code_verifier'],
            );
        } catch (ConnectException $e) {
            // RFC 6749 §5.2 shape, so an off-the-shelf OAuth client reads
            // it without special-casing Manfaa.
            return new JsonResponse([
                'error' => $e->errorCode,
                'error_description' => $e->getMessage(),
            ], $e->errorCode === 'invalid_client' ? 401 : 400);
        }

        return new JsonResponse([
            'access_token' => $issued->plainTextToken,
            'token_type' => 'Bearer',
            // No `expires_in` and no refresh token, deliberately: this
            // grant lasts until somebody ends it. Saying `expires_in: null`
            // would invite a client to schedule a refresh that will never
            // be needed.
            'scope' => implode(' ', (array) $issued->credential->abilities),
            'merchant' => [
                'id' => $issued->credential->merchant_id,
                'name' => $issued->credential->merchant?->name,
            ],
        ], 201);
    }
}
