<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Webhooks\EndpointUrlGuard;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\PosVendor;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin registration of §9.3 webhook endpoints, per POS vendor — we do the
 * integrations ourselves, so endpoints are admin-created like credentials.
 * The signing secret is generated here, returned exactly once in the 201
 * body (handed to the vendor out-of-band), and stored encrypted; there is
 * no retrieval path. Losing it means registering a new endpoint.
 */
class WebhookEndpointController extends Controller
{
    public function store(Request $request, PosVendor $vendor): JsonResponse
    {
        $validated = $request->validate([
            // https-only, public hosts only (EndpointUrlGuard): the queue
            // worker POSTs wherever this row points, so a private-range or
            // metadata-service URL would turn deliveries into SSRF probes.
            'url' => ['required', 'string', 'url:https', 'max:2048', function (string $attribute, mixed $value, callable $fail) {
                if (is_string($value) && ($violation = EndpointUrlGuard::violation($value)) !== null) {
                    $fail($violation);
                }
            }],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookEvents::all())],
        ]);

        $secret = 'whsec_'.Str::random(48);

        $endpoint = WebhookEndpoint::query()->create([
            'pos_vendor_id' => $vendor->id,
            'url' => $validated['url'],
            'events' => array_values(array_unique($validated['events'])),
            'secret' => $secret,
            'active' => true,
        ]);

        return response()->json([
            // Shown once. Only the encrypted form is stored; this string is
            // what the vendor verifies X-Manfaa-Signature with.
            'secret' => $secret,
            'endpoint' => new WebhookEndpointResource($endpoint),
        ], 201);
    }

    public function index(PosVendor $vendor): AnonymousResourceCollection
    {
        $endpoints = WebhookEndpoint::query()
            ->where('pos_vendor_id', $vendor->id)
            ->orderBy('id')
            ->get();

        return WebhookEndpointResource::collection($endpoints);
    }

    public function destroy(PosVendor $vendor, WebhookEndpoint $endpoint): JsonResponse
    {
        // Vendor-scoped: another vendor's endpoint id is indistinguishable
        // from a nonexistent one.
        if ($endpoint->pos_vendor_id !== $vendor->id) {
            abort(404);
        }

        // Hard delete; delivery history cascades. Deliveries are operational
        // telemetry, not financial history — the append-only rule protects
        // the ledger and transaction_events, not delivery attempts to an
        // endpoint that no longer exists.
        $endpoint->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
