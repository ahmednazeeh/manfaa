<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * §9.1 regression: Sanctum resolves the first-party session guards BEFORE
 * the bearer token and hands session users a TransientToken that passes
 * every tokenCan() check. Without EnsureVendorCredential, any logged-in
 * customer, merchant staffer, or admin could call the vendor API — most
 * sensitively GET /v1/customers/lookup — with no vendor credential and no
 * ability grant. /v1 must be reachable with a real per-merchant personal
 * access token ONLY.
 */

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
});

it('refuses a customer web session on the vendor lookup endpoint', function () {
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/v1/customers/lookup?ref=482917')
        ->assertUnauthorized()
        ->assertJsonMissingPath('masked_name');
});

it('refuses a merchant panel session on every vendor endpoint', function () {
    $user = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($user, 'merchant')
        ->getJson('/api/v1/customers/lookup?ref=482917')
        ->assertUnauthorized();

    $this->actingAs($user, 'merchant')
        ->getJson('/api/v1/merchants/me/rate')
        ->assertUnauthorized();

    $this->actingAs($user, 'merchant')
        ->withHeaders(['Idempotency-Key' => 'session-must-not-write'])
        ->postJson('/api/v1/transactions', [
            'invoice_no' => 'INV-9001',
            'customer_ref' => '482917',
            'eligible_amount' => 100000,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertUnauthorized();

    expect(Transaction::query()->count())->toBe(0);
});

it('refuses an admin session on the vendor rate endpoint', function () {
    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson('/api/v1/merchants/me/rate')
        ->assertUnauthorized();
});

it('still admits a genuine vendor bearer token', function () {
    $token = $this->merchant->createToken('till', ['customers:lookup'])->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/customers/lookup?ref=482917')
        ->assertOk()
        ->assertJsonPath('valid', true);
});
