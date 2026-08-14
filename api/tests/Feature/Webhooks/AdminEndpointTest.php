<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->vendor = PosVendor::query()->create(['name' => 'TillWorks']);
});

it('rejects unauthenticated endpoint management', function () {
    $this->postJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints", [
        'url' => 'https://vendor.example/hooks',
        'events' => ['merchant.rate_changed'],
    ])->assertUnauthorized();

    $this->getJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints")->assertUnauthorized();
});

it('creates an endpoint, shows the secret exactly once, and stores it encrypted', function () {
    $response = $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints", [
            'url' => 'https://vendor.example/hooks',
            'events' => ['merchant.rate_changed', 'merchant.suspended'],
        ])
        ->assertCreated()
        ->assertJsonPath('endpoint.url', 'https://vendor.example/hooks')
        ->assertJsonPath('endpoint.events', ['merchant.rate_changed', 'merchant.suspended'])
        ->assertJsonPath('endpoint.active', true)
        ->assertJsonPath('endpoint.pos_vendor_id', $this->vendor->id)
        ->assertJsonMissingPath('endpoint.secret');

    $secret = $response->json('secret');
    expect($secret)->toStartWith('whsec_');

    $endpoint = WebhookEndpoint::query()->sole();

    // Encrypted at rest: the raw column never contains the plaintext, the
    // cast round-trips it.
    $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');
    expect($raw)->not->toContain($secret)
        ->and($endpoint->secret)->toBe($secret);
});

it('validates url and event names', function () {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints", [
            'url' => 'not-a-url',
            'events' => ['merchant.rate_changed'],
        ])->assertStatus(422)->assertJsonValidationErrors('url');

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints", [
            'url' => 'https://vendor.example/hooks',
            'events' => ['merchant.exploded'],
        ])->assertStatus(422)->assertJsonValidationErrors('events.0');

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints", [
            'url' => 'https://vendor.example/hooks',
            'events' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('events');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('lists a vendor endpoints without ever serialising the secret', function () {
    WebhookEndpoint::query()->create([
        'pos_vendor_id' => $this->vendor->id,
        'url' => 'https://vendor.example/hooks/a',
        'secret' => 'whsec_'.Str::random(48),
        'events' => ['transaction.reversed'],
        'active' => true,
    ]);

    // Another vendor's endpoint stays out of this listing.
    $otherVendor = PosVendor::query()->create(['name' => 'Other']);
    WebhookEndpoint::query()->create([
        'pos_vendor_id' => $otherVendor->id,
        'url' => 'https://other.example/hooks',
        'secret' => 'whsec_'.Str::random(48),
        'events' => ['transaction.reversed'],
        'active' => true,
    ]);

    $response = $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.url', 'https://vendor.example/hooks/a');

    expect(json_encode($response->json()))->not->toContain('whsec_');
});

it('deletes an endpoint (cascading its deliveries) and 404s across vendors', function () {
    $endpoint = WebhookEndpoint::query()->create([
        'pos_vendor_id' => $this->vendor->id,
        'url' => 'https://vendor.example/hooks',
        'secret' => 'whsec_'.Str::random(48),
        'events' => ['transaction.reversed'],
        'active' => true,
    ]);

    WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'transaction.reversed',
        'payload' => ['id' => 'evt_'.Str::ulid(), 'type' => 'transaction.reversed', 'created_at' => now()->toIso8601String(), 'data' => []],
        'attempt' => 0,
        'status' => 'pending',
    ]);

    $admin = AdminUser::factory()->create();

    // Another vendor cannot address this endpoint.
    $otherVendor = PosVendor::query()->create(['name' => 'Other']);
    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/admin/pos-vendors/{$otherVendor->id}/webhook-endpoints/{$endpoint->id}")
        ->assertNotFound();

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/admin/pos-vendors/{$this->vendor->id}/webhook-endpoints/{$endpoint->id}")
        ->assertOk();

    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});
