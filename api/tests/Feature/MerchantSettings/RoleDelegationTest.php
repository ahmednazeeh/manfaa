<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\RolePresetService;
use App\Domain\MerchantSettings\RoleService;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The security core of the round (PLAN §13b §3.2, D5): `roles.manage` is a
// permission, not a second way to spell owner. Everything here is about the
// two ways a store could otherwise be handed away — delegating authority the
// delegator never held, and attaching an account to a role belonging to a
// different store entirely.
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->roles = app(RolePresetService::class)->provision($this->merchant);

    // A deliberately partial delegate: enough to run the roles and staff
    // screens, nothing that touches the money.
    $this->supervisorRole = MerchantRole::query()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Supervisor',
        'slug' => 'supervisor',
        'permissions' => ['transactions.view', 'staff.view', 'staff.invite', 'staff.edit', 'roles.view', 'roles.manage'],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $this->supervisor = MerchantUser::factory()->for($this->merchant)->withRole($this->supervisorRole)->create();
});

it('refuses another merchant\'s role id exactly as it refuses one that does not exist', function () {
    $foreign = Merchant::factory()->create();
    MerchantUser::factory()->for($foreign)->owner()->create();
    $foreignRole = app(RolePresetService::class)->ensure($foreign, RolePresetService::MANAGER);

    $target = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($this->owner, 'merchant');

    $stranger = $this->patchJson("/api/merchant/staff/{$target->id}", ['merchant_role_id' => $foreignRole->id]);
    $missing = $this->patchJson("/api/merchant/staff/{$target->id}", ['merchant_role_id' => 999999]);

    $stranger->assertUnprocessable()->assertJsonValidationErrors('merchant_role_id');
    $missing->assertUnprocessable()->assertJsonValidationErrors('merchant_role_id');

    // Byte for byte the same refusal: whether the id belongs to somebody
    // else is not this caller's to learn. Role ids are shared sequential
    // integers, so the difference is guessable and the answer must not be.
    expect($stranger->json())->toBe($missing->json())
        ->and($target->refresh()->merchant_role_id)->toBe($this->roles[RolePresetService::STAFF]->id);

    $this->postJson('/api/merchant/staff', [
        'name' => 'Poached',
        'email' => 'poached@example.com',
        'merchant_role_id' => $foreignRole->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('merchant_role_id');

    expect(MerchantUser::query()->where('email', 'poached@example.com')->exists())->toBeFalse();

    // The roles screen answers the same way: a foreign role is a missing one.
    $this->patchJson("/api/merchant/roles/{$foreignRole->id}", ['name' => 'Theirs Now'])->assertNotFound();
    $this->deleteJson("/api/merchant/roles/{$foreignRole->id}")->assertNotFound();
    $this->patchJson('/api/merchant/roles/999999', ['name' => 'Nobody'])->assertNotFound();

    expect($foreignRole->refresh()->name)->toBe('Manager');
});

it('refuses to let a delegate grant a permission they do not hold themselves', function () {
    $this->actingAs($this->supervisor, 'merchant')
        ->postJson('/api/merchant/roles', [
            'name' => 'Treasurer',
            'permissions' => ['transactions.view', 'bank_account.update'],
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_not_held')
        // Named, so the roles screen can point at the checkbox rather than
        // saying the whole submission was wrong.
        ->assertJsonPath('permissions', ['bank_account.update']);

    expect(MerchantRole::query()->where('merchant_id', $this->merchant->id)->where('name', 'Treasurer')->exists())
        ->toBeFalse();

    // What they DO hold passes without a special case.
    $this->postJson('/api/merchant/roles', ['name' => 'Reader', 'permissions' => ['transactions.view']])
        ->assertCreated()
        ->assertJsonPath('data.permissions', ['transactions.view'])
        ->assertJsonPath('data.is_owner', false)
        ->assertJsonPath('data.is_system', false);
});

it('lets a delegate NARROW a role wider than themselves — removing hands nobody anything', function () {
    $wide = MerchantRole::query()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Accounts',
        'slug' => 'accounts',
        'permissions' => ['transactions.view', 'bank_account.update'],
        'is_owner' => false,
        'is_system' => false,
    ]);

    $this->actingAs($this->supervisor, 'merchant')
        ->patchJson("/api/merchant/roles/{$wide->id}", ['permissions' => ['transactions.view']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['transactions.view']);

    // Putting it back is an addition again, and refused.
    $this->patchJson("/api/merchant/roles/{$wide->id}", ['permissions' => ['transactions.view', 'bank_account.update']])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_not_held');
});

it('never lets anyone mint a second owner role', function () {
    $this->actingAs($this->supervisor, 'merchant')
        ->postJson('/api/merchant/roles', ['name' => 'Shadow', 'permissions' => [], 'is_owner' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_owner');

    // And the flag is not merely un-submittable — it is not an input at all,
    // so the ordinary create path cannot arrive at one either.
    $id = $this->postJson('/api/merchant/roles', ['name' => 'Shadow', 'permissions' => []])
        ->assertCreated()
        ->json('data.id');

    expect(MerchantRole::query()->findOrFail($id)->is_owner)->toBeFalse()
        ->and(MerchantRole::query()->where('merchant_id', $this->merchant->id)->where('is_owner', true)->count())->toBe(1);
});

it('reserves handing out the owner role for owners', function () {
    $target = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($this->supervisor, 'merchant')
        ->patchJson("/api/merchant/staff/{$target->id}", [
            'merchant_role_id' => $this->roles[RolePresetService::OWNER]->id,
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'owner_role_not_delegable');

    $this->postJson('/api/merchant/staff', [
        'name' => 'Heir',
        'email' => 'heir@example.com',
        'merchant_role_id' => $this->roles[RolePresetService::OWNER]->id,
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'owner_role_not_delegable');

    expect($target->refresh()->isOwner())->toBeFalse()
        ->and(MerchantUser::query()->where('email', 'heir@example.com')->exists())->toBeFalse();

    // The same request from an owner goes through, so what was refused above
    // was the delegation and not the route.
    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/staff/{$target->id}", [
            'merchant_role_id' => $this->roles[RolePresetService::OWNER]->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.role.is_owner', true);
});

it('refuses to let a delegate edit the role they stand on', function () {
    $this->actingAs($this->supervisor, 'merchant')
        ->patchJson("/api/merchant/roles/{$this->supervisorRole->id}", ['name' => 'Chief'])
        ->assertForbidden()
        ->assertJsonPath('code', 'cannot_edit_own_role');

    $this->patchJson("/api/merchant/roles/{$this->supervisorRole->id}", [
        'permissions' => ['transactions.view', 'staff.view', 'staff.invite', 'staff.edit', 'roles.view', 'roles.manage', 'bank_account.update'],
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'cannot_edit_own_role');

    expect($this->supervisorRole->refresh()->name)->toBe('Supervisor')
        ->and($this->supervisor->refresh()->can('bank_account.update'))->toBeFalse();
});

it('keeps the owner role renameable, un-editable and un-deletable', function () {
    $ownerRole = $this->roles[RolePresetService::OWNER];

    $this->actingAs($this->owner, 'merchant');

    // A store that calls it something else keeps its own word for it (D7).
    $this->patchJson("/api/merchant/roles/{$ownerRole->id}", ['name' => 'Proprietor', 'name_dv' => 'ވެރިޔާ'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Proprietor')
        ->assertJsonPath('data.name_dv', 'ވެރިޔާ')
        // The slug never follows a rename: it is how the presets are found.
        ->assertJsonPath('data.slug', 'owner');

    $this->patchJson("/api/merchant/roles/{$ownerRole->id}", ['permissions' => ['transactions.view']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'owner_role_frozen');

    $this->deleteJson("/api/merchant/roles/{$ownerRole->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'owner_role_undeletable');

    // Stripping it would make the last-owner guard meaningless, so the
    // stored list is still the empty one the flag stands in for.
    expect($ownerRole->refresh()->permissions)->toBe([])
        ->and($this->owner->refresh()->can('bank_account.update'))->toBeTrue();
});

it('refuses to delete a role somebody is still standing on', function () {
    $this->actingAs($this->owner, 'merchant')
        ->deleteJson("/api/merchant/roles/{$this->supervisorRole->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'role_in_use')
        ->assertJsonPath('staff_count', 1);

    // Moved off it, the same delete succeeds.
    $this->patchJson("/api/merchant/staff/{$this->supervisor->id}", [
        'merchant_role_id' => $this->roles[RolePresetService::STAFF]->id,
    ])->assertOk();

    $this->deleteJson("/api/merchant/roles/{$this->supervisorRole->id}")->assertNoContent();

    expect(MerchantRole::query()->whereKey($this->supervisorRole->id)->exists())->toBeFalse();
});

it('caps a store at twenty roles', function () {
    $held = MerchantRole::query()->where('merchant_id', $this->merchant->id)->count();

    for ($i = $held; $i < RoleService::MAX_PER_MERCHANT; $i++) {
        MerchantRole::query()->create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Filler '.$i,
            'slug' => 'filler-'.$i,
            'permissions' => [],
            'is_owner' => false,
            'is_system' => false,
        ]);
    }

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/roles', ['name' => 'One Too Many', 'permissions' => []])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'role_cap_reached');

    expect(MerchantRole::query()->where('merchant_id', $this->merchant->id)->count())
        ->toBe(RoleService::MAX_PER_MERCHANT);
});
