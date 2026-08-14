<?php

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');
});

it('logs in an admin with email and password and me returns identity', function () {
    $admin = AdminUser::factory()->create([
        'email' => 'admin@manfaa.app',
        'role' => 'superadmin',
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@manfaa.app',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@manfaa.app');

    $this->assertAuthenticatedAs($admin, 'admin');

    $this->getJson('/api/admin/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.email', 'admin@manfaa.app')
        ->assertJsonPath('data.role', 'superadmin');
});

it('rejects a wrong admin password', function () {
    AdminUser::factory()->create(['email' => 'admin@manfaa.app']);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@manfaa.app',
        'password' => 'not-the-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('admin');
});

it('logs out an admin and invalidates the session', function () {
    AdminUser::factory()->create(['email' => 'admin@manfaa.app']);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@manfaa.app',
        'password' => 'password',
    ])->assertOk();

    $this->postJson('/api/admin/auth/logout')->assertNoContent();

    $this->assertGuest('admin');
    $this->getJson('/api/admin/auth/me')->assertUnauthorized();
});

it('requires authentication for admin me', function () {
    $this->getJson('/api/admin/auth/me')->assertUnauthorized();
});
