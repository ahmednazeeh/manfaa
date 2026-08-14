<?php

declare(strict_types=1);

use App\Jobs\SendWebhook;
use App\Models\AdminUser;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * SSRF regression: the queue worker POSTs the signed envelope wherever the
 * endpoint row points, so endpoint URLs must never be able to aim the
 * platform at its own network — cloud metadata, loopback, RFC1918,
 * link-local — and must be https. Checked at registration AND again at
 * send time (a DNS record repointed after registration).
 */

beforeEach(function () {
    $this->vendor = PosVendor::query()->create(['name' => 'TillWorks']);
    $this->admin = AdminUser::factory()->create();
});

function urlGuardStore(string $url): TestResponse
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/api/admin/pos-vendors/'.test()->vendor->id.'/webhook-endpoints', [
            'url' => $url,
            'events' => ['merchant.rate_changed'],
        ]);
}

it('rejects cleartext http endpoint URLs', function () {
    urlGuardStore('http://vendor.example/hooks')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('rejects endpoint URLs aimed at internal or reserved addresses', function (string $url) {
    urlGuardStore($url)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(WebhookEndpoint::query()->count())->toBe(0);
})->with([
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
    'loopback IPv4' => 'https://127.0.0.1:6379/',
    'loopback IPv6' => 'https://[::1]/internal',
    'RFC1918 10/8' => 'https://10.0.0.5:8080/internal',
    'RFC1918 172.16/12' => 'https://172.16.1.1/',
    'RFC1918 192.168/16' => 'https://192.168.1.10/hooks',
    'localhost name' => 'https://localhost/hooks',
    'internal suffix' => 'https://redis.internal/hooks',
    'mdns suffix' => 'https://till.local/hooks',
]);

it('accepts a public https endpoint URL', function () {
    urlGuardStore('https://vendor.example/hooks')->assertCreated();

    expect(WebhookEndpoint::query()->sole()->url)->toBe('https://vendor.example/hooks');
});

it('refuses delivery at send time when the stored URL is no longer safe', function () {
    Http::fake();

    // Simulates an endpoint whose target went private AFTER registration
    // (row edited, DNS repointed to an IP literal, historic data).
    $endpoint = WebhookEndpoint::query()->create([
        'pos_vendor_id' => $this->vendor->id,
        'url' => 'https://169.254.169.254/latest/meta-data/',
        'secret' => 'whsec_'.Str::random(48),
        'events' => ['merchant.rate_changed'],
        'active' => true,
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'merchant.rate_changed',
        'payload' => ['id' => 'evt_x', 'type' => 'merchant.rate_changed', 'created_at' => now()->toIso8601String(), 'data' => ['merchant_id' => 1]],
        'attempt' => 0,
        'status' => 'pending',
    ]);

    (new SendWebhook($delivery->id))->handle();

    $delivery->refresh();

    // Parked, never retried, and no request ever left the box.
    expect($delivery->status)->toBe('exhausted')
        ->and($delivery->last_error)->toStartWith('unsafe_url:')
        ->and($delivery->next_attempt_at)->toBeNull();
    Http::assertNothingSent();
});
