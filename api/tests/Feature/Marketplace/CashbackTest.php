<?php

declare(strict_types=1);

use App\Domain\Marketplace\MarketplaceCashbackService;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Standing\ValidationSweeper;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\MerchantUser;
use App\Models\Suborder;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/**
 * MP8 — a delivered marketplace order becomes cashback (§5.3).
 *
 * One wallet, one feed, one payout. The thing these tests guard hardest is
 * the boundary between the two ledgers: in a till sale the merchant OWES us
 * the cashback, in a marketplace order we already hold the money and deduct
 * it. Getting that wrong bills a shop twice for one sale.
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
    ]);
    $this->shopkeeper = MerchantUser::factory()->for($this->vendor['merchant'])->owner()->create();
});

/** Place, accept and deliver an order of `$qty`. */
function deliverOrder($test, int $qty = 3): Suborder
{
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $test->vendor['listing']->id, 'qty' => $qty,
        ])->assertOk();
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $sub = payFor(Suborder::query()->latest('id')->firstOrFail());

    $test->actingAs($test->shopkeeper, 'merchant');
    $test->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    foreach (['preparing', 'ready', 'out_for_delivery', 'delivered'] as $state) {
        $test->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => $state])->assertOk();
    }

    return $sub->fresh();
}

it('credits the shopper when the order is handed over', function () {
    $sub = deliverOrder($this, qty: 3);

    $transaction = Transaction::query()->where('suborder_id', $sub->id)->sole();

    // 30000 laari of goods at 2%.
    expect($transaction->cashback_laari)->toBe(600)
        ->and($transaction->origin)->toBe('marketplace')
        ->and($transaction->customer_id)->toBe($this->customer->id)
        // The shop's own reference, so both sides quote the same string.
        ->and($transaction->invoice_no)->toBe($sub->reference);
});

it('charges the merchant NO cashback fee — our cut was the marketplace fee', function () {
    $sub = deliverOrder($this);

    $transaction = Transaction::query()->where('suborder_id', $sub->id)->sole();

    // Billing the standard fee here would charge them twice for one sale:
    // the 2% marketplace fee is already deducted from their payout.
    expect($transaction->fee_laari)->toBe(0)
        ->and($transaction->fee_gst_laari)->toBe(0)
        ->and($sub->order_fee_laari)->toBeGreaterThan(0);
});

it('never bills a marketplace reward on a settlement', function () {
    $sub = deliverOrder($this);
    $transaction = Transaction::query()->where('suborder_id', $sub->id)->sole();

    // Force it into the state a settlement gathers from. Even there it must
    // not appear: this is the only thing keeping the two ledgers apart.
    $transaction->forceFill(['state' => 'payable_unfunded'])->save();

    $eligible = app(SettlementBuilder::class)
        ->eligibleTransactions($this->vendor['merchant'])
        ->pluck('id');

    expect($eligible)->not->toContain($transaction->id);
});

it('still bills an ordinary till sale', function () {
    // The exclusion must be surgical: everything that IS a receivable stays
    // one.
    $till = Transaction::factory()->create([
        'merchant_id' => $this->vendor['merchant']->id,
        'customer_id' => $this->customer->id,
        'origin' => 'manual',
        'state' => 'payable_unfunded',
        'cashback_laari' => 500,
    ]);

    $eligible = app(SettlementBuilder::class)
        ->eligibleTransactions($this->vendor['merchant'])
        ->pluck('id');

    expect($eligible)->toContain($till->id);
});

it('confirms a marketplace reward without it ever becoming a receivable', function () {
    $sub = deliverOrder($this);
    $transaction = Transaction::query()->where('suborder_id', $sub->id)->sole();

    expect($transaction->state->value)->toBe('awaiting_validation');

    // Past the store's validation window.
    $transaction->forceFill(['occurred_at' => now()->subDays(30)])->save();
    app(ValidationSweeper::class)->run();

    // Straight to confirmed. `payable_unfunded` would be a lie in the data —
    // the platform is already holding the money.
    expect($transaction->fresh()->state->value)->toBe('confirmed');
});

it('sends an ordinary sale to payable_unfunded, as it always did', function () {
    $till = Transaction::factory()->create([
        'merchant_id' => $this->vendor['merchant']->id,
        'customer_id' => $this->customer->id,
        'origin' => 'manual',
        'state' => 'awaiting_validation',
        'cashback_laari' => 500,
        'occurred_at' => now()->subDays(30),
    ]);

    app(ValidationSweeper::class)->run();

    expect($till->fresh()->state->value)->toBe('payable_unfunded');
});

it('credits only what was actually supplied after an amendment', function () {
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $this->vendor['listing']->id, 'qty' => 3,
        ])->assertOk();
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $sub = payFor(Suborder::query()->latest('id')->firstOrFail());
    $this->actingAs($this->shopkeeper, 'merchant');
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    // One bag short.
    $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
        'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 2]],
        'reason' => 'out_of_stock',
    ])->assertOk();

    foreach (['preparing', 'ready', 'out_for_delivery', 'delivered'] as $state) {
        $this->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => $state])->assertOk();
    }

    // 2 × 10000 at 2% = 400, not the 600 they were promised for three.
    expect(Transaction::query()->where('suborder_id', $sub->id)->sole()->cashback_laari)->toBe(400);
});

it('cannot credit the same order twice', function () {
    $sub = deliverOrder($this);

    // A retried job, a double tap, a replayed queue message — all lose at
    // the unique index rather than paying somebody twice.
    app(MarketplaceCashbackService::class)->credit($sub->fresh());
    app(MarketplaceCashbackService::class)->credit($sub->fresh());

    expect(Transaction::query()->where('suborder_id', $sub->id)->count())->toBe(1);
});

it('credits nothing for an order that was never handed over', function () {
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $this->vendor['listing']->id, 'qty' => 1,
        ])->assertOk();
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $sub = payFor(Suborder::query()->latest('id')->firstOrFail());

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/reject", ['reason' => 'Closed today.'])
        ->assertOk();

    expect(Transaction::query()->where('suborder_id', $sub->id)->count())->toBe(0);
});

it('shows marketplace cashback in the one Activity timeline', function () {
    $sub = deliverOrder($this);

    $feed = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/activity')->assertOk()->json('data');

    // The order card AND the cashback it earned, in one place.
    $kinds = array_column($feed, 'kind');
    expect($kinds)->toContain('order')->toContain('transaction');
});
