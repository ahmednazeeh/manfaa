<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\Suborder;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/**
 * MP5 — the cart becomes an order (`Order Received.png`, `Payment Step.png`).
 *
 * One payment, several shops. Every rate frozen at the moment the customer
 * agreed to it, because MP6's amendments will recompute against these
 * numbers and a platform change must never reach backwards into them.
 */
beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $this->zone = marketZone();
    $this->customer = Customer::factory()->create();
    $this->address = CustomerAddress::factory()->for($this->customer)->create([
        'zone_id' => $this->zone->id, 'is_default' => true,
    ]);
    $this->actingAs($this->customer, 'customer');
});

function stockedVendor(string $name, int $priceLaari = 10000, int $rateBp = 200): array
{
    $v = vendor($name, rateBp: $rateBp, priceLaari: $priceLaari);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $v['branch']->id,
        'zone_id' => Zone::query()->first()->id,
        'delivery_fee_laari' => 2500,
        'free_delivery_over_laari' => null,
    ]);

    return $v;
}

function fill($test, array $vendor, int $qty = 1): void
{
    $test->postJson('/api/customer/cart/items', [
        'branch_product_id' => $vendor['listing']->id, 'qty' => $qty,
    ])->assertOk();
}

it('turns a three-shop cart into one order and three suborders', function () {
    $a = stockedVendor('Island Mart');
    $b = stockedVendor('Horizon Bookstore');
    $c = stockedVendor('The Coffee Club');

    fill($this, $a);
    fill($this, $b);
    fill($this, $c);

    $order = $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertCreated()
        ->json('data');

    expect($order['store_count'])->toBe(3)
        ->and($order['reference'])->toStartWith('MF-')
        // One payment for the lot.
        ->and($order['total_payable_laari'])->toBe(3 * 12500)
        ->and($order['payment_state'])->toBe('awaiting_proof');

    // Each shop gets its own reference, numbered within the order.
    expect(Suborder::query()->pluck('reference')->sort()->values()->all())
        ->each->toMatch('/^MF-\d+-\d{2}$/');
});

it('freezes every rate at the moment the customer agreed to it', function () {
    $v = stockedVendor('Island Mart', priceLaari: 10000, rateBp: 200);
    fill($this, $v);

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $sub = Suborder::query()->sole();

    // 2% cashback and the platform's 2% fee, both on ITEMS only.
    expect($sub->cashback_rate_bp)->toBe(200)
        ->and($sub->cashback_laari)->toBe(200)
        ->and($sub->order_fee_bp)->toBe(200)
        ->and($sub->order_fee_laari)->toBe(200)
        // items + delivery − cashback − fee
        ->and($sub->payable_to_merchant_laari)->toBe(10000 + 2500 - 200 - 200);

    // The platform changes its cut. The placed order must not move.
    app(PlatformConfig::class)->set('marketplace_fee_bp', 900);

    expect($sub->fresh()->order_fee_bp)->toBe(200)
        ->and($sub->fresh()->payable_to_merchant_laari)->toBe(12100);
});

it('honours a per-merchant fee override', function () {
    $v = stockedVendor('Island Mart', priceLaari: 10000);
    $v['merchant']->marketplace()->update(['order_fee_bp' => 500]);
    fill($this, $v);

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    expect(Suborder::query()->sole()->order_fee_laari)->toBe(500);
});

it('refuses a cart that is short of a shop\'s minimum, and names the shop', function () {
    $v = stockedVendor('Horizon Bookstore', priceLaari: 12000);
    $v['branch']->deliveryRules()->update(['order_minimum_laari' => 15000]);
    fill($this, $v);

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'below_minimum')
        // "Checkout failed" is not something a shopper can act on.
        ->assertJsonFragment(['message' => 'Horizon Bookstore — Malé needs MVR 30.00 more before it can deliver.']);

    expect(Order::query()->count())->toBe(0);
});

