<?php

declare(strict_types=1);

use App\Domain\Marketplace\DeliveryQuote;
use App\Domain\Platform\PlatformConfig;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantUser;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MP3 — what a branch will deliver, and where (PLAN-marketplace.md §2.4).
 *
 * The case that shaped the design: a Malé shop wants a low free-delivery
 * minimum to Malé and a high one to Hulhumalé, and a Hulhumalé shop wants
 * the mirror image. A merchant-level setting cannot say that; a branch-level
 * one says it exactly.
 */
beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    // Real squares, so a pin dropped inside one genuinely resolves — the
    // whole point of taking the island from the pin rather than the form.
    $this->male = Zone::create(['name' => "Male'", 'polygon' => [
        ['lat' => 4.16, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.52],
        ['lat' => 4.16, 'lng' => 73.52],
    ]]);
    $this->hulhumale = Zone::create(['name' => "Hulhumale'", 'polygon' => [
        ['lat' => 4.20, 'lng' => 73.53],
        ['lat' => 4.24, 'lng' => 73.53],
        ['lat' => 4.24, 'lng' => 73.56],
        ['lat' => 4.20, 'lng' => 73.56],
    ]]);

    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->shop = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Island Mart Malé']);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

it('prices the owner\'s worked example in both directions', function () {
    // Island Mart, Malé: cheap at home, dear across the water.
    $home = BranchDeliveryRule::factory()->create([
        'branch_id' => $this->shop->id,
        'zone_id' => $this->male->id,
        'free_delivery_over_laari' => 2500,
        'delivery_fee_laari' => 2500,
    ]);
    $across = BranchDeliveryRule::factory()->create([
        'branch_id' => $this->shop->id,
        'zone_id' => $this->hulhumale->id,
        'free_delivery_over_laari' => 50000,
        'delivery_fee_laari' => 6000,
    ]);

    // A MVR 300 basket: free to Malé, charged to Hulhumalé.
    $toMale = DeliveryQuote::for($home, 30000);
    $toHulhumale = DeliveryQuote::for($across, 30000);

    expect($toMale->feeWaived)->toBeTrue()
        ->and($toMale->feeLaari)->toBe(0)
        ->and($toHulhumale->feeWaived)->toBeFalse()
        ->and($toHulhumale->feeLaari)->toBe(6000)
        // And the app can say how much more would earn free delivery.
        ->and($toHulhumale->toFreeDeliveryLaari())->toBe(20000);
});

it('waives the fee exactly AT the threshold, not a laari past it', function () {
    $rule = BranchDeliveryRule::factory()->create([
        'branch_id' => $this->shop->id,
        'zone_id' => $this->male->id,
        'free_delivery_over_laari' => 50000,
        'delivery_fee_laari' => 2500,
    ]);

    // "Free delivery over 500" that charges you at exactly 500 reads as
    // broken, whatever the wording says.
    expect(DeliveryQuote::for($rule, 49999)->feeWaived)->toBeFalse()
        ->and(DeliveryQuote::for($rule, 50000)->feeWaived)->toBeTrue()
        ->and(DeliveryQuote::for($rule, 50001)->feeWaived)->toBeTrue();
});

it('treats a missing rule as "we do not go there"', function () {
    // The absence of a row IS the answer.
    $quote = DeliveryQuote::for(null, 100000);

    expect($quote->delivers)->toBeFalse()
        ->and($quote->feeLaari)->toBe(0);
});

it('never refuses an order when no floor was set', function () {
    // order_minimum is null by default (§11.2) — a branch only turns small
    // orders away if it deliberately decides to.
    $rule = BranchDeliveryRule::factory()->create([
        'branch_id' => $this->shop->id,
        'zone_id' => $this->male->id,
        'order_minimum_laari' => null,
        'delivery_fee_laari' => 2500,
    ]);

    $quote = DeliveryQuote::for($rule, 1);

    expect($quote->minimumMet)->toBeTrue()
        ->and($quote->shortfallLaari)->toBe(0);
});

it('reports how far short a basket is of a floor that was set', function () {
    $rule = BranchDeliveryRule::factory()->create([
        'branch_id' => $this->shop->id,
        'zone_id' => $this->male->id,
        'order_minimum_laari' => 20000,
        'delivery_fee_laari' => 2500,
    ]);

    $quote = DeliveryQuote::for($rule, 12000);

    // "Add MVR 80.00 more to reach the minimum order" — the exact number the
    // cart's warning renders.
    expect($quote->minimumMet)->toBeFalse()
        ->and($quote->shortfallLaari)->toBe(8000);
});

