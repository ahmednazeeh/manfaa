<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\AdminUser;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerRefund;
use App\Models\MerchantUser;
use App\Models\Suborder;
use App\Models\SuborderAmendment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/**
 * MP6 — the shop's half (`Orders.png`, `Order Details.png`) and the
 * reduction flow (§2.7, §5.4c).
 */
beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $this->zone = marketZone();
    $this->customer = Customer::factory()->create();
    CustomerAddress::factory()->for($this->customer)->create([
        'zone_id' => $this->zone->id, 'is_default' => true,
    ]);

    $this->vendor = vendor('Island Mart', rateBp: 200, priceLaari: 10000);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $this->vendor['branch']->id,
        'zone_id' => $this->zone->id,
        'delivery_fee_laari' => 2500,
        'free_delivery_over_laari' => null,
    ]);

    $this->shopkeeper = MerchantUser::factory()->for($this->vendor['merchant'])->owner()->create();
});

/** Place an order of `$qty` from the fixture vendor. */
function placeOrder($test, int $qty = 3): Suborder
{
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $test->vendor['listing']->id, 'qty' => $qty,
        ])->assertOk();

    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertCreated();

    return Suborder::query()->latest('id')->firstOrFail();
}

it('walks an order from new to delivered, and refuses shortcuts', function () {
    $sub = placeOrder($this);
    $this->actingAs($this->shopkeeper, 'merchant');

    // "Ready" before "accepted" is not a shortcut, it is a lie about what
    // happened to somebody's shopping.
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => 'ready'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_transition');

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")
        ->assertOk()->assertJsonPath('data.state', 'accepted');

    foreach (['preparing', 'ready', 'out_for_delivery', 'delivered'] as $state) {
        $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => $state])
            ->assertOk()->assertJsonPath('data.state', $state);
    }
});

it('mints a pickup code for collection, and none for delivery', function () {
    $sub = placeOrder($this);
    $this->actingAs($this->shopkeeper, 'merchant');

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    // This order is a delivery, so there is nothing to prove at a counter.
    expect($sub->fresh()->pickup_code)->toBeNull();

    $pickup = Suborder::query()->create([
        ...$sub->fresh()->only(['order_id', 'merchant_id', 'branch_id', 'items_laari', 'delivery_laari',
            'subtotal_laari', 'cashback_rate_bp', 'cashback_laari', 'order_fee_bp', 'order_fee_laari',
            'payable_to_merchant_laari']),
        'reference' => 'MF-9999-02',
        'fulfilment' => 'pickup',
        'state' => 'new',
    ]);

    $this->postJson("/api/merchant/marketplace/orders/{$pickup->id}/accept")->assertOk();

    expect($pickup->fresh()->pickup_code)->toHaveLength(4);
});

it('rejects with a reason and owes the whole subtotal back', function () {
    $sub = placeOrder($this);
    $this->actingAs($this->shopkeeper, 'merchant');

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/reject", [
        'reason' => 'Closed for a family emergency.',
    ])->assertOk()->assertJsonPath('data.state', 'rejected');

    // Everything they paid this shop, delivery included: they are getting
    // nothing from it.
    $refund = CustomerRefund::query()->sole();
    expect($refund->amount_laari)->toBe($sub->subtotal_laari)
        ->and($refund->reason)->toBe('suborder_rejected')
        ->and($refund->state)->toBe('pending');
});

it('refuses a rejection with no reason', function () {
    $sub = placeOrder($this);

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/reject", [])
        ->assertUnprocessable();
});

// ------------------------------------------------------------- amendments

it('reduces a line and recomputes everything against the frozen rates', function () {
    $sub = placeOrder($this, qty: 3);
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $item = $sub->items()->sole();

    // The platform doubles its fee AFTER the order was placed. The recompute
    // must use the rate frozen at checkout, not today's.
    app(PlatformConfig::class)->set('marketplace_fee_bp', 400);

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $item->id, 'fulfilled_qty' => 2]],
        'reason' => 'out_of_stock',
    ])->assertOk();

    $fresh = $sub->fresh();

    // 2 × 10000 = 20000 items. Cashback 2% = 400. Fee 2% (FROZEN) = 400.
    expect($fresh->items_laari)->toBe(20000)
        ->and($fresh->cashback_laari)->toBe(400)
        ->and($fresh->order_fee_bp)->toBe(200)
        ->and($fresh->order_fee_laari)->toBe(400)
        // Delivery does NOT move — see below.
        ->and($fresh->delivery_laari)->toBe(2500)
        ->and($fresh->payable_to_merchant_laari)->toBe(20000 + 2500 - 400 - 400);
});