it('keeps the basket when checkout fails', function () {
    $v = stockedVendor('Horizon Bookstore', priceLaari: 12000);
    $v['branch']->deliveryRules()->update(['order_minimum_laari' => 15000]);
    fill($this, $v);

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertUnprocessable();

    // A checkout that failed halfway AND took the basket with it is the
    // worst outcome available.
    expect($this->getJson('/api/customer/cart')->json('data.store_count'))->toBe(1);
});

it('empties the basket only on success', function () {
    fill($this, stockedVendor('Island Mart'));

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    expect($this->getJson('/api/customer/cart')->json('data.store_count'))->toBe(0);
});

it('refuses an empty cart', function () {
    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'cart_empty');
});

it('refuses a line that sold out between basket and checkout', function () {
    $v = stockedVendor('Island Mart');
    fill($this, $v);

    $v['listing']->forceFill(['stock_qty' => 0])->save();

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'item_unavailable');
});

it('snapshots the address so a later edit cannot move the order', function () {
    fill($this, stockedVendor('Island Mart'));

    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $this->address->forceFill(['building' => 'Somewhere Else Entirely'])->save();

    expect(Order::query()->sole()->address_snapshot['building'])->not->toBe('Somewhere Else Entirely');
});

it('snapshots the product name so a rename cannot rewrite history', function () {
    $v = stockedVendor('Island Mart');
    fill($this, $v);
    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $v['product']->forceFill(['name' => 'Renamed Later'])->save();

    expect(Suborder::query()->sole()->items()->sole()->name)->toBe('Island Mart Rice');
});

it('records what was ordered as what is expected, until a shop says otherwise', function () {
    fill($this, stockedVendor('Island Mart'), qty: 3);
    $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $item = Suborder::query()->sole()->items()->sole();

    // MP6 amendments move fulfilled_qty; the gap is what the customer sees.
    expect($item->qty)->toBe(3)
        ->and($item->fulfilled_qty)->toBe(3)
        ->and($item->wasAmended())->toBeFalse();
});

it('takes a receipt and moves the order under review', function () {
    Storage::fake('local');
    fill($this, stockedVendor('Island Mart'));

    $order = $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertCreated()->json('data.id');

    $this->postJson("/api/customer/orders/{$order}/receipt", [
        'receipt' => UploadedFile::fake()->image('transfer.jpg'),
    ])->assertOk()
        ->assertJsonPath('data.payment_state', 'proof_submitted')
        ->assertJsonPath('data.state', 'under_review');

    // A receipt carries an account number — private disk, never public.
    expect(Order::query()->sole()->receipt_path)->toStartWith('order-receipts/');
});

it('publishes where to send the money', function () {
    PlatformBankAccount::query()->create([
        'bank_name' => 'BML', 'account_no' => '200012345678',
        'account_name' => 'Manfaa Pvt Ltd', 'currency' => 'MVR',
        'is_primary' => true, 'active' => true,
    ]);

    $this->getJson('/api/customer/payment-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.bank_name', 'BML');
});

it('keeps one customer out of another\'s orders', function () {
    fill($this, stockedVendor('Island Mart'));
    $order = $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertCreated()->json('data.id');

    $intruder = Customer::factory()->create();

    $this->actingAs($intruder, 'customer')
        ->getJson("/api/customer/orders/{$order}")
        ->assertNotFound();
});

it('gives every order a distinct reference under concurrency', function () {
    // The advisory lock serialises generation; the unique index backstops
    // it. Sequential here, but the constraint is what actually guarantees it.
    $references = [];

    for ($i = 0; $i < 5; $i++) {
        fill($this, stockedVendor('Shop '.$i));
        $references[] = $this->postJson('/api/customer/orders', ['payment_method' => 'bml'])
            ->assertCreated()->json('data.reference');
    }

    expect($references)->toHaveCount(5)
        ->and(array_unique($references))->toHaveCount(5);
});
