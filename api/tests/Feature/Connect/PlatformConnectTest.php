<?php

declare(strict_types=1);

use App\Domain\Credentials\CredentialService;
use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\OauthAuthorizationCode;
use App\Models\PosVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PLATFORM CONNECT — "IsleBooks would like to … Authorise / Deny".
 *
 * Two gates stand between a stranger and a shopkeeper's till:
 *
 *   1. a SUPERADMIN registers the platform, because the right to put a
 *      consent screen in front of any merchant on Manfaa is a privilege,
 *      not something a developer grants themselves;
 *   2. the MERCHANT answers the question, and can only be asked for what
 *      the superadmin allowed the platform to ask for.
 *
 * The token that comes out does not expire (owner decision), which makes
 * every check below load-bearing: there is no clock that quietly cleans up
 * after a mistake.
 */

beforeEach(function () {
    // A stand-in /v1 write route — bearer auth plus the ability gate — so
    // this suite proves the issued token really works without dragging in
    // the whole transactions pipeline.
    Route::post('/connect-test/write', fn () => response()->json(['ok' => true]))
        ->middleware(['auth:sanctum', CheckAbilities::class.':transactions:write']);

    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

/** A registered, connect-enabled platform, plus the secret it was given. */
function platform(array $overrides = []): array
{
    $secret = Str::random(48);

    $vendor = PosVendor::query()->create(array_merge([
        'name' => 'IsleBooks',
        'display_name' => 'IsleBooks',
        'description' => 'Bookkeeping for Maldivian shops',
        'website' => 'https://islebooks.mv',
        'integration_status' => 'active',
        'client_id' => 'mfa_'.Str::lower(Str::random(24)),
        'client_secret_hash' => Hash::make($secret),
        'redirect_uris' => ['https://islebooks.mv/manfaa/callback'],
        'allowed_abilities' => ['transactions:write', 'rates:read'],
        'connect_enabled' => true,
    ], $overrides));

    return [$vendor, $secret];
}

/** A PKCE pair: the verifier the platform keeps, the challenge it sends. */
function pkce(): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

/* ------------------------------------------------------------------ *
 * Gate one: who may ask.
 * ------------------------------------------------------------------ */

it('registers a platform for a superadmin, showing the secret exactly once', function () {
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $response = $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/platform-clients', [
            'name' => 'IsleBooks',
            'display_name' => 'IsleBooks',
            'website' => 'https://islebooks.mv',
            'redirect_uris' => ['https://islebooks.mv/manfaa/callback'],
            'allowed_abilities' => ['transactions:write', 'rates:read'],
            'connect_enabled' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.connect_enabled', true)
        ->assertJsonPath('data.allowed_abilities', ['transactions:write', 'rates:read']);

    $secret = $response->json('data.client_secret');
    expect($secret)->toBeString()->and(strlen($secret))->toBe(48);

    // Never readable again — not on the listing, not anywhere.
    $listed = $this->getJson('/api/admin/platform-clients')->assertOk();
    expect($listed->json('data.0'))->not->toHaveKey('client_secret');
    expect($listed->json('data.0.has_secret'))->toBeTrue();
});

it('refuses platform registration to a non-superadmin admin', function () {
    $admin = AdminUser::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/platform-clients', [
            'name' => 'IsleBooks',
            'redirect_uris' => ['https://islebooks.mv/cb'],
            'allowed_abilities' => ['transactions:write'],
        ])
        ->assertForbidden();

    expect(PosVendor::query()->count())->toBe(0);
});

