<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\BranchDeliveryRule;
use App\Models\BranchProduct;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantMarketplaceProfile;
use App\Models\MerchantRate;
use App\Models\Product;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MP4 — browse and the multi-vendor cart
 * (`Market View.png`, `Cart Page Collapsible By Merchant.png`).
 *
 * The cart prices itself server-side, because every number it shows has to
 * be the number checkout charges.
 */
function marketZone(): Zone
{
    return Zone::create(['name' => "Male'", 'polygon' => [
        ['lat' => 4.16, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.52],
        ['lat' => 4.16, 'lng' => 73.52],
    ]]);
}

/** A vendor with one shop, one product on the shelf, and a rate. */
function vendor(string $name, int $rateBp = 200, int $priceLaari = 10000): array
{
    $merchant = Merchant::factory()->create(['status' => 'active', 'name' => $name]);
    MerchantMarketplaceProfile::factory()->for($merchant)->create();
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => $rateBp,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    $branch = MerchantBranch::factory()->for($merchant)->create(['name' => 'Malé']);
    $product = Product::factory()->for($merchant)->create(['name' => $name.' Rice']);
    $listing = BranchProduct::factory()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'price_laari' => $priceLaari,
        'stock_qty' => 50,
        'state' => 'active',
    ]);

    return compact('merchant', 'branch', 'product', 'listing');
}

beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $this->zone = marketZone();
    $this->customer = Customer::factory()->create();
    $this->address = CustomerAddress::factory()->for($this->customer)->create([
        'zone_id' => $this->zone->id,
        'is_default' => true,
    ]);
    $this->actingAs($this->customer, 'customer');
});

it('groups a basket into one subcart per shop', function () {
    $a = vendor('Island Mart');
    $b = vendor('Horizon Bookstore');

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $a['listing']->id, 'qty' => 2])->assertOk();
    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $b['listing']->id, 'qty' => 1])->assertOk();

    $cart = $this->getJson('/api/customer/cart')->assertOk()->json('data');

    // Two shops, two cards — exactly the collapsible sections in the mockup.
    expect($cart['store_count'])->toBe(2)
        ->and($cart['items_laari'])->toBe(30000);
});

it('projects cashback per line, on items and never on delivery', function () {
    $v = vendor('Island Mart', rateBp: 200, priceLaari: 10000);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id,
        'zone_id' => $this->zone->id,
        'delivery_fee_laari' => 2500,
        'free_delivery_over_laari' => null,
    ]);

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 3])->assertOk();

    $cart = $this->getJson('/api/customer/cart')->assertOk()->json('data');

    // 30000 laari of items at 2% = 600. The 2500 delivery earns nothing.
    expect($cart['items_laari'])->toBe(30000)
        ->and($cart['delivery_laari'])->toBe(2500)
        ->and($cart['cashback_laari'])->toBe(600)
        ->and($cart['total_payable_laari'])->toBe(32500);
});

it('lets a product override the store rate', function () {
    $v = vendor('Island Mart', rateBp: 200, priceLaari: 10000);
    $v['product']->forceFill(['cashback_rate_bp' => 500])->save();

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])->assertOk();

    // 5% of 10000 = 500, not the store's 2%.
    expect($this->getJson('/api/customer/cart')->json('data.cashback_laari'))->toBe(500);
});

it('says how far short a subcart is of that shop\'s minimum', function () {
    $v = vendor('Horizon Bookstore', priceLaari: 12000);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id,
        'zone_id' => $this->zone->id,
        'order_minimum_laari' => 15000,
        'delivery_fee_laari' => 1500,
    ]);

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])->assertOk();

    $cart = $this->getJson('/api/customer/cart')->assertOk()->json('data');

    // "Add MVR 30.00 more to reach the minimum order" — and checkout is
    // blocked until it is met.
    expect($cart['subcarts'][0]['delivery']['minimum_met'])->toBeFalse()
        ->and($cart['subcarts'][0]['delivery']['shortfall_laari'])->toBe(3000)
        ->and($cart['can_checkout'])->toBeFalse();
});

it('waives delivery once the shop\'s island threshold is met', function () {
    $v = vendor('Island Mart', priceLaari: 10000);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id,
        'zone_id' => $this->zone->id,
        'delivery_fee_laari' => 2500,
        'free_delivery_over_laari' => 25000,
    ]);

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 2])->assertOk();
    expect($this->getJson('/api/customer/cart')->json('data.delivery_laari'))->toBe(2500);

    // One more item crosses the threshold.
    $item = $this->getJson('/api/customer/cart')->json('data.subcarts.0.items.0.cart_item_id');
    $this->patchJson("/api/customer/cart/items/{$item}", ['qty' => 3])->assertOk();

    expect($this->getJson('/api/customer/cart')->json('data.delivery_laari'))->toBe(0);
});

