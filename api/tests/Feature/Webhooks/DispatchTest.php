<?php

declare(strict_types=1);

use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Jobs\SendWebhook;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A vendor with (by default) a live credential for $merchant, plus one
 * webhook endpoint. Returns the endpoint.
 *
 * @param  list<string>  $events
 */
function whDispatchEndpoint(
    Merchant $merchant,
    array $events,
    bool $active = true,
    bool $credential = true,
    bool $revoked = false,
): WebhookEndpoint {
    $vendor = PosVendor::query()->create(['name' => 'Vendor '.Str::random(6)]);

    if ($credential) {
        ApiCredential::query()->create([
            'merchant_id' => $merchant->id,
            'pos_vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', Str::random(40)),
            'abilities' => ['transactions:write'],
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    return WebhookEndpoint::query()->create([
        'pos_vendor_id' => $vendor->id,
        'url' => 'https://vendor.example/hooks/'.Str::random(8),
        'secret' => 'whsec_'.Str::random(48),
        'events' => $events,
        'active' => $active,
    ]);
}

beforeEach(function () {
    Queue::fake();
    $this->merchant = Merchant::factory()->create();
});

it('queues one delivery and one job for a subscribed, active endpoint whose vendor holds a live credential', function () {
    $endpoint = whDispatchEndpoint($this->merchant, [WebhookEvents::MERCHANT_SUSPENDED, WebhookEvents::MERCHANT_REINSTATED]);

    $queued = app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_SUSPENDED, [
        'merchant_id' => $this->merchant->id,
        'reason' => 'overdue_settlement',
        'suspended_at' => '2026-08-16T00:05:00+05:00',
    ]);

    expect($queued)->toBe(1);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->event)->toBe('merchant.suspended')
        ->and($delivery->status)->toBe('pending')
        ->and($delivery->attempt)->toBe(0)
        ->and($delivery->payload['id'])->toMatch('/^evt_[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($delivery->payload['type'])->toBe('merchant.suspended')
        ->and($delivery->payload['created_at'])->not->toBeNull()
        // toEqual: jsonb round-trips with canonical key order.
        ->and($delivery->payload['data'])->toEqual([
            'merchant_id' => $this->merchant->id,
            'reason' => 'overdue_settlement',
            'suspended_at' => '2026-08-16T00:05:00+05:00',
        ]);

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $delivery->id);
});

it('skips endpoints not subscribed to the event', function () {
    whDispatchEndpoint($this->merchant, [WebhookEvents::MERCHANT_RATE_CHANGED]);

    $queued = app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_SUSPENDED, [
        'merchant_id' => $this->merchant->id,
        'reason' => 'overdue_settlement',
        'suspended_at' => '2026-08-16T00:05:00+05:00',
    ]);

    expect($queued)->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skips inactive endpoints', function () {
    whDispatchEndpoint($this->merchant, [WebhookEvents::MERCHANT_SUSPENDED], active: false);

    app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_SUSPENDED, [
        'merchant_id' => $this->merchant->id,
        'reason' => 'overdue_settlement',
        'suspended_at' => '2026-08-16T00:05:00+05:00',
    ]);

    expect(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skips endpoints whose vendor holds no credential for the affected merchant', function () {
    // Vendor integrates a DIFFERENT merchant — must not hear about this one.
    $other = Merchant::factory()->create();
    whDispatchEndpoint($other, [WebhookEvents::MERCHANT_SUSPENDED]);

    // And a vendor with no credential at all.
    whDispatchEndpoint($this->merchant, [WebhookEvents::MERCHANT_SUSPENDED], credential: false);

    app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_SUSPENDED, [
        'merchant_id' => $this->merchant->id,
        'reason' => 'overdue_settlement',
        'suspended_at' => '2026-08-16T00:05:00+05:00',
    ]);

    expect(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skips endpoints whose vendor credential for the merchant is revoked', function () {
    whDispatchEndpoint($this->merchant, [WebhookEvents::MERCHANT_SUSPENDED], revoked: true);

    app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_SUSPENDED, [
        'merchant_id' => $this->merchant->id,
        'reason' => 'overdue_settlement',
        'suspended_at' => '2026-08-16T00:05:00+05:00',
    ]);

    expect(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fans out to every qualifying endpoint, all sharing one event id', function () {
    whDispatchEndpoint($this->merchant, [WebhookEvents::TRANSACTION_REVERSED]);
    whDispatchEndpoint($this->merchant, [WebhookEvents::TRANSACTION_REVERSED, WebhookEvents::MERCHANT_SUSPENDED]);
    whDispatchEndpoint($this->merchant, [WebhookEvents::MERCHANT_SUSPENDED]); // not subscribed

    $queued = app(WebhookDispatcher::class)->dispatch(WebhookEvents::TRANSACTION_REVERSED, [
        'transaction_id' => 1,
        'merchant_id' => $this->merchant->id,
        'invoice_no' => 'INV-1001',
        'reason' => 'customer_refund',
        'reversed_at' => '2026-08-15T14:20:00+05:00',
    ]);

    expect($queued)->toBe(2);

    $deliveries = WebhookDelivery::query()->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('payload.id')->unique())->toHaveCount(1);

    Queue::assertPushed(SendWebhook::class, 2);
});

it('rejects unknown events and payloads without a merchant_id', function () {
    expect(fn () => app(WebhookDispatcher::class)->dispatch('merchant.exploded', ['merchant_id' => 1]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_SUSPENDED, ['reason' => 'overdue_settlement']))
        ->toThrow(InvalidArgumentException::class);
});