it('refuses a redirect uri that is not https', function () {
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/platform-clients', [
            'name' => 'IsleBooks',
            'redirect_uris' => ['http://islebooks.mv/cb'],
            'allowed_abilities' => ['transactions:write'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('redirect_uris.0');
});

/* ------------------------------------------------------------------ *
 * The consent screen: what the shopkeeper is shown.
 * ------------------------------------------------------------------ */

it('shows the consent screen with a sentence per permission and writes nothing', function () {
    [$vendor] = platform();

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write rates:read',
        ]))
        ->assertOk()
        ->assertJsonPath('data.application.name', 'IsleBooks')
        ->assertJsonPath('data.store.name', $this->merchant->name)
        ->assertJsonPath('data.permissions.0.ability', 'transactions:write')
        ->assertJsonPath('data.permissions.1.ability', 'rates:read')
        ->assertJsonPath('data.already_connected', false);

    // Opening the question and walking away grants nothing.
    expect(OauthAuthorizationCode::query()->count())->toBe(0);
    expect(ApiCredential::query()->count())->toBe(0);
})->group('connect');

it('refuses an unregistered client', function () {
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => 'mfa_nobody',
            'redirect_uri' => 'https://elsewhere.mv/cb',
            'scope' => 'transactions:write',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_client');
});

it('refuses a platform that is registered but not connect-enabled', function () {
    [$vendor] = platform(['connect_enabled' => false]);

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_client');
});

it('refuses a scope beyond the ceiling the superadmin set', function () {
    // Registered for bookkeeping; asking for customer names anyway.
    [$vendor] = platform(['allowed_abilities' => ['transactions:write']]);

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write customers:lookup',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_scope');
});

it('refuses a redirect uri that is not the registered one', function () {
    [$vendor] = platform();

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            // A prefix of the real one — exact match, never a prefix.
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback.evil.mv',
            'scope' => 'transactions:write',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_request');
});

it('refuses a shop assistant who lacks the permission to issue keys', function () {
    [$vendor] = platform();
    [, $challenge] = pkce();
    $staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($staff, 'merchant')
        ->postJson('/api/merchant/connect/authorize', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->assertForbidden();

    expect(OauthAuthorizationCode::query()->count())->toBe(0);
});

it('refuses an unauthenticated visitor at every merchant door', function () {
    [$vendor] = platform();

    $this->getJson('/api/merchant/connect/authorize?client_id='.$vendor->client_id)->assertUnauthorized();
    $this->postJson('/api/merchant/connect/authorize', [])->assertUnauthorized();
    $this->postJson('/api/merchant/connect/deny', [])->assertUnauthorized();
});

/* ------------------------------------------------------------------ *
 * The handshake, end to end.
 * ------------------------------------------------------------------ */

it('completes the handshake: authorise, exchange, and the token works', function () {
    [$vendor, $secret] = platform();
    [$verifier, $challenge] = pkce();

    $approved = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/connect/authorize', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write rates:read',
            'state' => 'xyz-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->assertOk();

    $redirect = $approved->json('data.redirect_to');
    expect($redirect)->toStartWith('https://islebooks.mv/manfaa/callback?');
    // The state is echoed untouched so the platform can match its own request.
    expect($redirect)->toContain('state=xyz-123');

    parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
    $code = $query['code'];

    // The code is at rest hashed — a database reader cannot spend it.
    expect(OauthAuthorizationCode::query()->where('code_hash', $code)->exists())->toBeFalse();

    // Server to server. No session, no merchant login.
    app('auth')->forgetGuards();

    $token = $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])
        ->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('scope', 'transactions:write rates:read')
        ->assertJsonPath('merchant.id', $this->merchant->id)
        // No expiry, deliberately: an accounting integration that dies at
        // three in the morning is the worse outcome.
        ->assertJsonMissingPath('expires_in');

    $plain = $token->json('access_token');

    $issued = PersonalAccessToken::findToken($plain);
    expect($issued->tokenable_id)->toBe($this->merchant->id);
    expect($issued->abilities)->toBe(['transactions:write', 'rates:read']);
    expect($issued->expires_at)->toBeNull();

    // And it actually opens the door it was issued for.
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/connect-test/write')
        ->assertOk();

    // Audited to the person who pressed the button, not to an admin.
    $credential = ApiCredential::query()->firstOrFail();
    expect($credential->issued_by_merchant_user)->toBe($this->owner->id);
    expect($credential->pos_vendor_id)->toBe($vendor->id);
});

