<?php

declare(strict_types=1);

use App\Domain\Credentials\CredentialService;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Jobs\SendWebhook;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merchant-owned webhook endpoints (owner, 2026-08-22).
 *
 * Two doors — the panel and /v1 — and two properties that make the whole
 * thing worth having: a shop hears ONLY its own events, and a plugin's
 * endpoint dies with the token that registered it.
 */
const WH_URL = 'https://shop.example.mv/wp-json/manfaa/v1/webhook';

function whPanelOwner(Merchant $merchant): MerchantUser
{
    return MerchantUser::factory()->for($merchant)->owner()->create();
}

/** A /v1 bearer token carrying the given abilities, with its ApiCredential row. */
function whVendorToken(Merchant $merchant, array $abilities = ['webhooks:manage']): array
{
    $issued = app(CredentialService::class)->issueForMerchantUser(
        $merchant,
        'Plugin '.Str::random(4),
        $abilities,
        whPanelOwner($merchant),
    );

    return [['Authorization' => 'Bearer '.$issued->plainTextToken], $issued->credential];
}

beforeEach(function (): void {
    Queue::fake();
    $this->merchant = Merchant::factory()->create();
    $this->owner = whPanelOwner($this->merchant);
});

// ---------------------------------------------------------------- panel

it('lets the owner register an endpoint and shows the secret exactly once', function (): void {
    $response = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/webhook-endpoints', [
            'url' => WH_URL,
            'label' => 'My shop',
            'events' => [WebhookEvents::MERCHANT_RATE_CHANGED, WebhookEvents::TRANSACTION_REVERSED],
        ])
        ->assertCreated();

    $secret = $response->json('secret');
    expect($secret)->toStartWith('whsec_');
    expect($response->json('endpoint.registered_by'))->toBe('panel');
    expect($response->json('endpoint.label'))->toBe('My shop');

    // The list never shows it again.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/webhook-endpoints')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.secret');

    // And what is stored is the encrypted form, which verifies the same HMAC.
    $stored = WebhookEndpoint::query()->where('merchant_id', $this->merchant->id)->firstOrFail();
    expect($stored->secret)->toBe($secret);
    expect($stored->merchant_id)->toBe($this->merchant->id);
    expect($stored->pos_vendor_id)->toBeNull();
});

it('refuses a private, non-https or unknown-event registration the same way the admin registry does', function (): void {
    $as = fn () => $this->actingAs($this->owner, 'merchant');

    $as()->postJson('/api/merchant/webhook-endpoints', [
        'url' => 'http://shop.example.mv/hook', 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
    ])->assertStatus(422);

    $as()->postJson('/api/merchant/webhook-endpoints', [
        'url' => 'https://10.0.0.5/hook', 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
    ])->assertStatus(422);

    $as()->postJson('/api/merchant/webhook-endpoints', [
        'url' => WH_URL, 'events' => ['webhook.test'],
    ])->assertStatus(422);
});

it('caps a merchant at five active endpoints', function (): void {
    for ($i = 0; $i < WebhookEndpoint::MAX_PER_MERCHANT; $i++) {
        $this->actingAs($this->owner, 'merchant')
            ->postJson('/api/merchant/webhook-endpoints', [
                'url' => WH_URL.'/'.$i, 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
            ])->assertCreated();
    }

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/webhook-endpoints', [
            'url' => WH_URL.'/one-too-many', 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'endpoint_cap_reached');
});

it('is owner-only, like the credentials beside it — staff and managers are refused', function (): void {
    // api_credentials.* is seeded to the Owner preset alone.
    foreach (['staff', 'manager'] as $preset) {
        $user = MerchantUser::factory()->for($this->merchant)->{$preset}()->create();

        $this->actingAs($user, 'merchant')->getJson('/api/merchant/webhook-endpoints')->assertForbidden();
        $this->actingAs($user, 'merchant')->postJson('/api/merchant/webhook-endpoints', [
            'url' => WH_URL, 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
        ])->assertForbidden();
    }
});

