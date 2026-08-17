<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\RolePresetService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->roles = app(RolePresetService::class)->provision($this->merchant);
});

it('creates a staff account returning the temporary password exactly once', function () {
    $this->actingAs($this->owner, 'merchant');

    $response = $this->postJson('/api/merchant/staff', [
        'name' => 'New Staff',
        'email' => 'new.staff@example.com',
        'merchant_role_id' => $this->roles[RolePresetService::STAFF]->id,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'New Staff')
        ->assertJsonPath('data.role.name', 'Staff')
        ->assertJsonPath('data.role.is_owner', false)
        ->assertJsonPath('data.is_active', true);

    $tempPassword = $response->json('temp_password');
    $created = MerchantUser::query()->where('email', 'new.staff@example.com')->sole();

    expect($tempPassword)->toBeString()->not->toBeEmpty()
        ->and(Hash::check($tempPassword, $created->password))->toBeTrue()
        ->and($created->merchant_id)->toBe($this->merchant->id)
        ->and($created->merchant_role_id)->toBe($this->roles[RolePresetService::STAFF]->id);

    // Never surfaced again: the listing carries no password material.
    $listing = $this->getJson('/api/merchant/staff')->assertOk()->assertJsonCount(2, 'data');
    expect($listing->getContent())->not->toContain($tempPassword);
});

it('rejects a duplicate staff email', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/staff', [
        'name' => 'Dup',
        'email' => $this->owner->email,
        'merchant_role_id' => $this->roles[RolePresetService::STAFF]->id,
    ])->assertUnprocessable();
});

it('refuses login for a deactivated user without an account-state oracle', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    MerchantUser::factory()->for($this->merchant)->create([
        'email' => 'inactive@example.com',
        'is_active' => false,
    ]);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->getJson('/api/merchant/auth/me')->assertUnauthorized();
});

it('kills a live session on the next request after deactivation', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $staff = MerchantUser::factory()->for($this->merchant)->create([
        'email' => 'live.staff@example.com',
    ]);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'live.staff@example.com',
        'password' => 'password',
    ])->assertOk();

    $this->getJson('/api/merchant/auth/me')->assertOk()->assertJsonPath('data.email', 'live.staff@example.com');

    // Deactivated behind the session's back.
    MerchantUser::query()->whereKey($staff->id)->update(['is_active' => false]);

    // Force the guard to re-resolve from the session on the next request —
    // in production every request re-resolves; the test app caches guards.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/merchant/auth/me')->assertUnauthorized();
});

it('deactivates and reactivates a staff member', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$staff->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/merchant/staff/{$staff->id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('promotes staff to owner and demotes them back', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$staff->id}", [
        'merchant_role_id' => $this->roles[RolePresetService::OWNER]->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.role.is_owner', true);

    $this->patchJson("/api/merchant/staff/{$staff->id}", [
        'merchant_role_id' => $this->roles[RolePresetService::STAFF]->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.role.is_owner', false);
});

it('refuses self-deactivation and self-demotion', function () {
    // A second active owner exists, so the last-owner guard passes and the
    // SELF guard is what refuses.
    MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['is_active' => false])
        ->assertUnprocessable();
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", [
        'merchant_role_id' => $this->roles[RolePresetService::STAFF]->id,
    ])->assertUnprocessable();

    expect($this->owner->refresh()->isOwner())->toBeTrue()
        ->and($this->owner->is_active)->toBeTrue();
});

it('never removes the last active owner', function () {
    // The other owner is inactive, so $this->owner is the last ACTIVE one.
    MerchantUser::factory()->for($this->merchant)->owner()->create(['is_active' => false]);

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['is_active' => false])
        ->assertUnprocessable();
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", [
        'merchant_role_id' => $this->roles[RolePresetService::STAFF]->id,
    ])->assertUnprocessable();

    expect($this->owner->refresh()->isOwner())->toBeTrue()
        ->and($this->owner->is_active)->toBeTrue();
});

