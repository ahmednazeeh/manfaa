<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Webhooks\EndpointCapReachedException;
use App\Domain\Webhooks\EndpointUrlGuard;
use App\Domain\Webhooks\MerchantEndpointService;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantWebhookEndpointResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * A merchant's own webhook endpoints, from Settings › API access (owner,
 * 2026-08-22). The mirror of the admin per-vendor registry, with the same
 * URL guard and the same once-only secret, for a store that wants to be told
 * about its own events — a custom shop, an ERP, anything that is not a POS
 * platform we onboarded by hand.
 *
 * Permissions follow the credentials they sit beside: `api_credentials.view`
 * to see them, `.create` to add (owner by preset, approved store only),
 * `.revoke` to remove.
 */
final class WebhookEndpointController extends Controller
{
    /** Tests are cheap for us and noisy for the receiver; a burst is a bug. */
    private const int TESTS_PER_MINUTE = 6;

    public function index(Request $request, MerchantEndpointService $endpoints): AnonymousResourceCollection
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return MerchantWebhookEndpointResource::collection($endpoints->list($user->merchant));
    }

    public function store(Request $request, MerchantEndpointService $endpoints): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');
        $merchant = $user->merchant;

        // Same stance as credentials: a suspended store keeps what it has
        // but cannot point new things at us.
        if (! $merchant instanceof Merchant || $merchant->status !== 'active') {
            return response()->json([
                'message' => sprintf(
                    'Your store is %s, so new webhook endpoints cannot be added — removing existing ones still works.',
                    Merchant::statusLabel($merchant?->status),
                ),
                'code' => 'store_not_trading',
            ], 409);
        }

        $validated = $request->validate([
            // https-only, public hosts only: the queue worker POSTs wherever
            // this row points, so a private-range URL would turn deliveries
            // into SSRF probes. Identical rule to the admin registry.
            'url' => ['required', 'string', 'url:https', 'max:2048', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && ($violation = EndpointUrlGuard::violation($value)) !== null) {
                    $fail($violation);
                }
            }],
            'label' => ['nullable', 'string', 'max:80'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookEvents::all())],
        ]);

        try {
            $issued = $endpoints->register(
                $merchant,
                $validated['url'],
                $validated['events'],
                $validated['label'] ?? null,
                by: $user,
            );
        } catch (EndpointCapReachedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'endpoint_cap_reached',
            ], 422);
        }

        return response()->json([
            // Shown once. Only the encrypted form is stored; this string is
            // what the receiver verifies X-Manfaa-Signature with.
            'secret' => $issued->secret,
            'endpoint' => new MerchantWebhookEndpointResource($issued->endpoint),
        ], 201);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint, MerchantEndpointService $endpoints): JsonResponse
    {
        $this->own($request, $endpoint);

        $endpoints->remove($endpoint);

        return response()->json(null, 204);
    }

    /** Queue one `webhook.test` delivery so the merchant can prove the URL. */
    public function test(Request $request, WebhookEndpoint $endpoint, MerchantEndpointService $endpoints): JsonResponse
    {
        $this->own($request, $endpoint);

        $limiterKey = 'merchant-webhook-test:'.$endpoint->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::TESTS_PER_MINUTE)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'message' => 'Too many test deliveries. Try again shortly.',
                'code' => 'test_rate_limited',
                'retry_after_seconds' => $retryAfter,
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        RateLimiter::hit($limiterKey, 60);

        $delivery = $endpoints->sendTest($endpoint);

        return response()->json([
            'delivery' => [
                'id' => $delivery->getKey(),
                'event' => $delivery->event,
                'status' => $delivery->status,
            ],
        ], 202);
    }

    /** Merchant-scoped: another store's endpoint is indistinguishable from none. */
    private function own(Request $request, WebhookEndpoint $endpoint): void
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        if ($endpoint->merchant_id === null || (int) $endpoint->merchant_id !== (int) $user->merchant_id) {
            abort(404);
        }
    }
}
