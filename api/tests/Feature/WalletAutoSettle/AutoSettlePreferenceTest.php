<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The auto-settle switch (owner, 2026-08-24): read wherever the merchant
 * looks at the money — the wallet payload and the preferences resource —
 * and written through ONE path, PATCH /merchant/preferences, behind
 * preferences.update.
 */

beforeEach(function (): void {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');
});

it('defaults ON for a new store and shows on the wallet payload', function (): void {
    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeTrue();

    $this->getJson('/api/merchant/wallet')
        ->assertSuccessful()
        ->assertJsonPath('data.auto_settle_from_wallet', true)
        ->assertJsonPath('data.balance_laari', 0);
});

it('is switched through the preferences endpoint and the wallet payload follows', function (): void {
    $this->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => false])
        ->assertOk()
        ->assertJsonPath('data.auto_settle_from_wallet', false);

    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeFalse();

    $this->getJson('/api/merchant/wallet')
        ->assertSuccessful()
        ->assertJsonPath('data.auto_settle_from_wallet', false);

    $this->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => true])
        ->assertOk()
        ->assertJsonPath('data.auto_settle_from_wallet', true);

    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeTrue();

    // Untouched by a PATCH that does not mention it.
    $this->patchJson('/api/merchant/preferences', ['min_eligible_laari' => 1000])
        ->assertOk()
        ->assertJsonPath('data.auto_settle_from_wallet', true)
        ->assertJsonPath('data.min_eligible_laari', 1000);
});

it('accepts only a boolean', function (): void {
    $this->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('auto_settle_from_wallet');

    $this->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => null])
        ->assertUnprocessable();

    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeTrue();
});

it('is not writable by staff without preferences.update, and the wallet route cannot write it', function (): void {
    $staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($staff, 'merchant')
        ->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => false])
        ->assertForbidden();

    // No wallet-scoped write path exists: one write path, one permission.
    $this->actingAs($this->owner, 'merchant')
        ->patchJson('/api/merchant/wallet', ['auto_settle_from_wallet' => false])
        ->assertStatus(405);

    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeTrue();
});

it('is a Money decision: preferences.update alone cannot switch it', function (): void {
    $role = MerchantRole::query()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Settings only',
        'slug' => 'settings-only',
        'permissions' => ['preferences.update'],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $clerk = MerchantUser::factory()->for($this->merchant)->withRole($role)->create();

    $this->actingAs($clerk, 'merchant')
        ->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => false])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_required')
        ->assertJsonPath('permission', 'wallet.settle');

    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeTrue();

    // The other knobs are still theirs.
    $this->patchJson('/api/merchant/preferences', ['min_eligible_laari' => 500])->assertOk();

    // With wallet.settle as well, the toggle moves.
    $role->forceFill(['permissions' => ['preferences.update', 'wallet.settle']])->save();

    $this->actingAs($clerk->fresh(), 'merchant')
        ->patchJson('/api/merchant/preferences', ['auto_settle_from_wallet' => false])->assertOk();

    expect($this->merchant->refresh()->auto_settle_from_wallet)->toBeFalse();
});
