<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\OauthAuthorizationCode;
use App\Models\PosVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PUBLIC CLIENTS — "Connect with Manfaa" from a plugin that cannot keep a
 * secret (owner decision 2026-08-22, the WooCommerce plugin).
 *
 * The handshake is the same as for IsleBooks with two things removed — the
 * secret and the registered callback list — and two things added in their
 * place: the callback the plugin sends is checked for safety and shown to
 * the shopkeeper, and the grant remembers which store it came from.
 */

const SHOP_CALLBACK = 'https://shop.example.mv/wp-admin/admin.php?page=manfaa-cashback&manfaa-callback=1';

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

/** The one WooCommerce client: no secret, no callback list, PKCE only. */
function publicClient(array $overrides = []): PosVendor
{
    return PosVendor::query()->create(array_merge([
        'name' => 'Manfaa for WooCommerce',
        'display_name' => 'Manfaa for WooCommerce',
        'integration_status' => 'active',
        'client_id' => 'mfa_'.Str::lower(Str::random(24)),
        'client_secret_hash' => null,
        'redirect_uris' => null,
        'allowed_abilities' => ['transactions:write', 'transactions:reverse', 'rates:read', 'customers:lookup', 'webhooks:manage'],
        'connect_enabled' => true,
        'public_client' => true,
    ], $overrides));
}

function pkcePair(): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

/** The shopkeeper presses Authorise; returns the one-time code. */
function approvePublic(TestCase $test, PosVendor $vendor, string $challenge, string $callback = SHOP_CALLBACK, string $scope = 'transactions:write rates:read'): string
{
    $redirect = $test->actingAs(test()->owner, 'merchant')
        ->postJson('/api/merchant/connect/authorize', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => $callback,
            'scope' => $scope,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->assertOk()
        ->json('data.redirect_to');

    expect($redirect)->toStartWith($callback.(str_contains($callback, '?') ? '&' : '?').'code=');

    parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

    return $query['code'];
}

/** The plugin swaps the code for a token — no secret. */
function exchangePublic(TestCase $test, PosVendor $vendor, string $code, string $verifier, string $callback = SHOP_CALLBACK, array $extra = [])
{
    app('auth')->forgetGuards();

    return $test->postJson('/api/v1/connect/token', array_merge([
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'code' => $code,
        'redirect_uri' => $callback,
        'code_verifier' => $verifier,
    ], $extra));
}

it('connects a store with no secret and remembers where the grant came from', function () {
    $vendor = publicClient();
    [$verifier, $challenge] = pkcePair();

    // The consent screen names the connecting store.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => SHOP_CALLBACK,
            'scope' => 'transactions:write rates:read',
        ]))
        ->assertOk()
        ->assertJsonPath('data.application.public_client', true)
        ->assertJsonPath('data.callback_host', 'shop.example.mv')
        ->assertJsonPath('data.already_connected', false);

    $code = approvePublic($this, $vendor, $challenge);

    $token = exchangePublic($this, $vendor, $code, $verifier)
        ->assertCreated()
        ->assertJsonPath('scope', 'transactions:write rates:read')
        ->assertJsonPath('merchant.id', $this->merchant->id)
        ->json('access_token');

    $credential = ApiCredential::query()->firstOrFail();
    expect($credential->pos_vendor_id)->toBe($vendor->id);
    expect($credential->connected_from)->toBe('https://shop.example.mv');
    expect($credential->issued_by_merchant_user)->toBe($this->owner->id);

    // And the token says so about itself.
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('merchant.id', $this->merchant->id)
        ->assertJsonPath('credential.abilities', ['transactions:write', 'rates:read'])
        ->assertJsonPath('credential.connected_from', 'https://shop.example.mv')
        ->assertJsonPath('credential.label', 'Manfaa for WooCommerce');
});

it('refuses a public client that sends a secret', function () {
    $vendor = publicClient();
    [$verifier, $challenge] = pkcePair();
    $code = approvePublic($this, $vendor, $challenge);

    exchangePublic($this, $vendor, $code, $verifier, extra: ['client_secret' => 'i-think-i-have-one'])
        ->assertStatus(401)
        ->assertJsonPath('error', 'invalid_client');

    // The code was not spent by the refusal; the honest retry works.
    exchangePublic($this, $vendor, $code, $verifier)->assertCreated();
});

it('still demands the secret from a confidential platform', function () {
    $vendor = publicClient([
        'name' => 'IsleBooks',
        'public_client' => false,
        'client_secret_hash' => bcrypt('s3cret'),
        'redirect_uris' => ['https://islebooks.mv/manfaa/callback'],
    ]);
    [$verifier, $challenge] = pkcePair();
    $code = approvePublic($this, $vendor, $challenge, 'https://islebooks.mv/manfaa/callback');

    exchangePublic($this, $vendor, $code, $verifier, 'https://islebooks.mv/manfaa/callback')
        ->assertStatus(401)
        ->assertJsonPath('error', 'invalid_client');
});

