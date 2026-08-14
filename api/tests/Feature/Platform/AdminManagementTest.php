<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('gates the whole surface behind superadmin', function () {
    $this->actingAs(AdminUser::factory()->create(['role' => 'admin']), 'admin');

    $this->getJson('/api/admin/admins')->assertForbidden();
    $this->postJson('/api/admin/admins', ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
    $this->patchJson('/api/admin/admins/1', ['is_active' => false])->assertForbidden();
});

it('creates an admin returning the temporary password exactly once', function () {
    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    $response = $this->postJson('/api/admin/admins', [
        'name' => 'New Admin',
        'email' => 'new.admin@example.com',
        'role' => 'admin',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'New Admin')
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.is_active', true);

    $tempPassword = $response->json('temp_password');
    $created = AdminUser::query()->where('email', 'new.admin@example.com')->sole();

    expect($tempPassword)->toBeString()->not->toBeEmpty()
        ->and(Hash::check($tempPassword, $created->password))->toBeTrue();

    // Never surfaced again: the listing carries no password material.
    $listing = $this->getJson('/api/admin/admins')->assertOk();
    expect($listing->getContent())->not->toContain($tempPassword);

    // Duplicate email is refused.
    $this->postJson('/api/admin/admins', [
        'name' => 'Dup', 'email' => 'new.admin@example.com',
    ])->assertStatus(422);
});

it('refuses self-demotion even when other superadmins exist', function () {
    $me = AdminUser::factory()->create(['role' => 'superadmin']);
    AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($me, 'admin');

    $this->patchJson("/api/admin/admins/{$me->id}", ['role' => 'admin'])
        ->assertStatus(422);

    expect($me->refresh()->role)->toBe('superadmin');
});

it('refuses to deactivate or demote the last active superadmin', function () {
    $me = AdminUser::factory()->create(['role' => 'superadmin']);
    // An inactive superadmin and an active plain admin do not count.
    AdminUser::factory()->create(['role' => 'superadmin', 'is_active' => false]);
    AdminUser::factory()->create(['role' => 'admin']);
    $this->actingAs($me, 'admin');

    $this->patchJson("/api/admin/admins/{$me->id}", ['is_active' => false])->assertStatus(422);
    $this->patchJson("/api/admin/admins/{$me->id}", ['role' => 'admin'])->assertStatus(422);

    expect($me->refresh()->role)->toBe('superadmin')->and($me->is_active)->toBeTrue();
});

it('deactivates and promotes other admins, and self-deactivation is refused', function () {
    $me = AdminUser::factory()->create(['role' => 'superadmin']);
    $other = AdminUser::factory()->create(['role' => 'admin']);
    AdminUser::factory()->create(['role' => 'superadmin']); // a second superadmin exists
    $this->actingAs($me, 'admin');

    // Self-deactivation refused even though another superadmin remains.
    $this->patchJson("/api/admin/admins/{$me->id}", ['is_active' => false])->assertStatus(422);

    $this->patchJson("/api/admin/admins/{$other->id}", ['role' => 'superadmin'])
        ->assertOk()
        ->assertJsonPath('data.role', 'superadmin');

    $this->patchJson("/api/admin/admins/{$other->id}", ['is_active' => false, 'role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.role', 'admin');

    // There is no DELETE — deactivation is the only removal.
    $this->deleteJson("/api/admin/admins/{$other->id}")->assertStatus(405);
});

it('refuses login for a deactivated admin', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    AdminUser::factory()->create([
        'email' => 'inactive@example.com',
        'password' => 'secret-password-1',
        'is_active' => false,
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'secret-password-1',
    ])->assertStatus(422);

    $this->getJson('/api/admin/auth/me')->assertUnauthorized();
});

it('kills a live session on the next request after deactivation', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $admin = AdminUser::factory()->create([
        'email' => 'live@example.com',
        'password' => 'secret-password-2',
        'role' => 'superadmin',
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'live@example.com',
        'password' => 'secret-password-2',
    ])->assertOk();

    $this->getJson('/api/admin/auth/me')->assertOk()->assertJsonPath('data.email', 'live@example.com');

    // Deactivated behind the session's back.
    AdminUser::query()->whereKey($admin->id)->update(['is_active' => false]);

    // Force the guard to re-resolve from the session on the next request —
    // in production every request re-resolves; the test app caches guards.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/admin/auth/me')->assertUnauthorized();
});