it('edits a staff member\'s name and email, refusing a duplicate email like the invite does (MR8)', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$staff->id}", ['name' => 'Renamed Cashier'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Cashier');

    $this->patchJson("/api/merchant/staff/{$staff->id}", ['email' => 'renamed.cashier@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'renamed.cashier@example.com');

    expect($staff->refresh()->name)->toBe('Renamed Cashier')
        ->and($staff->email)->toBe('renamed.cashier@example.com');

    // Another account's email is refused exactly as a duplicate invite is…
    $this->patchJson("/api/merchant/staff/{$staff->id}", ['email' => $this->owner->email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    // …while re-submitting the target's OWN email is a no-op, not a clash.
    $this->patchJson("/api/merchant/staff/{$staff->id}", ['email' => 'renamed.cashier@example.com'])
        ->assertOk();

    expect($staff->refresh()->email)->toBe('renamed.cashier@example.com');
});

it('resets a staff password: temp password shown once, old password and app tokens dead (MR8)', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create([
        'email' => 'reset.me@example.com',
    ]);

    // A signed-in phone: the reset must DESTROY this token, not merely
    // refuse it — parity with deactivation's revokeEverything.
    app(MobileTokenService::class)->issue($staff, MobileAudience::Merchant, 'Phone');
    expect($staff->tokens()->count())->toBe(1);

    $this->actingAs($this->owner, 'merchant');

    $response = $this->postJson("/api/merchant/staff/{$staff->id}/reset-password")
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id);

    $tempPassword = $response->json('temp_password');

    expect($tempPassword)->toBeString()->not->toBeEmpty()
        ->and(Hash::check($tempPassword, $staff->refresh()->password))->toBeTrue()
        // The factory password is dead — only the fresh hash survives.
        ->and(Hash::check('password', $staff->password))->toBeFalse()
        ->and($staff->tokens()->count())->toBe(0);

    // Never surfaced again: the listing carries no password material.
    $listing = $this->getJson('/api/merchant/staff')->assertOk();
    expect($listing->getContent())->not->toContain($tempPassword);
});

it('signs in with the temp password after a reset; the old password refuses (MR8)', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $staff = MerchantUser::factory()->for($this->merchant)->create([
        'email' => 'reset.login@example.com',
    ]);

    $tempPassword = $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/staff/{$staff->id}/reset-password")
        ->assertOk()
        ->json('temp_password');

    // Fresh guard state for the login attempts below.
    app('auth')->forgetGuards();

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'reset.login@example.com',
        'password' => 'password',
    ])->assertUnprocessable();

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'reset.login@example.com',
        'password' => $tempPassword,
    ])->assertOk();
});

it('lets an owner reset their OWN password — a reset is not a deactivation (MR8)', function () {
    // No self guard and no last-owner guard: nothing is being removed, and
    // the owner walks straight back in with the fresh password.
    $this->actingAs($this->owner, 'merchant');

    $tempPassword = $this->postJson("/api/merchant/staff/{$this->owner->id}/reset-password")
        ->assertOk()
        ->json('temp_password');

    expect(Hash::check($tempPassword, $this->owner->refresh()->password))->toBeTrue()
        ->and($this->owner->is_active)->toBeTrue()
        ->and($this->owner->isOwner())->toBeTrue();
});

it('gates the password reset on staff.edit and scopes it to the merchant (MR8)', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create();
    $foreignStaff = MerchantUser::factory()->create(); // other merchant

    // The Staff preset holds no staff.* slug — the reset refuses 403.
    $till = MerchantUser::factory()->for($this->merchant)
        ->withRole($this->roles[RolePresetService::STAFF])
        ->create();

    $this->actingAs($till, 'merchant')
        ->postJson("/api/merchant/staff/{$staff->id}/reset-password")
        ->assertForbidden();

    // A foreign target is a 404 through the users relation, never a hit.
    $originalHash = $foreignStaff->password;

    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/staff/{$foreignStaff->id}/reset-password")
        ->assertNotFound();

    expect($foreignStaff->refresh()->password)->toBe($originalHash);
});

it('scopes staff management to the authenticated merchant', function () {
    $foreignStaff = MerchantUser::factory()->create(); // other merchant

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$foreignStaff->id}", ['is_active' => false])
        ->assertNotFound();

    expect($foreignStaff->refresh()->is_active)->toBeTrue();
});

it('an owner of a DIFFERENT merchant with the same guard cannot see this staff list', function () {
    MerchantUser::factory()->for($this->merchant)->create();
    $foreignOwner = MerchantUser::factory()->owner()->create(); // other merchant

    $this->actingAs($foreignOwner, 'merchant');

    $this->getJson('/api/merchant/staff')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $foreignOwner->id);
});
