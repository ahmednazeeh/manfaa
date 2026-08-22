<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Webhooks\EndpointCapReachedException;
use App\Domain\Webhooks\EndpointUrlGuard;
use App\Domain\Webhooks\MerchantEndpointService;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Resources\MerchantWebhookEndpointResource;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A credential registering its OWN webhook endpoints (owner, 2026-08-22).
 *
 * This is what lets a plugin set itself up with no manual step: the store
 * owner pastes one token, the plugin calls `POST /v1/webhooks` with its
 * callback URL, and from then on it is told when the rate changes or a sale
 * is reversed. The endpoint belongs to the merchant and hears only that
 * merchant's events; it is tied to the calling credential, so revoking the
 * token in the panel switches the endpoint off with it.
 *
 * Ability `webhooks:manage`. A credential sees and removes only endpoints it
 * registered itself — never the ones the merchant typed into the panel, and
 * never another credential's.
 */
final class WebhooksController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        [$merchant, $credential] = $this->caller($request);

        $endpoints = WebhookEndpoint::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('api_credential_id', $credential->getKey())
            ->with(['deliveries' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => MerchantWebhookEndpointResource::collection($endpoints)]);
    }

    public function store(Request $request, MerchantEndpointService $endpoints): JsonResponse
    {
        [$merchant, $credential] = $this->caller($request);

        $data = $this->validateEnvelope($request, [
            'url' => ['required', 'string', 'url:https', 'max:2048', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && ($violation = EndpointUrlGuard::violation($value)) !== null) {
                    $fail($violation);
                }
            }],
            'label' => ['nullable', 'string', 'max:80'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookEvents::all())],
        ]);

        // Re-registering the same URL from the same credential replaces it
        // rather than stacking copies toward the cap: a plugin re-activated
        // or re-installed should end up with exactly one live endpoint.
        WebhookEndpoint::query()
            ->where('api_credential_id', $credential->getKey())
            ->where('url', $data['url'])
            ->delete();

        try {
            $issued = $endpoints->register(
                $merchant,
                $data['url'],
                $data['events'],
                $data['label'] ?? null,
                credential: $credential,
            );
        } catch (EndpointCapReachedException $exception) {
            return $this->error(422, 'endpoint_cap_reached', $exception->getMessage(), meta: [
                'max_active' => WebhookEndpoint::MAX_PER_MERCHANT,
            ]);
        }

        return response()->json([
            // Shown once, exactly as at the panel and the admin registry.
            'secret' => $issued->secret,
            'endpoint' => new MerchantWebhookEndpointResource($issued->endpoint),
        ], 201);
    }

    public function destroy(Request $request, int $id, MerchantEndpointService $endpoints): JsonResponse
    {
        [$merchant, $credential] = $this->caller($request);

        $endpoint = WebhookEndpoint::query()
            ->whereKey($id)
            ->where('merchant_id', $merchant->getKey())
            ->where('api_credential_id', $credential->getKey())
            ->first();

        if ($endpoint === null) {
            return $this->error(404, 'webhook_not_found', 'No webhook endpoint with that id was registered by this credential.');
        }

        $endpoints->remove($endpoint);

        return response()->json(null, 204);
    }

    /**
     * The merchant and the ApiCredential row behind the bearer token.
     * EnsureVendorCredential has already guaranteed a real Sanctum PAT.
     *
     * @return array{Merchant, ApiCredential}
     */
    private function caller(Request $request): array
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        /** @var PersonalAccessToken $token */
        $token = $merchant->currentAccessToken();

        $credential = ApiCredential::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('personal_access_token_id', $token->getKey())
            ->whereNull('revoked_at')
            ->firstOrFail();

        return [$merchant, $credential];
    }
}
