<?php

use App\Domain\Credentials\CredentialService;
use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Route::get('/credential-test/rate', fn () => response()->json(['rate_bp' => 200]))
        ->middleware(['auth:sanctum', CheckAbilities::class.':rates:read']);
});

it('revokes one credential: auth dies immediately, the merchant\'s other credential keeps working', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendorA = PosVendor::query()->create(['name' => 'TillPoint']);
    $vendorB = PosVendor::query()->create(['name' => 'RetailSoft']);

    $service = app(CredentialService::class);
    $issuedA = $service->issue($merchant, $vendorA, ['rates:read'], $admin);
    $issuedB = $service->issue($merchant, $vendorB, ['rates:read'], $admin);

    // Both credentials authenticate before any revocation.
    $this->withHeader('Authorization', 'Bearer '.$issuedA->plainTextToken)
        ->getJson('/credential-test/rate')->assertOk();
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issuedB->plainTextToken)
        ->getJson('/credential-test/rate')->assertOk();
    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/admin/credentials/{$issuedA->credential->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $issuedA->credential->id)
        ->assertJsonPath('data.revoked_by', $admin->id);

    // The Sanctum token is gone; the credential row survives as audit.
    expect(PersonalAccessToken::query()->find($issuedA->credential->personal_access_token_id))->toBeNull();

    $revoked = $issuedA->credential->refresh();
    expect($revoked->revoked_at)->not->toBeNull()
        ->and((int) $revoked->revoked_by)->toBe($admin->id)
        ->and((int) $revoked->issued_by)->toBe($admin->id)
        // The audit linkage to the deleted token id is retained.
        ->and($revoked->personal_access_token_id)->not->toBeNull();

    // Revoked token answers 401 immediately; the sibling credential is untouched.
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issuedA->plainTextToken)
        ->getJson('/credential-test/rate')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issuedB->plainTextToken)
        ->getJson('/credential-test/rate')->assertOk();

    expect($issuedB->credential->refresh()->revoked_at)->toBeNull();
});

it('revokes across merchants by id without touching other merchants', function () {
    $admin = AdminUser::factory()->create();
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $service = app(CredentialService::class);
    $issuedA = $service->issue($merchantA, $vendor, ['rates:read'], $admin);
    $issuedB = $service->issue($merchantB, $vendor, ['rates:read'], $admin);

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/admin/credentials/{$issuedB->credential->id}")
        ->assertOk();

    // Merchant B's credential is dead, merchant A's untouched — a vendor
    // switch never forces rotation across other merchants.
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issuedB->plainTextToken)
        ->getJson('/credential-test/rate')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issuedA->plainTextToken)
        ->getJson('/credential-test/rate')->assertOk();

    expect($issuedA->credential->refresh()->revoked_at)->toBeNull()
        ->and($issuedB->credential->refresh()->revoked_at)->not->toBeNull();
});

it('rejects revocation from anyone but an admin', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $issued = app(CredentialService::class)->issue($merchant, $vendor, ['rates:read'], $admin);

    // Unauthenticated.
    $this->deleteJson("/api/admin/credentials/{$issued->credential->id}")->assertUnauthorized();

    // A merchant session is not an admin session — even for their own credential.
    $merchantUser = MerchantUser::factory()->for($merchant)->create();
    $this->actingAs($merchantUser, 'merchant')
        ->deleteJson("/api/admin/credentials/{$issued->credential->id}")
        ->assertUnauthorized();

    expect($issued->credential->refresh()->revoked_at)->toBeNull()
        ->and(PersonalAccessToken::query()->find($issued->credential->personal_access_token_id))->not->toBeNull();
});

it('answers 409 on double revocation and 404 for an unknown credential id', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $issued = app(CredentialService::class)->issue($merchant, $vendor, ['rates:read'], $admin);

    $this->actingAs($admin, 'admin');
    $this->deleteJson("/api/admin/credentials/{$issued->credential->id}")->assertOk();
    $this->deleteJson("/api/admin/credentials/{$issued->credential->id}")->assertConflict();
    $this->deleteJson('/api/admin/credentials/999999')->assertNotFound();

    // Double revocation never rewrites the original audit stamp.
    $first = ApiCredential::query()->findOrFail($issued->credential->id);
    expect((int) $first->revoked_by)->toBe($admin->id);
});
