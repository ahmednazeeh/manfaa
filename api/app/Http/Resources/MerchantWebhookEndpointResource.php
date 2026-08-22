<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant-owned webhook endpoint as the panel and /v1 show it. Never the
 * secret — that was shown once at registration and is gone.
 *
 * @mixin WebhookEndpoint
 */
final class MerchantWebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;

        /** @var WebhookDelivery|null $last */
        $last = $endpoint->relationLoaded('deliveries') ? $endpoint->deliveries->first() : null;

        return [
            'id' => $endpoint->getKey(),
            'url' => $endpoint->url,
            'label' => $endpoint->label,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
            // Which door registered it. A plugin's endpoint dies with its
            // credential; a panel-made one outlives every token.
            'registered_by' => $endpoint->api_credential_id !== null ? 'credential' : 'panel',
            'api_credential_id' => $endpoint->api_credential_id,
            // "Last heard from" for the panel: the newest delivery, any event.
            'last_delivery' => $last === null ? null : [
                'event' => $last->event,
                'status' => $last->status,
                'response_status' => $last->response_status,
                'attempted_at' => ($last->delivered_at ?? $last->updated_at)?->toIso8601String(),
            ],
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }
}
