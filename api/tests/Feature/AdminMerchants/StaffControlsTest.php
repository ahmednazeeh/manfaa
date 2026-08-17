<?php

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * In-test requests share one app instance, so resolved guards memoise the
 * user they loaded on an earlier request. Production requests construct
 * fresh guards every time; this models that between two requests.
 */
function freshStaffControlGuards(): void
{
    app('auth')->forgetGuards();
}

it('rejects unauthenticated and non-superadmin staff writes', function () {
    $staff = MerchantUser::factory()->staff()->create();
    $merchantId = $staff->merchant_id;

    $this->postJson("/api/admin/merchants/{$merchantId}/staff/{$staff->id}/reset-password")
        ->assertUnauthorized();
    $this->patchJson("/api/admin/merchants/{$merchantId}/staff/{$staff->id}", ['is_active' => false])
        ->assertUnauthorized();

    $this->actingAs(AdminUser::factory()->create(), 'admin');

    $this->postJson("/api/admin/merchants/{$merchantId}/staff/{$staff->id}/reset-password")
        ->assertForbidden();
    $this->patchJson("/api/admin/merchants/{$merchantId}/staff/{$staff->id}", ['is_active' => false])
        ->assertForbidden();

    expect($staff->refresh()->is_active)->not->toBeFalse();
});

it('resolves the staff member through the route merchant, so a cross-tenant id is a 404', function () {
    $staff = MerchantUser::factory()->staff()->create();
    $otherMerchant = Merchant::factory()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    $this->postJson("/api/admin/merchants/{$otherMerchant->id}/staff/{$staff->id}/reset-password")
        ->assertNotFound();
    $this->patchJson("/api/admin/merchants/{$otherMerchant->id}/staff/{$staff->id}", ['is_active' => false])
        ->assertNotFound();
});

it('resets a staff password: returns a working temp password once and destroys app tokens', function () {
    $staff = MerchantUser::factory()->staff()->create();
    $staff->createToken('old-phone', ['mobile:merchant']);
    expect($staff->tokens()->count())->toBe(1);

    $response = $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$staff->merchant_id}/staff/{$staff->id}/reset-password")
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id);

    $temp = $response->json('temp_password');
    expect($temp)->toBeString()->and(strlen($temp))->toBeGreaterThanOrEqual(20);

    $staff->refresh();

    // The returned password is the one that now works; the old one is dead.
    expect(Hash::check($temp, $staff->password))->toBeTrue()
        ->and(Hash::check('password', $staff->password))->toBeFalse()
        // And the phone that held the old credentials is signed out for good.
        ->and($staff->tokens()->count())->toBe(0);
});

it('kills the live merchant panel session when a superadmin resets the password', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $staff = MerchantUser::factory()->owner()->create(['email' => 'owner@demo.manfaa.app']);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'owner@demo.manfaa.app',
        'password' => 'password',
    ])->assertOk();

    $this->getJson('/api/merchant/auth/me')->assertOk();
    freshStaffControlGuards();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$staff->merchant_id}/staff/{$staff->id}/reset-password")
        ->assertOk();
    freshStaffControlGuards();

    // The session's stored password hash no longer matches — the very next
    // request is logged out (AuthenticateMultiGuardSession).
    $this->getJson('/api/merchant/auth/me')->assertUnauthorized();
});

it('deactivates and reactivates a staff member, destroying app tokens on the way out', function () {
    $merchant = Merchant::factory()->create();
    // A second active owner so the last-owner guard stays out of the way.
    MerchantUser::factory()->owner()->for($merchant)->create();
    $staff = MerchantUser::factory()->staff()->for($merchant)->create();
    $staff->createToken('phone', ['mobile:merchant']);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}/staff/{$staff->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $staff->refresh();
    expect($staff->is_active)->toBeFalse()
        // Deactivation destroys the app credentials, not merely refuses them.
        ->and($staff->tokens()->count())->toBe(0);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}/staff/{$staff->id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($staff->refresh()->is_active)->toBeTrue();
});

it('refuses to deactivate the last active owner — the store must never be locked out of its own settings', function () {
    $owner = MerchantUser::factory()->owner()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->patchJson("/api/admin/merchants/{$owner->merchant_id}/staff/{$owner->id}", ['is_active' => false])
        ->assertStatus(422);

    expect($owner->refresh()->is_active)->not->toBeFalse();
});
