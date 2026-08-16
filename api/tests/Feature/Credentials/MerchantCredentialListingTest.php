<?php

use App\Domain\Credentials\CredentialService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('lists only the merchant\'s own credentials with vendor, abilities, last_used_at and revoked_at — never token material', function () {
    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $otherMerchant = Merchant::factory()->create();
    $vendorA = PosVendor::query()->create(['name' => 'TillPoint']);
    $vendorB = PosVendor::query()->create(['name' => 'RetailSoft']);

    $service = app(CredentialService::class);
    $active = $service->issue($merchant, $vendorA, ['transactions:write', 'rates:read'], $admin);
    $revoked = $service->issue($merchant, $vendorB, ['rates:read'], $admin);
    $foreign = $service->issue($otherMerchant, $vendorA, ['transactions:write'], $admin);

    $service->revoke($revoked->credential, $admin);
    $active->credential->forceFill([
        'last_used_at' => CarbonImmutable::parse('2026-08-14T09:30:00+00:00'),
    ])->save();

    // Owner-only surface (PLAN §1: API credentials sit outside the manager
    // tier) — the listing names every POS vendor holding a write token.
    $merchantUser = MerchantUser::factory()->for($merchant)->owner()->create();

    $response = $this->actingAs($merchantUser, 'merchant')
        ->getJson('/api/merchant/credentials')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        // Ordered newest-first: the revoked vendorB credential, then vendorA.
        ->assertJsonPath('data.0.pos_vendor.name', 'RetailSoft')
        ->assertJsonPath('data.0.abilities', ['rates:read'])
        ->assertJsonPath('data.1.pos_vendor.name', 'TillPoint')
        ->assertJsonPath('data.1.abilities', ['transactions:write', 'rates:read'])
        ->assertJsonPath('data.1.last_used_at', '2026-08-14T09:30:00+00:00')
        ->assertJsonPath('data.1.revoked_at', null);

    expect($response->json('data.0.revoked_at'))->not->toBeNull();

    // The other merchant's credential never appears.
    expect(collect($response->json('data'))->pluck('id')->all())
        ->not->toContain($foreign->credential->id);

    // No token values, digests, or plaintext fragments anywhere in the body.
    $body = $response->getContent();
    expect($body)
        ->not->toContain('token_hash')
        ->not->toContain('plaintext')
        ->not->toContain($active->credential->token_hash)
        ->not->toContain($revoked->credential->token_hash)
        ->not->toContain(explode('|', $active->plainTextToken, 2)[1]);
});

it('requires a merchant session', function () {
    $this->getJson('/api/merchant/credentials')->assertUnauthorized();
});

it('hides the credential listing from managers and staff', function () {
    $merchant = Merchant::factory()->create();

    foreach (['manager', 'staff'] as $preset) {
        $user = MerchantUser::factory()->for($merchant)->{$preset}()->create();

        $this->actingAs($user, 'merchant')
            ->getJson('/api/merchant/credentials')
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_required')
            ->assertJsonPath('permission', 'api_credentials.view');
    }
});
