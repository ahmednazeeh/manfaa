<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->staff = MerchantUser::factory()->for($this->merchant)->create(['role' => 'staff']);
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
});

/**
 * Every owner-only route: the new settings surface plus the pre-existing
 * owner actions the EnsureMerchantOwner middleware now gates (rate change,
 * promotion writes, settlement creation, settlement submission, wallet
 * settle).
 *
 * @return array<string, array{0: string, 1: string}>
 */
function ownerOnlyRoutes(): array
{
    return [
        'profile read' => ['getJson', '/api/merchant/profile'],
        'profile write' => ['patchJson', '/api/merchant/profile'],
        'bank account' => ['patchJson', '/api/merchant/bank-account'],
        'branches read' => ['getJson', '/api/merchant/branches'],
        'branch create' => ['postJson', '/api/merchant/branches'],
        'branch update' => ['patchJson', '/api/merchant/branches/1'],
        'branch delete' => ['deleteJson', '/api/merchant/branches/1'],
        'staff read' => ['getJson', '/api/merchant/staff'],
        'staff create' => ['postJson', '/api/merchant/staff'],
        'staff update' => ['patchJson', '/api/merchant/staff/1'],
        'preferences' => ['patchJson', '/api/merchant/preferences'],
        // Newly gated pre-existing owner surfaces:
        'rate change' => ['postJson', '/api/merchant/rate'],
        'promotion create' => ['postJson', '/api/merchant/promotions'],
        'promotion publish' => ['postJson', '/api/merchant/promotions/1/publish'],
        'promotion cancel' => ['postJson', '/api/merchant/promotions/1/cancel'],
        'settlement create' => ['postJson', '/api/merchant/settlements'],
        // Submit freezes lines and — on a fully credit-netted draft —
        // allocates and settles the whole batch: a settlement mutation
        // like the other two, so owner-only like the other two.
        'settlement submit' => ['postJson', '/api/merchant/settlements/1/submit'],
        'wallet settle' => ['postJson', '/api/merchant/settlements/1/wallet-settle'],
    ];
}

it('answers 403 owner_required to staff on every owner-only route', function () {
    $this->actingAs($this->staff, 'merchant');

    foreach (ownerOnlyRoutes() as $label => [$method, $uri]) {
        $this->{$method}($uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'owner_required');
    }
});

it('rejects unauthenticated requests on the settings surface outright', function () {
    $this->getJson('/api/merchant/profile')->assertUnauthorized();
    $this->patchJson('/api/merchant/preferences')->assertUnauthorized();
    $this->getJson('/api/merchant/customers/lookup?code=482917')->assertUnauthorized();
});

it('lets staff post manual credits — the operational surface stays theirs', function () {
    $this->actingAs($this->staff, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-STAFF-1',
            'eligible_amount' => 125000,
            'sale_amount' => 125000,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertCreated();
});

it('lets staff use the customer lookup for the credit screen', function () {
    $this->actingAs($this->staff, 'merchant')
        ->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertOk()
        ->assertJsonPath('valid', true);
});

it('keeps the read endpoints staff-accessible', function () {
    $this->actingAs($this->staff, 'merchant');

    $this->getJson('/api/merchant/rate')->assertOk();
    $this->getJson('/api/merchant/promotions')->assertOk();
    $this->getJson('/api/merchant/settlements')->assertOk();
    $this->getJson('/api/merchant/outstanding')->assertOk();
    $this->getJson('/api/merchant/transactions')->assertOk();
});

it('lets the owner through the same gates', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->getJson('/api/merchant/profile')->assertOk();
    $this->getJson('/api/merchant/branches')->assertOk();
    $this->getJson('/api/merchant/staff')->assertOk();
    $this->postJson('/api/merchant/rate', ['rate_bp' => 300])->assertOk();
});
