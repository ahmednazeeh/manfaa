<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

it('creates a staff account returning the temporary password exactly once', function () {
    $this->actingAs($this->owner, 'merchant');

    $response = $this->postJson('/api/merchant/staff', [
        'name' => 'New Staff',
        'email' => 'new.staff@example.com',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'New Staff')
        ->assertJsonPath('data.role', 'staff')
        ->assertJsonPath('data.is_active', true);

    $tempPassword = $response->json('temp_password');
    $created = MerchantUser::query()->where('email', 'new.staff@example.com')->sole();

    expect($tempPassword)->toBeString()->not->toBeEmpty()
        ->and(Hash::check($tempPassword, $created->password))->toBeTrue()
        ->and($created->merchant_id)->toBe($this->merchant->id)
        ->and($created->role)->toBe('staff');

    // Never surfaced again: the listing carries no password material.
    $listing = $this->getJson('/api/merchant/staff')->assertOk()->assertJsonCount(2, 'data');
    expect($listing->getContent())->not->toContain($tempPassword);
});

it('rejects a duplicate staff email', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/staff', [
        'name' => 'Dup',
        'email' => $this->owner->email,
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

    $this->patchJson("/api/merchant/staff/{$staff->id}", ['role' => 'owner'])
        ->assertOk()
        ->assertJsonPath('data.role', 'owner');

    $this->patchJson("/api/merchant/staff/{$staff->id}", ['role' => 'staff'])
        ->assertOk()
        ->assertJsonPath('data.role', 'staff');
});

it('refuses self-deactivation and self-demotion', function () {
    // A second active owner exists, so the last-owner guard passes and the
    // SELF guard is what refuses.
    MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['is_active' => false])
        ->assertUnprocessable();
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['role' => 'staff'])
        ->assertUnprocessable();

    expect($this->owner->refresh()->role)->toBe('owner')
        ->and($this->owner->is_active)->toBeTrue();
});

it('never removes the last active owner', function () {
    // The other owner is inactive, so $this->owner is the last ACTIVE one.
    MerchantUser::factory()->for($this->merchant)->owner()->create(['is_active' => false]);

    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['is_active' => false])
        ->assertUnprocessable();
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['role' => 'staff'])
        ->assertUnprocessable();

    expect($this->owner->refresh()->role)->toBe('owner')
        ->and($this->owner->is_active)->toBeTrue();
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