it('lets a branch add an island, change it, and stop serving it', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->putJson("/api/merchant/marketplace/branches/{$this->shop->id}/delivery", [
        'zone_id' => $this->hulhumale->id,
        'delivery_fee_laari' => 6000,
        'free_delivery_over_laari' => 50000,
        'eta_min' => 60,
        'eta_max' => 120,
    ])->assertOk();

    $this->getJson("/api/merchant/marketplace/branches/{$this->shop->id}/delivery")
        ->assertOk()
        // Every platform island is listed; `delivers` says which are served.
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.delivers', true)
        ->assertJsonPath('data.0.delivers', false);

    $this->putJson("/api/merchant/marketplace/branches/{$this->shop->id}/delivery", [
        'zone_id' => $this->hulhumale->id,
        'delivery_fee_laari' => 7500,
        'free_delivery_over_laari' => 40000,
    ])->assertOk();

    expect(BranchDeliveryRule::query()->sole()->delivery_fee_laari)->toBe(7500);

    $this->deleteJson("/api/merchant/marketplace/branches/{$this->shop->id}/delivery/{$this->hulhumale->id}")
        ->assertNoContent();

    expect(BranchDeliveryRule::query()->count())->toBe(0);
});

it('will not let a merchant set terms for another merchant\'s branch', function () {
    $stranger = MerchantBranch::factory()->for(Merchant::factory()->create())->create();

    $this->actingAs($this->owner, 'merchant')
        ->putJson("/api/merchant/marketplace/branches/{$stranger->id}/delivery", [
            'zone_id' => $this->male->id, 'delivery_fee_laari' => 0,
        ])->assertNotFound();
});

// -------------------------------------------------------------- addresses

it('resolves the island from the pin, not from what was typed', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson('/api/customer/addresses', [
            'label' => 'Home',
            'recipient_name' => 'Aishath Niza',
            'phone' => '+9607771234',
            'building' => 'M. Maavehi',
            // Deliberately wrong: the courier drives to the PIN.
            'island' => 'Somewhere Else',
            'lat' => 4.1755,
            'lng' => 73.5093,
        ])->assertCreated();

    $address = CustomerAddress::query()->sole();

    // The typed island is kept verbatim — it is the customer's own words for
    // their address — but it decides NOTHING. The zone came from the pin.
    expect($address->island)->toBe('Somewhere Else')
        ->and($address->zone_id)->toBe($this->male->id);
});

it('leaves the zone null for a pin outside every island we have drawn', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson('/api/customer/addresses', [
            'label' => 'Home', 'recipient_name' => 'A', 'phone' => '+9607771234',
            'building' => 'M. Faraway',
            // Mid-ocean. Null is honest: no branch can quote delivery there
            // yet, which the checkout says in words rather than guessing.
            'lat' => 0.5, 'lng' => 73.0,
        ])->assertCreated()
        ->assertJsonPath('data.zone_id', null);
});

it('makes the first address the default, and keeps exactly one', function () {
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');

    $post = fn (string $label) => $this->postJson('/api/customer/addresses', [
        'label' => $label,
        'recipient_name' => 'Aishath Niza',
        'phone' => '+9607771234',
        'building' => 'M. '.$label,
    ])->assertCreated();

    $post('Home');
    expect($customer->addresses()->where('is_default', true)->count())->toBe(1);

    $post('Work');
    // Still exactly one — a checkout that opens with two defaults, or none,
    // is a checkout with nothing selected.
    expect($customer->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($customer->addresses()->where('is_default', true)->value('label'))->toBe('Home');

    $work = $customer->addresses()->where('label', 'Work')->sole();
    $this->patchJson("/api/customer/addresses/{$work->id}", [
        'label' => 'Work', 'recipient_name' => 'A', 'phone' => '+9607771234',
        'building' => 'H. Lotus', 'is_default' => true,
    ])->assertOk();

    expect($customer->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($customer->addresses()->where('is_default', true)->value('label'))->toBe('Work');
});

it('promotes another address when the default is deleted', function () {
    $customer = Customer::factory()->create();
    $default = CustomerAddress::factory()->for($customer)->create(['is_default' => true]);
    CustomerAddress::factory()->for($customer)->create(['is_default' => false]);

    $this->actingAs($customer, 'customer')
        ->deleteJson("/api/customer/addresses/{$default->id}")
        ->assertNoContent();

    // Never leave a customer holding addresses and no default.
    expect($customer->addresses()->where('is_default', true)->count())->toBe(1);
});

it('refuses half a coordinate', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson('/api/customer/addresses', [
            'label' => 'Home', 'recipient_name' => 'A', 'phone' => '+9607771234',
            'building' => 'M. Maavehi',
            'lat' => 4.1755,
        ])->assertUnprocessable();
});

it('keeps one customer out of another\'s address book', function () {
    $mine = CustomerAddress::factory()->create();
    $intruder = Customer::factory()->create();

    $this->actingAs($intruder, 'customer')
        ->patchJson("/api/customer/addresses/{$mine->id}", [
            'label' => 'Stolen', 'recipient_name' => 'A', 'phone' => '+9607771234',
            'building' => 'X',
        ])->assertNotFound();
});

it('hides the address book when the marketplace is off', function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 0);
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->getJson('/api/customer/addresses')
        ->assertNotFound();
});