it('re-prices from the live listing and SAYS the price moved', function () {
    $v = vendor('Island Mart', priceLaari: 10000);

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])->assertOk();

    $v['listing']->forceFill(['price_laari' => 12000])->save();

    $line = $this->getJson('/api/customer/cart')->assertOk()->json('data.subcarts.0.items.0');

    // Billed at the live price — a basket that quietly charges yesterday's
    // price is worse than one that says the price changed.
    expect($line['unit_price_laari'])->toBe(12000)
        ->and($line['price_changed'])->toBeTrue()
        ->and($line['price_was_laari'])->toBe(10000);
});

it('flags a line that sold out rather than dropping it', function () {
    $v = vendor('Island Mart');

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])->assertOk();

    $v['listing']->forceFill(['stock_qty' => 0])->save();

    $cart = $this->getJson('/api/customer/cart')->assertOk()->json('data');

    // A row that vanishes reads as a bug, and the shopper cannot act on what
    // they cannot see.
    expect($cart['subcarts'][0]['items'][0]['available'])->toBeFalse()
        ->and($cart['subcarts'][0]['all_available'])->toBeFalse();
});

it('refuses to add something that is not for sale', function () {
    $v = vendor('Island Mart');
    $v['listing']->forceFill(['state' => 'out_of_stock'])->save();

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])
        ->assertStatus(409)
        ->assertJsonPath('code', 'item_unavailable');
});

it('removes a line when the stepper reaches zero', function () {
    $v = vendor('Island Mart');
    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 2])->assertOk();

    $item = $this->getJson('/api/customer/cart')->json('data.subcarts.0.items.0.cart_item_id');
    $this->patchJson("/api/customer/cart/items/{$item}", ['qty' => 0])->assertOk();

    expect($this->getJson('/api/customer/cart')->json('data.store_count'))->toBe(0);
});

it('prices for a different address when asked', function () {
    $other = Zone::create(['name' => "Hulhumale'", 'polygon' => [
        ['lat' => 4.20, 'lng' => 73.53],
        ['lat' => 4.24, 'lng' => 73.53],
        ['lat' => 4.24, 'lng' => 73.56],
        ['lat' => 4.20, 'lng' => 73.56],
    ]]);
    $far = CustomerAddress::factory()->for($this->customer)->create([
        'zone_id' => $other->id, 'is_default' => false,
    ]);

    $v = vendor('Island Mart', priceLaari: 10000);
    // Cheap at home, dear across the water — the owner's case.
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id, 'zone_id' => $this->zone->id,
        'delivery_fee_laari' => 2500, 'free_delivery_over_laari' => null,
    ]);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id, 'zone_id' => $other->id,
        'delivery_fee_laari' => 6000, 'free_delivery_over_laari' => null,
    ]);

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])->assertOk();

    expect($this->getJson('/api/customer/cart')->json('data.delivery_laari'))->toBe(2500)
        ->and($this->getJson("/api/customer/cart?address_id={$far->id}")->json('data.delivery_laari'))->toBe(6000);
});

it('cannot be checked out with no address to deliver to', function () {
    $this->address->forceFill(['zone_id' => null])->save();
    $v = vendor('Island Mart');
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id, 'zone_id' => $this->zone->id,
    ]);

    $this->postJson('/api/customer/cart/items', ['branch_product_id' => $v['listing']->id, 'qty' => 1])->assertOk();

    // No zone means no branch can quote — the shop shows as pickup-only
    // rather than vanishing.
    expect($this->getJson('/api/customer/cart')->json('data.subcarts.0.delivery.delivers'))->toBeFalse();
});

// ----------------------------------------------------------------- browse

it('lists branches as storefronts, not merchants', function () {
    $v = vendor('Island Mart');

    $rows = $this->getJson('/api/market/branches')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['store_name'])->toBe('Island Mart')
        ->and($rows[0]['branch_name'])->toBe('Malé')
        // A new shop has no rating; zero stars would libel it.
        ->and($rows[0]['rating'])->toBeNull();
});

it('hides a store that is not an approved vendor, or has paused itself', function () {
    $v = vendor('Island Mart');
    expect($this->getJson('/api/market/branches')->json('data'))->toHaveCount(1);

    // Paused by its own owner.
    $v['merchant']->forceFill(['unpublished_at' => now()])->save();
    expect($this->getJson('/api/market/branches')->json('data'))->toHaveCount(0);

    $v['merchant']->forceFill(['unpublished_at' => null])->save();

    // Enrolled but not yet approved as a vendor.
    $v['merchant']->marketplace()->update(['state' => 'pending_kyb']);
    expect($this->getJson('/api/market/branches')->json('data'))->toHaveCount(0);
});

it('shows one shop\'s shelves, and only the aisles it stocks', function () {
    $v = vendor('Island Mart');

    $data = $this->getJson("/api/market/branches/{$v['branch']->id}")->assertOk()->json('data');

    expect($data['store_name'])->toBe('Island Mart')
        ->and($data['products'])->toHaveCount(1)
        ->and($data['products'][0]['in_stock'])->toBeTrue()
        // An empty category chip is a promise the shelf cannot keep.
        ->and($data['categories'])->toHaveCount(0);
});

it('hides the market entirely when the switch is off', function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 0);

    $this->getJson('/api/market/branches')->assertNotFound();
    $this->getJson('/api/customer/cart')->assertNotFound();
});
