<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');
});

it('logs in a customer with phone and password and me returns identity', function () {
    $customer = Customer::factory()->create(['phone' => '+9607771234']);

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607771234',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer_code', $customer->customer_code);

    $this->assertAuthenticatedAs($customer, 'customer');

    $response = $this->getJson('/api/customer/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id);

    expect($response->json('data.customer_code'))
        ->toBe($customer->customer_code)
        ->toMatch('/^\d{6}$/');

    // The phone is masked — prefix and last three digits only.
    expect($response->json('data.phone'))->toBe('+960****234');
});

it('rejects a wrong customer password', function () {
    Customer::factory()->create(['phone' => '+9607771234']);

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607771234',
        'password' => 'not-the-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    $this->assertGuest('customer');
});

it('logs out a customer and invalidates the session', function () {
    Customer::factory()->create(['phone' => '+9607771234']);

    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607771234',
        'password' => 'password',
    ])->assertOk();

    $this->postJson('/api/customer/auth/logout')->assertNoContent();

    $this->assertGuest('customer');
    $this->getJson('/api/customer/auth/me')->assertUnauthorized();
});

it('requires authentication for customer me', function () {
    $this->getJson('/api/customer/auth/me')->assertUnauthorized();
});
