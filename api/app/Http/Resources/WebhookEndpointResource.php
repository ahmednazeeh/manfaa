<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor webhook endpoint for the admin panel. The signing secret is
 * NEVER serialised here — it appears exactly once, in the creation 201
 * body, and is otherwise unrecoverable by design.
 *
 * @property-read WebhookEndpoint $resource
 */
class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $endpoint = $this->resource;

        $last = $endpoint->relationLoaded('deliveries') ? $endpoint->deliveries->first() : null;

        return [
            'id' => $endpoint->id,
            'pos_vendor_id' => $endpoint->pos_vendor_id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
            // "Last heard from", for the registry screen: the newest delivery.
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
