<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Jobs\SendWebhook;
use App\Models\ApiCredential;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fans one §9.3 event out to every endpoint entitled to hear it, one
 * WebhookDelivery row + one queued SendWebhook job per endpoint.
 *
 * Entitlement is two-fold:
 *  - the endpoint is active AND subscribed to this event name, and
 *  - the endpoint's POS VENDOR holds a live (unrevoked) api_credential for
 *    the affected merchant. Endpoints are per-vendor, not per-merchant, so
 *    merchant scoping happens here, via payload merchant_id joined against
 *    the vendor–credential table — a vendor never hears about merchants it
 *    no longer integrates.
 *
 * Every §9.3 event payload carries merchant_id, so the scoping input is a
 * hard requirement, not a convention.
 *
 * The signed envelope {id, type, created_at, data} is built once per event:
 * all endpoints receive the same event id (`evt_` + ULID) — deduplication
 * is per receiver — and created_at is the authoritative event time, inside
 * the signature (docs/openapi.yaml WebhookEvent).
 */
final class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload  event data; MUST carry merchant_id
     * @return int the number of deliveries queued
     */
    public function dispatch(string $event, array $payload): int
    {
        if (! in_array($event, WebhookEvents::all(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown webhook event "%s".', $event));
        }

        $merchantId = $payload['merchant_id'] ?? null;

        if (! is_int($merchantId)) {
            throw new InvalidArgumentException(sprintf('Webhook payload for %s must carry an integer merchant_id.', $event));
        }

        $endpoints = WebhookEndpoint::query()
            ->where('active', true)
            ->whereJsonContains('events', $event)
            ->whereIn('pos_vendor_id', ApiCredential::query()
                ->select('pos_vendor_id')
                ->where('merchant_id', $merchantId)
                ->whereNotNull('pos_vendor_id')
                ->whereNull('revoked_at'))
            ->orderBy('id')
            ->get();

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $envelope = [
            'id' => 'evt_'.Str::ulid(),
            'type' => $event,
            'created_at' => CarbonImmutable::now((string) config('app.business_timezone', 'Indian/Maldives'))->toIso8601String(),
            'data' => $payload,
        ];

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => $envelope,
                'attempt' => 0,
                'status' => 'pending',
            ]);

            SendWebhook::dispatch($delivery->id);
        }

        return $endpoints->count();
    }
}