it("another store's endpoint is indistinguishable from none", function (): void {
    $other = Merchant::factory()->create();
    $theirs = WebhookEndpoint::query()->create([
        'merchant_id' => $other->id, 'url' => WH_URL, 'secret' => 'whsec_x',
        'events' => [WebhookEvents::MERCHANT_RATE_CHANGED], 'active' => true,
    ]);

    $this->actingAs($this->owner, 'merchant')
        ->deleteJson('/api/merchant/webhook-endpoints/'.$theirs->id)
        ->assertNotFound();
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/webhook-endpoints/'.$theirs->id.'/test')
        ->assertNotFound();

    expect(WebhookEndpoint::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('sends a test delivery that is signed exactly like a real one', function (): void {
    $created = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/webhook-endpoints', [
            'url' => WH_URL, 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
        ])->assertCreated();

    $id = $created->json('endpoint.id');

    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/webhook-endpoints/{$id}/test")
        ->assertStatus(202)
        ->assertJsonPath('delivery.event', WebhookEvents::TEST);

    $delivery = WebhookDelivery::query()->where('webhook_endpoint_id', $id)->firstOrFail();
    expect($delivery->payload['type'])->toBe(WebhookEvents::TEST);
    expect($delivery->payload['data']['merchant_id'])->toBe($this->merchant->id);
    expect($delivery->payload)->toHaveKeys(['id', 'type', 'created_at', 'data']);

    Queue::assertPushed(SendWebhook::class, 1);
});

// ------------------------------------------------------------- dispatch

it('delivers a merchant event to that merchant\'s own endpoint and to nobody else\'s', function (): void {
    $mine = WebhookEndpoint::query()->create([
        'merchant_id' => $this->merchant->id, 'url' => WH_URL, 'secret' => 'whsec_a',
        'events' => [WebhookEvents::MERCHANT_RATE_CHANGED], 'active' => true,
    ]);
    $other = Merchant::factory()->create();
    $theirs = WebhookEndpoint::query()->create([
        'merchant_id' => $other->id, 'url' => WH_URL.'/other', 'secret' => 'whsec_b',
        'events' => [WebhookEvents::MERCHANT_RATE_CHANGED], 'active' => true,
    ]);

    $queued = app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_RATE_CHANGED, [
        'merchant_id' => $this->merchant->id,
    ]);

    expect($queued)->toBe(1);
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $mine->id)->count())->toBe(1);
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $theirs->id)->count())->toBe(0);
});

it('still delivers to a POS vendor endpoint through its live credential, alongside the merchant\'s own', function (): void {
    // The pre-existing vendor path must keep working unchanged.
    $vendor = PosVendor::query()->create(['name' => 'Vendor '.Str::random(6)]);
    ApiCredential::query()->create([
        'merchant_id' => $this->merchant->id, 'pos_vendor_id' => $vendor->id,
        'token_hash' => hash('sha256', Str::random(40)), 'abilities' => ['transactions:write'],
    ]);
    $vendorEndpoint = WebhookEndpoint::query()->create([
        'pos_vendor_id' => $vendor->id, 'url' => 'https://vendor.example/hooks', 'secret' => 'whsec_v',
        'events' => [WebhookEvents::TRANSACTION_REVERSED], 'active' => true,
    ]);
    $mine = WebhookEndpoint::query()->create([
        'merchant_id' => $this->merchant->id, 'url' => WH_URL, 'secret' => 'whsec_m',
        'events' => [WebhookEvents::TRANSACTION_REVERSED], 'active' => true,
    ]);

    $queued = app(WebhookDispatcher::class)->dispatch(WebhookEvents::TRANSACTION_REVERSED, [
        'merchant_id' => $this->merchant->id, 'transaction_id' => 1, 'invoice_no' => 'X', 'reason' => 'other', 'reversed_at' => now()->toIso8601String(),
    ]);

    expect($queued)->toBe(2);
    expect(WebhookDelivery::query()->whereIn('webhook_endpoint_id', [$vendorEndpoint->id, $mine->id])->count())->toBe(2);
});

it('the database refuses an endpoint with no owner', function (): void {
    expect(fn () => WebhookEndpoint::query()->create([
        'url' => WH_URL, 'secret' => 'whsec_x', 'events' => [], 'active' => true,
    ]))->toThrow(QueryException::class);
});