it('refuses a callback that is not https on a public host', function (string $callback, string $reason) {
    $vendor = publicClient();
    [, $challenge] = pkcePair();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/connect/authorize', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => $callback,
            'scope' => 'transactions:write',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonFragment(['message' => 'The redirect_uri is not acceptable: '.$reason]);

    // Shown nothing, minted nothing.
    expect(OauthAuthorizationCode::query()->count())->toBe(0);
})->with([
    'plain http' => ['http://shop.example.mv/cb', 'the url must use https.'],
    'private address' => ['https://10.0.0.5/cb', 'the url must point at a public IP address.'],
    'loopback' => ['https://127.0.0.1/cb', 'the url must point at a public IP address.'],
    'localhost' => ['https://localhost/cb', 'the url must point at a public host.'],
    'fragment' => ['https://shop.example.mv/cb#frag', 'it must not carry a fragment.'],
]);

it('binds the exact callback into the code', function () {
    $vendor = publicClient();
    [$verifier, $challenge] = pkcePair();
    $code = approvePublic($this, $vendor, $challenge);

    // Same origin, different path: not the URL consented to.
    exchangePublic($this, $vendor, $code, $verifier, 'https://shop.example.mv/other')
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

it('replaces the grant when the same store reconnects, but keeps a second store', function () {
    $vendor = publicClient();

    [$v1, $c1] = pkcePair();
    $first = exchangePublic($this, $vendor, approvePublic($this, $vendor, $c1), $v1)->assertCreated()->json('access_token');

    // The consent screen knows this store is already connected.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => SHOP_CALLBACK,
            'scope' => 'transactions:write',
        ]))
        ->assertOk()
        ->assertJsonPath('data.already_connected', true);

    [$v2, $c2] = pkcePair();
    $second = exchangePublic($this, $vendor, approvePublic($this, $vendor, $c2), $v2)->assertCreated()->json('access_token');

    expect(PersonalAccessToken::findToken($first))->toBeNull();
    expect(ApiCredential::query()->whereNull('revoked_at')->count())->toBe(1);

    // A second store of the same merchant is a second connection.
    $other = 'https://second.example.mv/wp-admin/admin.php?page=manfaa-cashback';
    [$v3, $c3] = pkcePair();
    $third = exchangePublic($this, $vendor, approvePublic($this, $vendor, $c3, $other), $v3, $other)
        ->assertCreated()->json('access_token');

    expect(PersonalAccessToken::findToken($second))->not->toBeNull();
    expect(PersonalAccessToken::findToken($third))->not->toBeNull();
    expect(ApiCredential::query()->whereNull('revoked_at')->pluck('connected_from')->sort()->values()->all())
        ->toBe(['https://second.example.mv', 'https://shop.example.mv']);

    // The panel tells them apart.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/credentials')
        ->assertOk()
        ->assertJsonFragment(['connected_from' => 'https://second.example.mv'])
        ->assertJsonFragment(['connected_from' => 'https://shop.example.mv']);
});

it('registers a public client from the admin registry with no secret, and refuses to rotate it', function () {
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $created = $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/platform-clients', [
            'name' => 'Manfaa for WooCommerce',
            'public_client' => true,
            'allowed_abilities' => ['transactions:write', 'rates:read'],
            'connect_enabled' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.public_client', true)
        ->assertJsonPath('data.has_secret', false)
        ->assertJsonPath('data.client_secret', null)
        ->assertJsonPath('data.redirect_uris', []);

    $id = $created->json('data.id');

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/platform-clients/{$id}/rotate")
        ->assertStatus(409)
        ->assertJsonPath('code', 'public_client');

    // Public-ness is fixed at registration.
    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/platform-clients/{$id}", ['public_client' => false])
        ->assertStatus(422);

    // A confidential client still needs its callbacks.
    $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/platform-clients', [
            'name' => 'IsleBooks',
            'allowed_abilities' => ['transactions:write'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['redirect_uris']);
});

it('seeds the WooCommerce client idempotently', function () {
    $this->artisan('manfaa:register-woocommerce-client')->assertSuccessful();
    $this->artisan('manfaa:register-woocommerce-client')->assertSuccessful();

    $clients = PosVendor::query()->where('name', 'Manfaa for WooCommerce')->get();
    expect($clients)->toHaveCount(1);
    expect($clients->first()->isPublicClient())->toBeTrue();
    expect($clients->first()->client_secret_hash)->toBeNull();
    expect($clients->first()->canConnect())->toBeTrue();
    expect($clients->first()->allowed_abilities)->toContain('webhooks:manage');
});