it('never grants an ability the shopkeeper was not asked about', function () {
    [$vendor, $secret] = platform();
    [$verifier, $challenge] = pkce();

    $code = authorise($this, $vendor, $challenge, 'rates:read');

    app('auth')->forgetGuards();
    $plain = $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])->assertCreated()->json('access_token');

    // Approved for reading rates; writing money is still shut.
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/connect-test/write')
        ->assertForbidden();
});

/** Walk the merchant half and hand back the raw code. */
function authorise(TestCase $test, PosVendor $vendor, string $challenge, string $scope = 'transactions:write'): string
{
    $redirect = $test->actingAs(test()->owner, 'merchant')
        ->postJson('/api/merchant/connect/authorize', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => $scope,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->assertOk()
        ->json('data.redirect_to');

    parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

    return $query['code'];
}

/* ------------------------------------------------------------------ *
 * Everything that must NOT produce a token.
 * ------------------------------------------------------------------ */

it('refuses the exchange when the client secret is wrong', function () {
    [$vendor] = platform();
    [$verifier, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => Str::random(48),
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_client');

    expect(ApiCredential::query()->count())->toBe(0);
});

it('refuses the exchange when the pkce verifier does not match', function () {
    [$vendor, $secret] = platform();
    [, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    app('auth')->forgetGuards();

    // A stolen code, without the verifier that never left the platform.
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => Str::random(64),
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect(ApiCredential::query()->count())->toBe(0);
});

it('spends a code exactly once', function () {
    [$vendor, $secret] = platform();
    [$verifier, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    $body = [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ];

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', $body)->assertCreated();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', $body)
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect(ApiCredential::query()->whereNull('revoked_at')->count())->toBe(1);
});

it('refuses a code past its minute', function () {
    [$vendor, $secret] = platform();
    [$verifier, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    $this->travel(61)->seconds();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect(ApiCredential::query()->count())->toBe(0);
});

it('refuses a code presented by a different platform', function () {
    [$vendor] = platform();
    [$thief, $thiefSecret] = platform([
        'name' => 'Rival',
        'client_id' => 'mfa_'.Str::lower(Str::random(24)),
        'redirect_uris' => ['https://islebooks.mv/manfaa/callback'],
    ]);
    [$verifier, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $thief->client_id,
        'client_secret' => $thiefSecret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect(ApiCredential::query()->count())->toBe(0);
});

it('refuses the exchange when the redirect uri differs from the one consented to', function () {
    [$vendor, $secret] = platform([
        'redirect_uris' => [
            'https://islebooks.mv/manfaa/callback',
            'https://islebooks.mv/other/callback',
        ],
    ]);
    [$verifier, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    app('auth')->forgetGuards();

    // Registered, but not the one this code was minted against.
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/other/callback',
        'code_verifier' => $verifier,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

/* ------------------------------------------------------------------ *
 * Living with a token that never expires.
 * ------------------------------------------------------------------ */

it('replaces the previous grant when a shop re-authorises the same platform', function () {
    [$vendor, $secret] = platform();

    $exchange = function (string $verifier, string $code) use ($vendor, $secret) {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/connect/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $vendor->client_id,
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'code_verifier' => $verifier,
        ])->assertCreated()->json('access_token');
    };

    [$verifier1, $challenge1] = pkce();
    $first = $exchange($verifier1, authorise($this, $vendor, $challenge1));

    // The consent screen warns before, not after.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write',
        ]))
        ->assertOk()
        ->assertJsonPath('data.already_connected', true);

    [$verifier2, $challenge2] = pkce();
    $second = $exchange($verifier2, authorise($this, $vendor, $challenge2));

    expect($second)->not->toBe($first);

    // Exactly one live grant — with no expiry, stacking would mean
    // forgotten tokens living forever.
    expect(ApiCredential::query()->whereNull('revoked_at')->count())->toBe(1);
    expect(PersonalAccessToken::findToken($first))->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$second)
        ->postJson('/connect-test/write')
        ->assertOk();
});

it('cuts every live connection when the platform secret is rotated', function () {
    [$vendor, $secret] = platform();
    [$verifier, $challenge] = pkce();
    $code = authorise($this, $vendor, $challenge);

    app('auth')->forgetGuards();
    $plain = $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])->assertCreated()->json('access_token');

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    app('auth')->forgetGuards();
    $rotated = $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/platform-clients/{$vendor->id}/rotate")
        ->assertOk()
        ->assertJsonPath('data.connections_revoked', 1);

    expect($rotated->json('data.client_secret'))->not->toBe($secret);

    // Rotation happens because a secret leaked; leaving the grants alive
    // would mean rotating changed nothing for whoever already holds them.
    expect(PersonalAccessToken::findToken($plain))->toBeNull();

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/connect-test/write')
        ->assertUnauthorized();
});

