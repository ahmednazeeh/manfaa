<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Webhooks\EndpointUrlGuard;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One §9.3 delivery attempt, exactly as published in docs/openapi.yaml:
 *
 *  - POST, raw JSON body = the stored signed envelope. The body is
 *    serialised from the delivery's jsonb payload, whose key order is
 *    canonical — every retry sends byte-identical bytes, and therefore an
 *    identical signature.
 *  - X-Manfaa-Signature: lowercase hex HMAC-SHA256 of the raw body with the
 *    endpoint's secret (over the exact bytes sent, computed before sending).
 *  - X-Manfaa-Timestamp: unix seconds of THIS attempt — fresh per retry,
 *    deliberately outside the signature.
 *  - X-Manfaa-Event: the event name, so receivers can route before parsing.
 *  - Success is any 2xx within 10 seconds. Anything else — non-2xx,
 *    timeout, connection failure — schedules the next attempt.
 *
 * Retry policy: 6 retries after the initial attempt, delayed 1m / 5m / 30m
 * / 2h / 8h / 24h from the previous failure (≈35 hours end to end), then
 * the delivery is parked as `exhausted` and logged for operations — never
 * silently dropped. The schedule is driven by this job re-dispatching
 * itself with a delay, so the delivery row (attempt counter,
 * next_attempt_at) is always the source of truth, not queue internals.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    /** Initial attempt + six retries (docs/openapi.yaml WebhookEvent). */
    public const int MAX_ATTEMPTS = 7;

    public const int TIMEOUT_SECONDS = 10;

    /** Delay before retry N, in seconds: 1m, 5m, 30m, 2h, 8h, 24h. */
    public const array RETRY_DELAY_SECONDS = [60, 300, 1800, 7200, 28800, 86400];

    public function __construct(public int $deliveryId)
    {
        // Emit sites can sit inside a DB transaction (the /v1 idempotency
        // middleware wraps the handler in one); the job must not race a
        // worker to a delivery row that has not committed yet — a too-early
        // run would find no row and silently drop the delivery.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, ['delivered', 'exhausted'], true)) {
            return;
        }

        $endpoint = $delivery->endpoint;
        $now = CarbonImmutable::now('UTC');

        // Deactivated between dispatch and attempt: park it. Consent to be
        // called was withdrawn; retrying later would ignore that.
        if ($endpoint === null || ! $endpoint->active) {
            $delivery->update([
                'status' => 'exhausted',
                'last_error' => 'endpoint_inactive',
                'next_attempt_at' => null,
            ]);

            return;
        }

        // SSRF re-check at send time (EndpointUrlGuard): registration
        // validated the URL, but a DNS record repointed at the internal
        // network since then must still be refused. Parked, never retried —
        // the URL will not become safe by waiting.
        if (($violation = EndpointUrlGuard::violation($endpoint->url)) !== null) {
            $delivery->update([
                'status' => 'exhausted',
                'last_error' => 'unsafe_url: '.$violation,
                'next_attempt_at' => null,
            ]);

            Log::warning(sprintf(
                'Webhook delivery #%d refused: endpoint #%d URL failed the egress guard (%s)',
                $delivery->id, $endpoint->id, $violation,
            ));

            return;
        }

        [$responseStatus, $error] = $this->attempt($endpoint, $delivery->event, $this->rawBody($delivery));

        $attempt = $delivery->attempt + 1;

        if ($error === null) {
            $delivery->update([
                'attempt' => $attempt,
                'status' => 'delivered',
                'response_status' => $responseStatus,
                'last_error' => null,
                'next_attempt_at' => null,
                'delivered_at' => $now,
            ]);

            return;
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            $delivery->update([
                'attempt' => $attempt,
                'status' => 'exhausted',
                'response_status' => $responseStatus,
                'last_error' => $error,
                'next_attempt_at' => null,
            ]);

            Log::warning(sprintf(
                'Webhook delivery #%d (%s → endpoint #%d) exhausted after %d attempts: %s',
                $delivery->id, $delivery->event, $endpoint->id, $attempt, $error,
            ));

            return;
        }

        $delay = self::RETRY_DELAY_SECONDS[$attempt - 1];

        $delivery->update([
            'attempt' => $attempt,
            'status' => 'failed',
            'response_status' => $responseStatus,
            'last_error' => $error,
            'next_attempt_at' => $now->addSeconds($delay),
        ]);

        self::dispatch($this->deliveryId)->delay($delay);
    }

    /**
     * The exact bytes to sign and send. json_encode over the jsonb
     * round-tripped payload: PostgreSQL stores jsonb keys in canonical
     * order, so every attempt yields identical bytes.
     */
    private function rawBody(WebhookDelivery $delivery): string
    {
        return (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{int|null, string|null} [response status, error] — error null on success
     */
    private function attempt(WebhookEndpoint $endpoint, string $event, string $body): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Manfaa-Signature' => hash_hmac('sha256', $body, $endpoint->secret),
                    'X-Manfaa-Timestamp' => (string) CarbonImmutable::now('UTC')->getTimestamp(),
                    'X-Manfaa-Event' => $event,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $exception) {
            return [null, mb_substr($exception->getMessage(), 0, 1000)];
        }

        return $response->successful()
            ? [$response->status(), null]
            : [$response->status(), sprintf('HTTP %d', $response->status())];
    }
}
