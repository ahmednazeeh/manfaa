<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\MerchantUser;
use App\Models\Suborder;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../Marketplace/fixtures.php';

/*
 * THE MARKETPLACE DECISION, WRITTEN DOWN AS A TEST (owner, 2026-08-25).
 *
 * A platform fee promotion does NOT touch `suborders.order_fee_bp`. That is a
 * separate price list — a percentage of an order's items, set per shop on
 * `merchant_marketplace_profiles.order_fee_bp` or platform-wide via the
 * `marketplace_fee_bp` setting, on a completely different scale from the §4
 * cashback tier fees (5% against 0.25–1.00%), and it never posts to ledger
 * account 4100.
 *
 * The GST round discovered this second fee path late. This file exists so
 * nobody has to discover it again: if a future change ever wires promotions
 * into the marketplace pricer, these assertions fail and the decision gets
 * made deliberately rather than by accident.
 *
 * The per-shop lever that DOES exist is that shop's own `order_fee_bp`.
 */
beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

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
    ]);
    $this->shopkeeper = MerchantUser::factory()->for($this->vendor['merchant'])->owner()->create();

    // The shop was approved today AND a platform-wide promotion is running,
    // so both kinds are live for this merchant at the instant it sells.
    $this->vendor['merchant']->forceFill(['approved_at' => CarbonImmutable::now('UTC')])->save();

    FeePromotionFixture::intro(30, 0);
    FeePromotionFixture::platformWide(
        CarbonImmutable::now('UTC')->subDay(),
        CarbonImmutable::now('UTC')->addDays(30),
        0,
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('charges the marketplace order fee in full while a zero-fee promotion is running', function (): void {
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $this->vendor['listing']->id, 'qty' => 3,
        ])->assertOk();

    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $suborder = Suborder::query()->latest('id')->firstOrFail();

    // MVR 300 of goods at the platform's default marketplace fee. The
    // promotion is live for this merchant and prices none of it.
    $expectedFeeBp = app(PlatformConfig::class)->marketplaceFeeBp();

    expect($suborder->items_laari)->toBe(30_000)
        ->and($suborder->order_fee_bp)->toBe($expectedFeeBp)
        ->and($suborder->order_fee_laari)->toBe(intdiv(30_000 * $expectedFeeBp + 9999, 10_000))
        ->and($suborder->order_fee_laari)->toBeGreaterThan(0);
});

it('leaves the marketplace cashback row at the zero fee it already carried, and stamps no promotion on it', function (): void {
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $this->vendor['listing']->id, 'qty' => 3,
        ])->assertOk();

    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $suborder = payFor(Suborder::query()->latest('id')->firstOrFail());

    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$suborder->id}/accept")->assertOk();

    foreach (['preparing', 'ready', 'out_for_delivery', 'delivered'] as $state) {
        $this->postJson("/api/merchant/marketplace/orders/{$suborder->id}/advance", ['state' => $state])->assertOk();
    }

    $transaction = Transaction::query()->where('suborder_id', $suborder->id)->sole();

    // A marketplace cashback row already carries NO §4 fee — our cut was the
    // order fee — so there is nothing for a promotion to relieve, and nothing
    // is stamped. A forgone figure here would double-count an offer the
    // merchant never received.
    expect($transaction->fee_bp)->toBe(0)
        ->and($transaction->fee_laari)->toBe(0)
        ->and($transaction->fee_promo_kind)->toBeNull()
        ->and($transaction->fee_forgone_laari)->toBe(0);
});
