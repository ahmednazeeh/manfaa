<?php

declare(strict_types=1);

use App\Domain\Marketplace\MerchantPayoutBuilder;
use App\Domain\Platform\PlatformConfig;
use App\Models\AdminUser;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\MerchantPayoutBatch;
use App\Models\MerchantPayoutItem;
use App\Models\MerchantUser;
use App\Models\Settlement;
use App\Models\Suborder;
use App\Models\TransferProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/**
 * MP10 — what the PLATFORM owes a shop (§5.5).
 *
 * The opposite direction from the settlements this platform already has, and
 * kept apart from them for exactly that reason.
 */
beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $this->zone = marketZone();
    $this->customer = Customer::factory()->create();
    CustomerAddress::factory()->for($this->customer)->create([
        'zone_id' => $this->zone->id, 'is_default' => true,
    ]);

    $this->vendor = vendor('Island Mart', rateBp: 200, priceLaari: 10000);
    $this->vendor['merchant']->forceFill([
        'bank_name' => 'BML',
        'bank_account' => '7730000999888',
        'bank_account_name' => 'Island Mart Pvt Ltd',
        'validation_window_days' => 3,
    ])->save();

    BranchDeliveryRule::factory()->create([
        'branch_id' => $this->vendor['branch']->id,
        'zone_id' => $this->zone->id,
        'delivery_fee_laari' => 2500,
    ]);

    $this->shopkeeper = MerchantUser::factory()->for($this->vendor['merchant'])->owner()->create();
    $this->admin = AdminUser::factory()->create(['role' => 'superadmin']);
});

/** Deliver an order and age it past the store's validation window. */
function settledOrder($test, int $qty = 3, int $daysAgo = 10): Suborder
{
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $test->vendor['listing']->id, 'qty' => $qty,
        ])->assertOk();
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    $sub = Suborder::query()->latest('id')->firstOrFail();

    $test->actingAs($test->shopkeeper, 'merchant');
    $test->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();
    foreach (['preparing', 'ready', 'out_for_delivery', 'delivered'] as $state) {
        $test->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => $state])->assertOk();
    }

    $sub->fresh()->forceFill(['delivered_at' => now()->subDays($daysAgo)])->save();

    return $sub->fresh();
}

it('builds a batch of what we owe, after the store\'s validation window', function () {
    $sub = settledOrder($this);

    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    expect($batch->reference)->toStartWith('MS-')
        ->and($batch->merchant_count)->toBe(1)
        // items + delivery − cashback − our fee, exactly as frozen.
        ->and($batch->total_laari)->toBe($sub->payable_to_merchant_laari);
});

it('leaves an order alone until its validation window has passed', function () {
    // Delivered yesterday, window is three days: a return is still possible,
    // and paying now would be paying before the order is final.
    settledOrder($this, daysAgo: 1);

    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    expect($batch->merchant_count)->toBe(0)
        ->and($batch->total_laari)->toBe(0);
});

it('claims every order it pays for, so a second batch cannot pay again', function () {
    $sub = settledOrder($this);

    app(MerchantPayoutBuilder::class)->build($this->admin);

    expect($sub->fresh()->payout_item_id)->not->toBeNull();

    $second = app(MerchantPayoutBuilder::class)->build($this->admin);

    // The link is the whole of what stops an order being paid twice.
    expect($second->merchant_count)->toBe(0);
});

it('surfaces money waiting on bank details rather than losing it', function () {
    $this->vendor['merchant']->forceFill(['bank_account' => ''])->save();
    $sub = settledOrder($this);

    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    expect($batch->merchant_count)->toBe(0)
        ->and($batch->excluded_count)->toBe(1)
        ->and($batch->excluded_laari)->toBe($sub->payable_to_merchant_laari)
        // And the order stays unclaimed, so it falls into the next run.
        ->and($sub->fresh()->payout_item_id)->toBeNull();
});

it('gives every item an idempotency key', function () {
    settledOrder($this);

    app(MerchantPayoutBuilder::class)->build($this->admin);

    // The same string the bank deduplicates on, and the payout key on the
    // sheet: one identifier everywhere.
    expect(MerchantPayoutItem::query()->sole()->internal_ref)->toStartWith('manfaa-m-');
});

it('exports a sheet and takes the filled one back', function () {
    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);
    $item = MerchantPayoutItem::query()->sole();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/approve")->assertOk();

    $export = $this->actingAs($this->admin, 'admin')
        ->get("/api/admin/merchant-settlements/{$batch->id}/export");

    $export->assertOk();
    expect($export->headers->get('content-disposition'))->toContain($batch->reference);

    // Finance fills the reference column and sends it back.
    $csv = "Payout Key,Merchant,Account Name,Account Number,Amount Owed,Transfer Reference Number\n"
        ."{$item->internal_ref},Island Mart,Island Mart Pvt Ltd,7730000999888,120.00,FT99881234\n";

    $result = $this->actingAs($this->admin, 'admin')
        ->post("/api/admin/merchant-settlements/{$batch->id}/import", [
            'file' => UploadedFile::fake()->createWithContent('filled.csv', $csv),
        ])->assertOk()->json('data');

    expect($result['matched'])->toBe(1)
        ->and($result['paid'])->toBe(1)
        ->and($item->fresh()->state)->toBe('sent')
        ->and($item->fresh()->trx_id)->toBe('FT99881234')
        ->and($batch->fresh()->state)->toBe('completed');
});