it('never makes the customer owe more after a change they did not make', function () {
    // A basket over the free-delivery threshold, cut back under it.
    $this->vendor['branch']->deliveryRules()->update(['free_delivery_over_laari' => 25000]);

    $sub = placeOrder($this, qty: 3);
    expect($sub->delivery_laari)->toBe(0);

    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 1]],
        'reason' => 'out_of_stock',
    ])->assertOk();

    // Dropping to MVR 100 takes it back under the threshold — and the
    // customer still pays nothing for delivery. They met the terms with the
    // order they placed; a shortage on the shop's shelf is not their fault.
    expect($sub->fresh()->delivery_laari)->toBe(0);
});

it('owes exactly the difference, and says who cut what', function () {
    $sub = placeOrder($this, qty: 3);
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $item = $sub->items()->sole();

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $item->id, 'fulfilled_qty' => 1]],
        'reason' => 'out_of_stock',
        'note' => 'Only one bag left on the shelf.',
    ])->assertOk();

    // Two units at MVR 100 each.
    expect(CustomerRefund::query()->sole()->amount_laari)->toBe(20000);

    $amendment = SuborderAmendment::query()->sole();
    expect($amendment->refund_laari)->toBe(20000)
        ->and($amendment->reason)->toBe('out_of_stock')
        // A shop that habitually cuts orders should be visible to an admin
        // rather than a matter of opinion.
        ->and($amendment->merchant_user_id)->toBe($this->shopkeeper->id)
        ->and($amendment->lines()->sole()->qty_before)->toBe(3)
        ->and($amendment->lines()->sole()->qty_after)->toBe(1);
});

it('keeps both numbers so the customer sees the change', function () {
    $sub = placeOrder($this, qty: 3);
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 2]],
        'reason' => 'out_of_stock',
    ])->assertOk();

    $line = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/orders/'.$sub->order_id)
        ->assertOk()
        ->json('data.suborders.0.items.0');

    // The strike-through is drawn from the gap: ordered 3, supplying 2.
    expect($line['qty'])->toBe(3)
        ->and($line['fulfilled_qty'])->toBe(2)
        ->and($line['amended'])->toBeTrue()
        ->and($line['refund_laari'])->toBe(10000);
});

it('refuses to increase an order', function () {
    $sub = placeOrder($this, qty: 2);
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    // The customer authorised an amount and paid it to us. Anything upward
    // is a new order, not an edit.
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 5]],
        'reason' => 'other',
    ])->assertUnprocessable()->assertJsonPath('code', 'cannot_increase');
});

it('sends you to reject rather than amending an order to nothing', function () {
    $sub = placeOrder($this, qty: 2);
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 0]],
        'reason' => 'out_of_stock',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'would_empty_order');

    // Nothing was cut, and nothing is owed.
    expect(CustomerRefund::query()->count())->toBe(0)
        ->and($sub->fresh()->items()->sole()->fulfilled_qty)->toBe(2);
});

it('closes the window once the goods have gone', function () {
    $sub = placeOrder($this, qty: 3);
    $this->actingAs($this->shopkeeper, 'merchant');

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();
    foreach (['preparing', 'ready', 'delivered'] as $state) {
        $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => $state])->assertOk();
    }

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 1]],
        'reason' => 'out_of_stock',
    ])->assertUnprocessable()->assertJsonPath('code', 'not_amendable');
});

it('keeps one shop out of another shop\'s orders', function () {
    $sub = placeOrder($this);
    $other = vendor('Horizon Bookstore');
    $intruder = MerchantUser::factory()->for($other['merchant'])->owner()->create();

    $this->actingAs($intruder, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")
        ->assertNotFound();
});

// ------------------------------------------------------- payment approval

it('lets a superadmin verify a transfer, and an ordinary admin only look', function () {
    $sub = placeOrder($this);
    $order = $sub->order;
    $order->forceFill(['payment_state' => 'proof_submitted', 'proof_submitted_at' => now()])->save();

    $admin = AdminUser::factory()->create(['role' => 'admin']);
    $this->actingAs($admin, 'admin')->getJson('/api/admin/marketplace/payments')->assertOk();
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/payments/{$order->id}/verify")->assertForbidden();

    $super = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($super, 'admin')
        ->postJson("/api/admin/marketplace/payments/{$order->id}/verify")
        ->assertOk()->assertJsonPath('data.payment_state', 'verified');
});

it('sends a refused payment back for another receipt, not to the bin', function () {
    $sub = placeOrder($this);
    $order = $sub->order;
    $order->forceFill(['payment_state' => 'proof_submitted'])->save();

    $super = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($super, 'admin')
        ->postJson("/api/admin/marketplace/payments/{$order->id}/refuse", [
            'reason' => 'The transfer amount does not match the order.',
        ])->assertOk();

    // A wrong screenshot is a fixable mistake; cancelling would throw away a
    // basket somebody built.
    expect($order->fresh()->payment_state)->toBe('refused')
        ->and($order->fresh()->state)->toBe('placed');
});
