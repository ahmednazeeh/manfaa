<?php

use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\PosVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // A stand-in for a /v1 write route: bearer-token auth plus the §9.1
    // ability gate, registered here so this suite exercises ability
    // enforcement without depending on the real /v1 route file.
    Route::post('/credential-test/transactions', fn () => response()->json(['ok' => true]))
        ->middleware(['auth:sanctum', CheckAbilities::class.':transactions:write']);
});

it('creates and lists pos vendors', function () {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/pos-vendors', ['name' => 'TillPoint', 'contact' => 'dev@tillpoint.mv'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'TillPoint')
        ->assertJsonPath('data.contact', 'dev@tillpoint.mv')
        ->assertJsonPath('data.integration_status', 'pending');

    $this->getJson('/api/admin/pos-vendors')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'TillPoint')
        ->assertJsonPath('data.0.active_credentials_count', 0);
});

it('rejects unauthenticated access to the admin credential endpoints', function () {
    $merchant = Merchant::factory()->create();

    $this->postJson('/api/admin/pos-vendors', ['name' => 'X'])->assertUnauthorized();
    $this->getJson('/api/admin/pos-vendors')->assertUnauthorized();
    $this->postJson("/api/admin/merchants/{$merchant->id}/credentials", [])->assertUnauthorized();
    $this->getJson("/api/admin/merchants/{$merchant->id}/credentials")->assertUnauthorized();
    $this->deleteJson('/api/admin/credentials/1')->assertUnauthorized();
});

it('issues a credential returning the plaintext token exactly once, with issuance audited', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $response = $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
            'pos_vendor_id' => $vendor->id,
            'abilities' => ['transactions:write', 'rates:read'],
        ])
        ->assertCreated()
        ->assertJsonPath('credential.merchant_id', $merchant->id)
        ->assertJsonPath('credential.pos_vendor.name', 'TillPoint')
        ->assertJsonPath('credential.abilities', ['transactions:write', 'rates:read'])
        ->assertJsonPath('credential.issued_by', $admin->id)
        ->assertJsonPath('credential.revoked_at', null);

    $plain = $response->json('plaintext_token');
    expect($plain)->toBeString()->toContain('|');

    // The token belongs to the MERCHANT and carries exactly the granted abilities.
    $token = PersonalAccessToken::findToken($plain);
    expect($token)->not->toBeNull()
        ->and($token->tokenable)->toBeInstanceOf(Merchant::class)
        ->and($token->tokenable->id)->toBe($merchant->id)
        ->and($token->can('transactions:write'))->toBeTrue()
        ->and($token->can('rates:read'))->toBeTrue()
        ->and($token->can('transactions:reverse'))->toBeFalse()
        ->and($token->can('customers:lookup'))->toBeFalse();

    // The credential row stores the digest and token linkage, never the plaintext.
    $credential = ApiCredential::query()->sole();
    expect($credential->personal_access_token_id)->toBe($token->id)
        ->and($credential->token_hash)->toBe(hash('sha256', explode('|', $plain, 2)[1]))
        ->and((int) $credential->issued_by)->toBe($admin->id);

    // The plaintext appears only in the issuing response: listings never carry
    // it, the digest, or the token linkage.
    $listing = $this->getJson("/api/admin/merchants/{$merchant->id}/credentials")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.abilities', ['transactions:write', 'rates:read']);

    $secret = explode('|', $plain, 2)[1];
    expect($listing->getContent())
        ->not->toContain($secret)
        ->not->toContain($credential->token_hash)
        ->not->toContain('token_hash')
        ->not->toContain('plaintext');
});

it('rejects unknown abilities and empty ability lists', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
            'pos_vendor_id' => $vendor->id,
            'abilities' => ['transactions:write', 'admin:everything'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abilities.1');

    $this->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
        'pos_vendor_id' => $vendor->id,
        'abilities' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abilities');

    expect(ApiCredential::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('enforces abilities: a rates:read-only token gets 403 on a transactions:write route', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $readOnly = $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
            'pos_vendor_id' => $vendor->id,
            'abilities' => ['rates:read'],
        ])->assertCreated()->json('plaintext_token');

    $writer = $this->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
        'pos_vendor_id' => $vendor->id,
        'abilities' => ['transactions:write'],
    ])->assertCreated()->json('plaintext_token');

    // Drop cached guard users so this request authenticates from scratch.
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$readOnly)
        ->postJson('/credential-test/transactions')
        ->assertForbidden();

    // Drop cached guard users so this request authenticates from scratch.
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$writer)
        ->postJson('/credential-test/transactions')
        ->assertOk()
        ->assertJsonPath('ok', true);

    // Drop cached guard users and the sticky Authorization default header so
    // this request goes out bare — no token at all answers 401.
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->postJson('/credential-test/transactions')->assertUnauthorized();
});
