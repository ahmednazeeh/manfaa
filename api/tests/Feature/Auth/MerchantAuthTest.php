<?php

use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');
});

it('logs in a merchant user with email and password and me returns identity', function () {
    $merchant = Merchant::factory()->create(['name' => 'Demo Store']);
    $user = MerchantUser::factory()->for($merchant)->owner()->create([
        'email' => 'merchant@demo.manfaa.app',
    ]);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'merchant@demo.manfaa.app',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'merchant@demo.manfaa.app')
        ->assertJsonPath('data.merchant.name', 'Demo Store');

    $this->assertAuthenticatedAs($user, 'merchant');

    $this->getJson('/api/merchant/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role.is_owner', true)
        ->assertJsonPath('data.merchant.id', $merchant->id)
        ->assertJsonPath('data.merchant.name', 'Demo Store');
});

it('rejects a wrong merchant password', function () {
    MerchantUser::factory()->create(['email' => 'merchant@demo.manfaa.app']);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'merchant@demo.manfaa.app',
        'password' => 'not-the-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('merchant');
});

it('logs out a merchant user and invalidates the session', function () {
    MerchantUser::factory()->create(['email' => 'merchant@demo.manfaa.app']);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'merchant@demo.manfaa.app',
        'password' => 'password',
    ])->assertOk();

    $this->postJson('/api/merchant/auth/logout')->assertNoContent();

    $this->assertGuest('merchant');
    $this->getJson('/api/merchant/auth/me')->assertUnauthorized();
});

it('requires authentication for merchant me', function () {
    $this->getJson('/api/merchant/auth/me')->assertUnauthorized();
});

it('keeps a fresh merchant login alive when another guard\'s session hash pair is stale', function () {
    // The .manfaa.app session cookie is shared by the admin, merchant and
    // customer surfaces. A stale pair on ONE guard (here: a garbage admin
    // hash, the 2026-08-17 production incident) must log out that guard
    // only — flushing the whole session bounced a just-logged-in merchant
    // straight back to /login.
    $merchant = \App\Models\Merchant::factory()->create();
    $owner = \App\Models\MerchantUser::factory()->for($merchant)->owner()->create([
        'password' => bcrypt('secret-123'),
    ]);
    $admin = \App\Models\AdminUser::factory()->create();

    $this->postJson('/api/merchant/auth/login', [
        'email' => $owner->email,
        'password' => 'secret-123',
    ])->assertOk();

    session()->put([
        'login_admin_'.sha1(\Illuminate\Auth\SessionGuard::class) => $admin->id,
        'password_hash_admin' => 'stale-or-wrong-format-value',
    ]);

    // The merchant surface stays signed in…
    $this->getJson('/api/merchant/auth/me')->assertOk();

    // …while the offending admin login (and its pair) is gone.
    expect(session()->has('login_admin_'.sha1(\Illuminate\Auth\SessionGuard::class)))->toBeFalse()
        ->and(session()->has('password_hash_admin'))->toBeFalse()
        ->and(session()->has('login_merchant_'.sha1(\Illuminate\Auth\SessionGuard::class)))->toBeTrue();
});
