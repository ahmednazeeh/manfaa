<?php

declare(strict_types=1);

use App\Jobs\SendWebhook;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

const WH_JOB_SECRET = 'whsec_test_signing_secret_0123456789abcdef';

function whJobDelivery(): WebhookDelivery
{
    $vendor = PosVendor::query()->create(['name' => 'Vendor '.Str::random(6)]);

    $endpoint = WebhookEndpoint::query()->create([
        'pos_vendor_id' => $vendor->id,
        'url' => 'https://vendor.example/hooks/till',
        'secret' => WH_JOB_SECRET,
        'events' => ['merchant.rate_changed'],
        'active' => true,
    ]);

    return WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'merchant.rate_changed',
        'payload' => [
            'id' => 'evt_'.Str::ulid(),
            'type' => 'merchant.rate_changed',
            'created_at' => '2026-08-14T16:05:11+05:00',
            'data' => [
                'merchant_id' => 12,
                'cashback_rate_percent' => '1.50',
                'platform_fee_percent' => '0.50',
                'previous_cashback_rate_percent' => '2.00',
                'previous_platform_fee_percent' => '0.75',
                'effective_at' => '2026-08-15T00:00:00+05:00',
            ],
        ],
        'attempt' => 0,
        'status' => 'pending',
    ]);
}

it('POSTs the envelope with a signature that verifies against the raw body', function () {
    Queue::fake();
    Http::fake(['vendor.example/*' => Http::response(['ok' => true], 200)]);

    $delivery = whJobDelivery();

    (new SendWebhook($delivery->id))->handle();

    Http::assertSent(function (ClientRequest $request) use ($delivery) {
        $raw = $request->body();

        // The published verification recipe (docs/openapi.yaml): lowercase
        // hex HMAC-SHA256 of the raw body bytes with the endpoint secret.
        return $request->url() === 'https://vendor.example/hooks/till'
            && $request->method() === 'POST'
            && $request->header('X-Manfaa-Signature') === [hash_hmac('sha256', $raw, WH_JOB_SECRET)]
            && preg_match('/^\d{10}$/', $request->header('X-Manfaa-Timestamp')[0] ?? '') === 1
            && $request->header('X-Manfaa-Event') === ['merchant.rate_changed']
            && $request->header('Content-Type') === ['application/json']
            && json_decode($raw, true) == $delivery->refresh()->payload;
    });

    $delivery->refresh();
    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toBeNull();

    // Delivered: no retry scheduled.
    Queue::assertNothingPushed();
});

it('schedules a 1-minute retry on a 500 and delivers on the following 2xx', function () {
    Queue::fake();
    Http::fake(['vendor.example/*' => Http::sequence()
        ->push('boom', 500)
        ->push('ok', 200)]);

    $now = CarbonImmutable::parse('2026-08-14T12:00:00Z');
    Carbon::setTestNow($now);

    $delivery = whJobDelivery();

    (new SendWebhook($delivery->id))->handle();

    $delivery->refresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->last_error)->toBe('HTTP 500')
        ->and($delivery->next_attempt_at->equalTo($now->addSeconds(60)))->toBeTrue();

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $delivery->id && $job->delay === 60);

    // The retry (run here directly; in production the queue delays it).
    (new SendWebhook($delivery->id))->handle();

    $delivery->refresh();
    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempt)->toBe(2)
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull();
});

it('walks the full 1m/5m/30m/2h/8h/24h schedule and parks the delivery as exhausted', function () {
    Queue::fake();
    Http::fake(['vendor.example/*' => Http::response('boom', 500)]);

    $delivery = whJobDelivery();

    foreach (range(1, SendWebhook::MAX_ATTEMPTS) as $attempt) {
        (new SendWebhook($delivery->id))->handle();
        expect($delivery->refresh()->attempt)->toBe($attempt);
    }

    // Six retries were scheduled with the published backoff, in order.
    expect(Queue::pushed(SendWebhook::class)->map(fn (SendWebhook $job) => $job->delay)->all())
        ->toBe([60, 300, 1800, 7200, 28800, 86400]);

    expect($delivery->refresh()->status)->toBe('exhausted')
        ->and($delivery->attempt)->toBe(7)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toBe('HTTP 500');

    // A stray extra run is a no-op: no eighth attempt, no eighth request.
    (new SendWebhook($delivery->id))->handle();
    expect($delivery->refresh()->attempt)->toBe(7);
    Http::assertSentCount(7);
});

it('treats a connection failure like a failed attempt, with no response status', function () {
    Queue::fake();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: operation timed out'));

    $delivery = whJobDelivery();

    (new SendWebhook($delivery->id))->handle();

    $delivery->refresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->last_error)->toContain('cURL error 28');

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->delay === 60);
});

it('parks the delivery without calling out when the endpoint was deactivated meanwhile', function () {
    Queue::fake();
    Http::fake();

    $delivery = whJobDelivery();
    $delivery->endpoint->update(['active' => false]);

    (new SendWebhook($delivery->id))->handle();

    expect($delivery->refresh()->status)->toBe('exhausted')
        ->and($delivery->last_error)->toBe('endpoint_inactive');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
