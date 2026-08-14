<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');
});

/**
 * In-test requests share one app instance, so resolved guards memoise the
 * user they loaded on an earlier request. Production requests construct
 * fresh guards every time; this models that between two requests.
 */
function freshRequestGuards(): void
{
    app('auth')->forgetGuards();
}

/**
 * A password change (another device, an admin reset, a stolen-session
 * rotation) must kill every live session of that guard. Sanctum's stock
 * AuthenticateSession gates on the DEFAULT guard's user, so without the
 * multi-guard override only admin sessions would ever be checked.
 */
it('logs an admin session out when the admin password changes elsewhere', function () {
    $user = AdminUser::factory()->create(['email' => 'admin@demo.manfaa.app']);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@demo.manfaa.app',
        'password' => 'password',
    ])->assertOk();

    $this->getJson('/api/admin/auth/me')->assertOk();

    $user->forceFill(['password' => Hash::make('rotated-password')])->save();
    freshRequestGuards();

    $this->getJson('/api/admin/auth/me')->assertUnauthorized();
});

it('logs a merchant session out when the merchant password changes elsewhere', function () {
    $user = MerchantUser::factory()->create(['email' => 'merchant@demo.manfaa.app']);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'merchant@demo.manfaa.app',
        'password' => 'password',
    ])->assertOk();

    $this->getJson('/api/merchant/auth/me')->assertOk();

    $user->forceFill(['password' => Hash::make('rotated-password')])->save();
    freshRequestGuards();

    $this->getJson('/api/merchant/auth/me')->assertUnauthorized();
});

it('logs a customer session out when the customer password changes elsewhere', function () {
    $customer = Customer::factory()->create(['phone' => '+9607123456']);

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607123456',
        'password' => 'password',
    ])->assertOk();

    $this->getJson('/api/customer/auth/me')->assertOk();

    $customer->forceFill(['password' => Hash::make('rotated-password')])->save();
    freshRequestGuards();

    $this->getJson('/api/customer/auth/me')->assertUnauthorized();
});

it('keeps a session alive while the password is unchanged', function () {
    MerchantUser::factory()->create(['email' => 'merchant@demo.manfaa.app']);

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'merchant@demo.manfaa.app',
        'password' => 'password',
    ])->assertOk();

    $this->getJson('/api/merchant/auth/me')->assertOk();
    freshRequestGuards();
    $this->getJson('/api/merchant/auth/me')->assertOk();
});
