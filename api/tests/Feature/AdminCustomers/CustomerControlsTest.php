<?php

use App\Models\AdminUser;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * In-test requests share one app instance, so resolved guards memoise the
 * user they loaded on an earlier request. Production requests construct
 * fresh guards every time; this models that between two requests.
 */
function freshCustomerControlGuards(): void
{
    app('auth')->forgetGuards();
}

it('rejects unauthenticated and non-superadmin customer writes', function () {
    $customer = Customer::factory()->create(['name' => 'Before']);

    $this->patchJson("/api/admin/customers/{$customer->id}", ['name' => 'After'])
        ->assertUnauthorized();
    $this->postJson("/api/admin/customers/{$customer->id}/reset-password")
        ->assertUnauthorized();
    $this->postJson("/api/admin/customers/{$customer->id}/status", ['status' => 'suspended'])
        ->assertUnauthorized();

    $this->actingAs(AdminUser::factory()->create(), 'admin');

    $this->patchJson("/api/admin/customers/{$customer->id}", ['name' => 'After'])
        ->assertForbidden();
    $this->postJson("/api/admin/customers/{$customer->id}/reset-password")
        ->assertForbidden();
    $this->postJson("/api/admin/customers/{$customer->id}/status", ['status' => 'suspended'])
        ->assertForbidden();

    $customer->refresh();
    expect($customer->name)->toBe('Before')
        ->and($customer->status)->toBe('active');
});

it('edits name and email, and clearing the email stores null', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->patchJson("/api/admin/customers/{$customer->id}", [
            'name' => 'Renamed Customer',
            'email' => 'renamed@example.mv',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Customer')
        ->assertJsonPath('data.email', 'renamed@example.mv');

    $this->patchJson("/api/admin/customers/{$customer->id}", ['email' => null])
        ->assertOk()
        ->assertJsonPath('data.email', null);

    expect($customer->refresh()->name)->toBe('Renamed Customer')
        ->and($customer->email)->toBeNull();
});

it('validates a phone change: platform shape and uniqueness against other customers', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $other = Customer::factory()->create(['phone' => '+9609998877']);

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    // Not a Maldivian mobile in any accepted form.
    $this->patchJson("/api/admin/customers/{$customer->id}", ['phone' => '12345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    // Another customer's number — one number is one account.
    $this->patchJson("/api/admin/customers/{$customer->id}", ['phone' => '999 8877'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    // The customer's own number restated is not a collision.
    $this->patchJson("/api/admin/customers/{$customer->id}", ['phone' => '7712345'])
        ->assertOk();

    expect($customer->refresh()->phone)->toBe('+9607712345')
        ->and($other->refresh()->phone)->toBe('+9609998877');
});

it('changes the phone: normalises to +960, clears the OTP attestation, keeps the customer signed in', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $customer->createToken('customer: iPhone', ['mobile:customer'], now()->addDays(30));

    // A live web session on the OLD number.
    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607712345',
        'password' => 'password',
    ])->assertOk();
    $this->getJson('/api/customer/auth/me')->assertOk();
    freshCustomerControlGuards();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->patchJson("/api/admin/customers/{$customer->id}", ['phone' => '760-1122'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+9607601122')
        ->assertJsonPath('data.phone_verified_at', null);
    freshCustomerControlGuards();

    $customer->refresh();
    expect($customer->phone)->toBe('+9607601122')
        // The attestation belonged to the OLD number; the next OTP sign-in
        // re-earns it for the new one.
        ->and($customer->phone_verified_at)->toBeNull()
        // The support scenario is a LOST SIM: the customer's own app install
        // and web session survive the change — nothing is revoked.
        ->and($customer->tokens()->count())->toBe(1);

    $this->getJson('/api/customer/auth/me')->assertOk();
});

it('resets the web password: the temp works and is returned once, the web session dies, the app stays signed in', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $customer->createToken('customer: iPhone', ['mobile:customer'], now()->addDays(30));

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607712345',
        'password' => 'password',
    ])->assertOk();
    $this->getJson('/api/customer/auth/me')->assertOk();
    freshCustomerControlGuards();

    $response = $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/customers/{$customer->id}/reset-password")
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id);
    freshCustomerControlGuards();

    $temp = $response->json('temp_password');
    expect($temp)->toBeString()->and(strlen($temp))->toBeGreaterThanOrEqual(20);

    $customer->refresh();

    // The returned password is the one that now works; the old one is dead.
    expect(Hash::check($temp, $customer->password))->toBeTrue()
        ->and(Hash::check('password', $customer->password))->toBeFalse()
        // The app is passwordless (OTP sign-in) — the password never guarded
        // it, so the reset deliberately leaves the app signed in.
        ->and($customer->tokens()->count())->toBe(1);

    // The session's stored password hash no longer matches — the very next
    // request is logged out (AuthenticateMultiGuardSession).
    $this->getJson('/api/customer/auth/me')->assertUnauthorized();
});

it('disables a customer: web session and login die, app tokens are destroyed; enabling restores sign-in', function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $plainToken = $customer->createToken(
        'customer: iPhone', ['mobile:customer'], now()->addDays(30),
    )->plainTextToken;

    // Signed in everywhere: app bearer token first (the mobile tree is
    // bearer-only and refuses a request that ALSO carries a live session —
    // EnsureMobileToken's TransientToken stance), then the web session.
    $this->getJson('/api/mobile/v1/customer/me', ['Authorization' => 'Bearer '.$plainToken])
        ->assertOk();
    freshCustomerControlGuards();

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607712345',
        'password' => 'password',
    ])->assertOk();
    $this->getJson('/api/customer/auth/me')->assertOk();
    freshCustomerControlGuards();

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/customers/{$customer->id}/status", [
            'status' => 'suspended',
            'reason' => 'Chargeback fraud under investigation.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');
    freshCustomerControlGuards();

    $customer->refresh();
    // Destroyed, not merely refused — reactivating later must not resurrect
    // a token on a phone nobody holds anymore. Push registrations cascade
    // with the tokens (device_tokens FK).
    expect($customer->status)->toBe('suspended')
        ->and($customer->tokens()->count())->toBe(0);

    // The app credential is gone.
    $this->getJson('/api/mobile/v1/customer/me', ['Authorization' => 'Bearer '.$plainToken])
        ->assertUnauthorized();

    // The live web session dies on its next request (CustomersServiceProvider).
    $this->getJson('/api/customer/auth/me')->assertUnauthorized();
    freshCustomerControlGuards();

    // And a fresh password login fails exactly like a wrong password.
    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607712345',
        'password' => 'password',
    ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    freshCustomerControlGuards();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/customers/{$customer->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
    freshCustomerControlGuards();

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607712345',
        'password' => 'password',
    ])->assertOk();
});

it('refuses unknown statuses and never reopens a closed account', function () {
    $customer = Customer::factory()->create();
    $closed = Customer::factory()->create(['status' => 'closed']);

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    // `closed` is ledger bookkeeping, not a switch this endpoint offers.
    $this->postJson("/api/admin/customers/{$customer->id}/status", ['status' => 'closed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->postJson("/api/admin/customers/{$closed->id}/status", ['status' => 'active'])
        ->assertStatus(409);

    expect($customer->refresh()->status)->toBe('active')
        ->and($closed->refresh()->status)->toBe('closed');
});