it('the database refuses an endpoint with two owners', function (): void {
    // One refused statement per test: Postgres aborts the surrounding
    // transaction, so a second expect in the same test would only see that.
    $vendor = PosVendor::query()->create(['name' => 'V']);
    expect(fn () => WebhookEndpoint::query()->create([
        'pos_vendor_id' => $vendor->id, 'merchant_id' => $this->merchant->id,
        'url' => WH_URL, 'secret' => 'whsec_x', 'events' => [], 'active' => true,
    ]))->toThrow(QueryException::class);
});

// ------------------------------------------------------------------ /v1

it('a credential with webhooks:manage registers its own endpoint over /v1', function (): void {
    [$headers, $credential] = whVendorToken($this->merchant);

    $response = $this->withHeaders($headers)->postJson('/api/v1/webhooks', [
        'url' => WH_URL, 'label' => 'WooCommerce', 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
    ])->assertCreated();

    expect($response->json('secret'))->toStartWith('whsec_');
    expect($response->json('endpoint.registered_by'))->toBe('credential');
    expect($response->json('endpoint.api_credential_id'))->toBe($credential->id);
});

it('a credential without the ability is refused', function (): void {
    [$headers] = whVendorToken($this->merchant, ['transactions:write']);

    $this->withHeaders($headers)->postJson('/api/v1/webhooks', [
        'url' => WH_URL, 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
    ])->assertForbidden();
});

it('re-registering the same URL from the same credential replaces, never stacks', function (): void {
    [$headers] = whVendorToken($this->merchant);

    for ($i = 0; $i < 3; $i++) {
        $this->withHeaders($headers)->postJson('/api/v1/webhooks', [
            'url' => WH_URL, 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
        ])->assertCreated();
    }

    expect(WebhookEndpoint::query()->where('merchant_id', $this->merchant->id)->count())->toBe(1);
});

it('a credential sees and removes only what it registered — not the panel\'s, not another token\'s', function (): void {
    [$headersA, $credA] = whVendorToken($this->merchant);
    [$headersB] = whVendorToken($this->merchant);

    $panelMade = WebhookEndpoint::query()->create([
        'merchant_id' => $this->merchant->id, 'url' => WH_URL.'/panel', 'secret' => 'whsec_p',
        'events' => [WebhookEvents::MERCHANT_RATE_CHANGED], 'active' => true,
    ]);
    $a = $this->withHeaders($headersA)->postJson('/api/v1/webhooks', [
        'url' => WH_URL.'/a', 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
    ])->assertCreated()->json('endpoint.id');

    // Sanctum caches the first authenticated user on the guard for the life
    // of the test process; a second bearer in the same test needs the guard
    // forgotten, exactly as Phase3LifecycleTest does. Production is one
    // process per request and has no such cache.
    app('auth')->forgetGuards();
    $this->withHeaders($headersB)->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(0, 'data');
    $this->withHeaders($headersB)->deleteJson('/api/v1/webhooks/'.$a)->assertNotFound();

    app('auth')->forgetGuards();
    $this->withHeaders($headersA)->deleteJson('/api/v1/webhooks/'.$panelMade->id)->assertNotFound();
    $this->withHeaders($headersA)->deleteJson('/api/v1/webhooks/'.$a)->assertNoContent();
    expect(WebhookEndpoint::query()->whereKey($panelMade->id)->exists())->toBeTrue();
});

it('revoking the credential switches its endpoints off but leaves the panel\'s alone', function (): void {
    [$headers, $credential] = whVendorToken($this->merchant);

    $pluginEndpoint = $this->withHeaders($headers)->postJson('/api/v1/webhooks', [
        'url' => WH_URL, 'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
    ])->assertCreated()->json('endpoint.id');

    $panelMade = WebhookEndpoint::query()->create([
        'merchant_id' => $this->merchant->id, 'url' => WH_URL.'/panel', 'secret' => 'whsec_p',
        'events' => [WebhookEvents::MERCHANT_RATE_CHANGED], 'active' => true,
    ]);

    app(CredentialService::class)->revoke($credential->fresh(), $this->owner);

    expect(WebhookEndpoint::query()->find($pluginEndpoint)->active)->toBeFalse();
    expect(WebhookEndpoint::query()->find($panelMade->id)->active)->toBeTrue();

    // And the dead endpoint hears nothing more.
    $queued = app(WebhookDispatcher::class)->dispatch(WebhookEvents::MERCHANT_RATE_CHANGED, [
        'merchant_id' => $this->merchant->id,
    ]);
    expect($queued)->toBe(1);
});
