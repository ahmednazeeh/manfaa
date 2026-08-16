<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($this->staff, 'merchant');
});

it('resolves a known active customer to their name for staff', function () {
    Customer::factory()->create([
        'customer_code' => '482917',
        'name' => 'Aisha Mohamed',
        'status' => 'active',
    ]);

    $this->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertOk()
        ->assertExactJson([
            'valid' => true,
            'name' => 'Aisha Mohamed',
        ]);
});

it('answers a plain valid:false for an unknown code — no enumeration error shape', function () {
    $this->getJson('/api/merchant/customers/lookup?code=999999')
        ->assertOk()
        ->assertExactJson(['valid' => false]);
});

it('answers valid:false for a blocked customer without leaking the name', function () {
    Customer::factory()->create([
        'customer_code' => '111222',
        'name' => 'Blocked Person',
        'status' => 'suspended',
    ]);

    $this->getJson('/api/merchant/customers/lookup?code=111222')
        ->assertOk()
        ->assertExactJson(['valid' => false]);
});

it('rejects a malformed code', function () {
    $this->getJson('/api/merchant/customers/lookup?code=12345')->assertUnprocessable();
    $this->getJson('/api/merchant/customers/lookup?code=abcdef')->assertUnprocessable();
    $this->getJson('/api/merchant/customers/lookup')->assertUnprocessable();
});

it('throttles the lookup at 30 per minute per user', function () {
    Customer::factory()->create(['customer_code' => '482917']);

    foreach (range(1, 30) as $i) {
        $this->getJson('/api/merchant/customers/lookup?code=482917')->assertOk();
    }

    $this->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertStatus(429);

    // Keyed per USER, not globally: a colleague is unaffected.
    $colleague = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($colleague, 'merchant')
        ->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertOk();
});

it('caps non-creditable lookups per MERCHANT per day — staff accounts share one miss budget', function () {
    Customer::factory()->create(['customer_code' => '482917']);

    // Two staff each burn their per-minute request budget on unknown
    // codes: 60 misses land on the MERCHANT's shared daily miss allowance.
    foreach (range(1, 30) as $i) {
        $this->getJson('/api/merchant/customers/lookup?code=999999')
            ->assertOk()
            ->assertExactJson(['valid' => false]);
    }

    $colleague = MerchantUser::factory()->for($this->merchant)->staff()->create();
    $this->actingAs($colleague, 'merchant');

    foreach (range(1, 30) as $i) {
        $this->getJson('/api/merchant/customers/lookup?code=999999')
            ->assertOk()
            ->assertExactJson(['valid' => false]);
    }

    // A third account gains nothing: the cap is keyed per merchant, so
    // spinning up staff accounts cannot extend the enumeration budget —
    // once tripped, even a VALID code answers 429 until the day rolls.
    $third = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($third, 'merchant')
        ->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertStatus(429);

    // Another merchant's till is unaffected — its own budget is untouched.
    $otherMerchantStaff = MerchantUser::factory()->staff()->create();

    $this->actingAs($otherMerchantStaff, 'merchant')
        ->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertOk()
        ->assertJsonPath('valid', true);
});

/**
 * The lookup answers a real name because the cashier is confirming it
 * against the person at the counter. A three-letter fragment cannot do
 * that in the Maldives — "Ahm*** Naz***" fits an enormous number of people
 * — so masking here bought no privacy and cost the check its purpose.
 *
 * What actually protects the customer base is unchanged and asserted
 * below: a lookup needs the code the customer just showed, answers one
 * customer, and misses are budgeted per merchant.
 */
it('answers the full name, never a masked fragment', function () {
    Customer::factory()->create([
        'customer_code' => '771234',
        'name' => 'Ahmed Nazeeh',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/merchant/customers/lookup?code=771234')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('name', 'Ahmed Nazeeh');

    expect($response->getContent())->not->toContain('***');

    // One customer, and nothing else about them.
    expect(array_keys($response->json()))->toBe(['valid', 'name']);
});

it('still refuses to be an enumeration oracle', function () {
    Customer::factory()->create([
        'customer_code' => '771235',
        'name' => 'Blocked Person',
        'status' => 'suspended',
    ]);

    // A blocked customer and a code that does not exist answer identically:
    // knowing a name requires a code that can actually be credited.
    $blocked = $this->getJson('/api/merchant/customers/lookup?code=771235')->assertOk();
    $missing = $this->getJson('/api/merchant/customers/lookup?code=771236')->assertOk();

    expect($blocked->getContent())->toBe($missing->getContent())
        ->and($blocked->json())->toBe(['valid' => false]);
});