it('answers a denial plainly instead of leaving the platform waiting', function () {
    [$vendor] = platform();

    $redirect = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/connect/deny', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'state' => 'xyz-123',
        ])
        ->assertOk()
        ->json('data.redirect_to');

    expect($redirect)->toContain('error=access_denied')->toContain('state=xyz-123');

    expect(OauthAuthorizationCode::query()->count())->toBe(0);
    expect(ApiCredential::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------ *
 * The store's own ceiling on live credentials.
 * ------------------------------------------------------------------ */

it('refuses at the consent screen when the store is already at its credential cap', function () {
    [$vendor] = platform();
    [, $challenge] = pkce();

    // Ten live credentials — the maximum a store may hold.
    for ($i = 0; $i < CredentialService::MAX_ACTIVE_PER_MERCHANT; $i++) {
        app(CredentialService::class)->issueForMerchantUser(
            $this->merchant,
            'Till '.$i,
            ['transactions:write'],
            $this->owner,
        );
    }

    // The screen says so BEFORE the button, not after the redirect.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write',
        ]))
        ->assertOk()
        ->assertJsonPath('data.blocked_reason', fn (?string $reason) => is_string($reason));

    // And pressing it anyway refuses cleanly — no code, no 500.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/connect/authorize', [
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'access_denied');

    expect(OauthAuthorizationCode::query()->count())->toBe(0);
});

it('lets a store at its cap re-authorise an app it is already connected to', function () {
    [$vendor, $secret] = platform();
    [$verifier, $challenge] = pkce();

    // Connect first, then fill the rest of the store's allowance.
    $code = authorise($this, $vendor, $challenge);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier,
    ])->assertCreated();

    while (
        ApiCredential::query()->whereNull('revoked_at')->count()
        < CredentialService::MAX_ACTIVE_PER_MERCHANT
    ) {
        app(CredentialService::class)->issueForMerchantUser(
            $this->merchant,
            'Till '.ApiCredential::query()->count(),
            ['transactions:write'],
            $this->owner,
        );
    }

    // Re-authorising REPLACES, so it never adds to the count — the cap must
    // not lock a shop out of reconnecting software it already runs.
    [$verifier2, $challenge2] = pkce();

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/connect/authorize?'.http_build_query([
            'client_id' => $vendor->client_id,
            'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
            'scope' => 'transactions:write',
        ]))
        ->assertOk()
        ->assertJsonPath('data.blocked_reason', null)
        ->assertJsonPath('data.already_connected', true);

    $code2 = authorise($this, $vendor, $challenge2);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/connect/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $vendor->client_id,
        'client_secret' => $secret,
        'code' => $code2,
        'redirect_uri' => 'https://islebooks.mv/manfaa/callback',
        'code_verifier' => $verifier2,
    ])->assertCreated();

    expect(ApiCredential::query()->whereNull('revoked_at')->count())
        ->toBe(CredentialService::MAX_ACTIVE_PER_MERCHANT);
});
