<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\MerchantUser;
use App\Models\Suborder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/**
 * GST ON THE MARKETPLACE ORDER FEE — the platform's OTHER charge on a
 * merchant, and the sibling of the till fee `CreditRecorder` taxes.
 *
 * The order fee is deducted from what the shop is paid, so it is a Manfaa
 * charge on the merchant in exactly the sense the till fee is. When the
 * superadmin throws the switch it must be taxed the same way, under the
 * same treatments, or the platform would charge (and have to remit) tax on
 * one of its two fee streams and not the other.
 *
 * The invariant, identical to the till's: `order_fee_laari` is Manfaa's NET
 * revenue, `order_fee_gst_laari` is the tax owed to MIRA, and the shop is
 * paid `subtotal − cashback − fee − GST`.
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
function placeGstOrder($test, int $qty = 1): Suborder
{
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $test->vendor['listing']->id, 'qty' => $qty,
        ])->assertOk();

    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])
        ->assertCreated();

    return payFor(Suborder::query()->latest('id')->firstOrFail());
}

it('leaves the order fee untaxed while the switch is off', function () {
    $sub = placeGstOrder($this);

    expect($sub->order_fee_laari)->toBe(200)
        ->and($sub->order_fee_gst_laari)->toBe(0)
        // The identity, stamped: re-pricing this row from its own terms
        // reproduces the zero exactly.
        ->and($sub->order_fee_gst_bp)->toBe(0)
        ->and($sub->order_fee_treatment)->toBe('on_top')
        ->and($sub->payable_to_merchant_laari)->toBe(10000 + 2500 - 200 - 200);
});

it('adds the tax to the order fee under on_top, and the shop is paid less', function () {
    GstFixture::enable(treatment: 'on_top');

    $sub = placeGstOrder($this);

    // Fee 2% of 10000 = 200; GST 8% ON TOP = ceil(200 × 800 / 10000) = 16.
    expect($sub->order_fee_laari)->toBe(200)
        ->and($sub->order_fee_gst_laari)->toBe(16)
        ->and($sub->order_fee_gst_bp)->toBe(800)
        ->and($sub->order_fee_treatment)->toBe('on_top')
        ->and($sub->payable_to_merchant_laari)->toBe(10000 + 2500 - 200 - 200 - 16);
});

it('carves the tax out of the order fee under inclusive, and the shop is paid the same', function () {
    GstFixture::enable(treatment: 'inclusive');

    $sub = placeGstOrder($this);

    // GST = ceil(200 × 800 / 10800) = 15, net fee = 185: the shop is paid
    // exactly what it would have been paid untaxed — Manfaa's own revenue
    // absorbs the tax.
    expect($sub->order_fee_laari)->toBe(185)
        ->and($sub->order_fee_gst_laari)->toBe(15)
        ->and($sub->order_fee_gst_bp)->toBe(800)
        ->and($sub->order_fee_treatment)->toBe('inclusive')
        ->and($sub->payable_to_merchant_laari)->toBe(10000 + 2500 - 200 - 185 - 15);
});

it('re-prices an amendment from the order\'s own stamp, never the live setting', function () {
    GstFixture::enable(treatment: 'on_top');

    $sub = placeGstOrder($this, qty: 3);
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    // The platform doubles its GST and switches treatment AFTER the order
    // was placed. The recompute must ignore both.
    GstFixture::rate(1600);
    GstFixture::treatment('inclusive');

    $item = $sub->items()->sole();

    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $item->id, 'fulfilled_qty' => 2]],
        'reason' => 'out_of_stock',
    ])->assertOk();

    $fresh = $sub->fresh();

    // 2 × 10000 = 20000 items. Fee 2% = 400; GST at the STAMPED 8% on_top =
    // ceil(400 × 800 / 10000) = 32 — not 64 (16%), and not carved out.
    expect($fresh->order_fee_laari)->toBe(400)
        ->and($fresh->order_fee_gst_laari)->toBe(32)
        ->and($fresh->order_fee_gst_bp)->toBe(800)
        ->and($fresh->order_fee_treatment)->toBe('on_top')
        ->and($fresh->payable_to_merchant_laari)->toBe(20000 + 2500 - 400 - 400 - 32);
});

it('shows the shop the tax deducted from its payout', function () {
    GstFixture::enable(treatment: 'on_top');

    $sub = placeGstOrder($this);

    $this->actingAs($this->shopkeeper, 'merchant')
        ->getJson("/api/merchant/marketplace/orders/{$sub->id}")
        ->assertOk()
        ->assertJsonPath('data.order_fee_laari', 200)
        ->assertJsonPath('data.order_fee_gst_laari', 16);
});