it('reports a key it never issued rather than guessing', function () {
    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/approve")->assertOk();

    $csv = "Payout Key,Merchant,Account Name,Account Number,Amount Owed,Transfer Reference Number\n"
        ."manfaa-m-nonsense,Someone,Else,123,10.00,FT1\n";

    $result = $this->actingAs($this->admin, 'admin')
        ->post("/api/admin/merchant-settlements/{$batch->id}/import", [
            'file' => UploadedFile::fake()->createWithContent('filled.csv', $csv),
        ])->assertOk()->json('data');

    // A mistyped row must not silently mark the wrong shop paid.
    expect($result['unmatched'])->toBe(['manfaa-m-nonsense'])
        ->and($result['paid'])->toBe(0);
});

it('does not double-pay when the same sheet is imported twice', function () {
    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);
    $item = MerchantPayoutItem::query()->sole();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/approve")->assertOk();

    $csv = "Payout Key,Merchant,Account Name,Account Number,Amount Owed,Transfer Reference Number\n"
        ."{$item->internal_ref},Island Mart,X,7730000999888,120.00,FT1\n";

    $upload = fn () => $this->actingAs($this->admin, 'admin')
        ->post("/api/admin/merchant-settlements/{$batch->id}/import", [
            'file' => UploadedFile::fake()->createWithContent('filled.csv', $csv),
        ])->assertOk()->json('data');

    expect($upload()['paid'])->toBe(1)
        // Re-importing a sheet is a thing people do.
        ->and($upload()['paid'])->toBe(0)
        ->and($item->fresh()->trx_id)->toBe('FT1');
});

it('sends through the bank API with the same idempotency key', function () {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-M1'], 200)]);

    TransferProfile::query()->create([
        'name' => 'Faseyha', 'base_url' => 'http://10.99.0.1:3005', 'segment' => '/faisanet',
        'from_account' => '90501400021681000', 'active' => true, 'is_default' => true,
    ]);
    config(['services.transfer.api_key' => 'k']);

    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);
    $item = MerchantPayoutItem::query()->sole();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/approve")->assertOk();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/items/{$item->id}/send")
        ->assertOk()->assertJsonPath('data.state', 'sent');

    Http::assertSent(fn ($request): bool => $request['internal_ref'] === $item->internal_ref);
});

it('never re-sends a parked merchant transfer', function () {
    Http::fake(['*' => Http::response(['status' => 'pending_approval', 'approval_id' => 'rec_9'], 200)]);

    TransferProfile::query()->create([
        'name' => 'Cleviden', 'base_url' => 'http://10.99.0.1:3005', 'segment' => '/faisanet4',
        'dual_control' => true, 'active' => true, 'is_default' => true,
    ]);
    config(['services.transfer.api_key' => 'k']);

    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);
    $item = MerchantPayoutItem::query()->sole();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/approve")->assertOk();

    $send = fn () => $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/items/{$item->id}/send");

    $send()->assertOk()->assertJsonPath('data.state', 'pending_approval');

    // Alive, not failed. A queue record id is not a bank reference.
    expect($item->fresh()->approval_id)->toBe('rec_9')
        ->and($item->fresh()->trx_id)->toBeNull();

    $send()->assertStatus(409)->assertJsonPath('code', 'pending_approval');
});

it('cancelling a batch releases the orders it claimed', function () {
    $sub = settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/merchant-settlements/{$batch->id}/cancel")->assertOk();

    // They fall into the next run rather than vanishing with the batch.
    expect($sub->fresh()->payout_item_id)->toBeNull()
        ->and($batch->fresh()->state)->toBe('cancelled');
});

it('will not export a batch nobody approved', function () {
    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    $this->actingAs($this->admin, 'admin')
        ->get("/api/admin/merchant-settlements/{$batch->id}/export")
        ->assertStatus(409);
});

it('keeps an ordinary admin out of the money', function () {
    settledOrder($this);
    $admin = AdminUser::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin')->getJson('/api/admin/merchant-settlements')->assertOk();
    $this->actingAs($admin, 'admin')->postJson('/api/admin/merchant-settlements')->assertForbidden();
});

it('never mixes the two ledgers on one screen', function () {
    // A merchant can owe US cashback from till sales AND be owed money for
    // marketplace orders. The two must not net (§5.4): one combined figure
    // is a figure neither side can check.
    settledOrder($this);
    $batch = app(MerchantPayoutBuilder::class)->build($this->admin);

    expect(MerchantPayoutBatch::query()->count())->toBe(1)
        // Nothing here touched the merchant's own settlement ledger.
        ->and(Settlement::query()->count())->toBe(0);
});
