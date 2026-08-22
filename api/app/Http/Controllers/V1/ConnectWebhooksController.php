<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Connect\ConnectException;
use App\Domain\Connect\ConnectService;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\PosVendor;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A PLATFORM reads its own webhook endpoints (owner, 2026-08-22).
 *
 * The endpoint that hears every merchant on the platform is registered by
 * a Manfaa superadmin — that stays a registry privilege. What a vendor
 * needs is to SEE it when they have forgotten what was registered: the
 * URL, the events, whether it is live, the last delivery — never the
 * secret, which was shown once. Authenticated as the platform itself with
 * its client credentials (HTTP Basic, `client_id:client_secret`), because
 * the platform is acting for itself, not for any merchant.
 *
 * A platform that wants per-merchant endpoints instead registers them
 * with each merchant's token over `/v1/webhooks` (ability
 * `webhooks:manage`, requested in the connect scope).
 */
final class ConnectWebhooksController extends V1Controller
{
    public function index(Request $request, ConnectService $connect): JsonResponse
    {
        $vendor = $this->platform($request, $connect);

        if ($vendor instanceof JsonResponse) {
            return $vendor;
        }

        $endpoints = WebhookEndpoint::query()
            ->where('pos_vendor_id', $vendor->getKey())
            ->with(['deliveries' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderBy('id')
            ->get();

        return new JsonResponse(['data' => WebhookEndpointResource::collection($endpoints)]);
    }

    /**
     * The platform behind `Authorization: Basic base64(client_id:client_secret)`.
     * Refusals use the OAuth error shape the token endpoint uses, so one
     * client library handles both.
     */
    private function platform(Request $request, ConnectService $connect): PosVendor|JsonResponse
    {
        $clientId = (string) $request->getUser();
        $clientSecret = (string) $request->getPassword();

        if ($clientId === '' || $clientSecret === '') {
            return new JsonResponse([
                'error' => 'invalid_client',
                'error_description' => 'Authenticate with HTTP Basic: your client_id as the username and client_secret as the password.',
            ], 401, ['WWW-Authenticate' => 'Basic realm="Manfaa platform"']);
        }

        try {
            $vendor = $connect->client($clientId);
        } catch (ConnectException $e) {
            return new JsonResponse(['error' => $e->errorCode, 'error_description' => $e->getMessage()], 401);
        }

        if ($vendor->isPublicClient()) {
            return new JsonResponse([
                'error' => 'invalid_client',
                'error_description' => 'This is a public client. It registers webhooks per store with POST /v1/webhooks, using that store\'s token.',
            ], 401);
        }

        if (! Hash::check($clientSecret, (string) $vendor->client_secret_hash)) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => 'That application could not be authenticated.'], 401);
        }

        return $vendor;
    }
}
