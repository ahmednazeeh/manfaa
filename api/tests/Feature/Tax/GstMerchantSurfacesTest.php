<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\OutstandingSummary;
use App\Domain\Settlement\SettlementPreview;
use App\Http\Resources\TransactionLineResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\V1\TransactionResource as VendorTransactionResource;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * WHEREVER A MERCHANT SEES WHAT THEY OWE, fee and GST are SEPARATE figures
 * (owner decision, 2026-08-24) — never one blended number. A shop that is
 * charged tax has to be able to see the tax, on the screen it pays from and
 * in the API response its own software reads.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->seed(LedgerAccountSeeder::class);
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function surfaceCredit(string $invoice = 'INV-SURFACE', int $eligibleLaari = 100_000): Transaction
{
    return app(ManualCreditService::class)->credit(
        test()->merchant,
        test()->user,
        '482917',
        $invoice,
        Laari::of($eligibleLaari),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    )->refresh();
}

it('breaks fee and GST out on the settlement preview — the screen a merchant pays from', function () {
    GstFixture::enable(800, 'on_top');

    $transaction = surfaceCredit();
    app(TransitionService::class)->makePayable($transaction, Actor::system());

    $preview = app(SettlementPreview::class)->for($this->merchant, null);

    expect($preview['cashback_total_laari'])->toBe(2_000)
        ->and($preview['fee_total_laari'])->toBe(750)
        ->and($preview['fee_total_mvr'])->toBe('7.50')
        ->and($preview['fee_gst_total_laari'])->toBe(60)
        ->and($preview['fee_gst_total_mvr'])->toBe('0.60')
        ->and($preview['amount_due_laari'])->toBe(2_810)
        // And on the instructions block itself, which is what the pay
        // screen prints beside the bank details.
        ->and($preview['payment_instructions']['cashback_total_laari'])->toBe(2_000)
        ->and($preview['payment_instructions']['fee_total_laari'])->toBe(750)
        ->and($preview['payment_instructions']['fee_gst_total_laari'])->toBe(60)
        ->and($preview['payment_instructions']['amount_due_laari'])->toBe(2_810)
        // Per row, too — the picker sums checkboxes client-side.
        ->and($preview['transactions'][0]['fee_laari'])->toBe(750)
        ->and($preview['transactions'][0]['fee_gst_laari'])->toBe(60)
        ->and($preview['transactions'][0]['due_laari'])->toBe(2_810);
});

it('breaks GST out on the outstanding summary the dashboard reads', function () {
    GstFixture::enable(800, 'on_top');

    $transaction = surfaceCredit();
    app(TransitionService::class)->makePayable($transaction, Actor::system());

    $outstanding = app(OutstandingSummary::class)->forMerchant($this->merchant);

    expect($outstanding['total']['fee_laari'])->toBe(750)
        ->and($outstanding['total']['fee_mvr'])->toBe('7.50')
        ->and($outstanding['total']['fee_gst_laari'])->toBe(60)
        ->and($outstanding['total']['fee_gst_mvr'])->toBe('0.60')
        ->and($outstanding['total']['payable_laari'])->toBe(2_810)
        ->and($outstanding['buckets']['0_5']['fee_gst_laari'])->toBe(60);
});

it('states the FROZEN GST terms on the vendor and panel transaction shapes', function () {
    GstFixture::enable(800, 'inclusive');

    $transaction = surfaceCredit();

    // The rate moves AFTER the sale; the receipt must not.
    GstFixture::rate(1600);

    $vendor = (new VendorTransactionResource($transaction->fresh()))->toArray(request());
    $panel = (new TransactionResource($transaction->fresh()))->toArray(request());

    expect($vendor['fee_laari'])->toBe(694)
        ->and($vendor['fee_gst_laari'])->toBe(56)
        ->and($vendor['fee_gst_mvr'])->toBe('0.56')
        // Percent strings on the wire; basis points stay internal.
        ->and($vendor['fee_gst_percent'])->toBe('8.00')
        ->and($vendor['fee_treatment'])->toBe('inclusive')
        ->and($panel['fee_laari'])->toBe(694)
        ->and($panel['fee_gst_laari'])->toBe(56)
        ->and($panel['fee_gst_percent'])->toBe('8.00')
        ->and($panel['fee_treatment'])->toBe('inclusive');
});

it('reads "no tax applied" on a row priced before the switch existed', function () {
    $transaction = surfaceCredit();

    $vendor = (new VendorTransactionResource($transaction))->toArray(request());

    expect($vendor['fee_laari'])->toBe(750)
        ->and($vendor['fee_gst_laari'])->toBe(0)
        ->and($vendor['fee_gst_percent'])->toBe('0.00')
        ->and($vendor['fee_treatment'])->toBe('on_top');
});

it('itemises the tax per line, so a receipt that itemises adds up', function () {
    GstFixture::enable(800, 'on_top');

    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'dry', 'name_en' => 'Dry goods',
        'mode' => 'rate', 'rate_bp' => 200, 'active' => true, 'sort' => 1,
    ]);

    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-LINES',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
            'lines' => [
                ['category' => 'dry', 'amount_laari' => 40_000],
                ['category' => null, 'amount_laari' => 60_000],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.lines.0.fee_laari', 300)
        ->assertJsonPath('data.lines.0.fee_gst_laari', 24)
        ->assertJsonPath('data.lines.0.fee_gst_percent', '8.00')
        ->assertJsonPath('data.lines.1.fee_laari', 450)
        ->assertJsonPath('data.lines.1.fee_gst_laari', 36);

    $transaction = Transaction::query()->sole();
    $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('sort')->get();

    expect($transaction->fee_gst_laari)->toBe((int) $lines->sum('fee_gst_laari'))
        ->and($transaction->fee_gst_laari)->toBe(60)
        ->and((new TransactionLineResource($lines[0]))->toArray(request()))
        ->toMatchArray(['fee_laari' => 300, 'fee_gst_laari' => 24, 'fee_gst_percent' => '8.00']);
});

/**
 * THE COST QUOTE, BEFORE THE SALE EXISTS.
 *
 * Every GST figure above describes a sale that already happened. The till's
 * pre-record estimate has no such row to read — it quotes what the merchant
 * is ABOUT to owe — so it needs the LIVE policy, and the rate endpoint is
 * where a merchant-readable copy of it belongs (the tax-settings route is
 * superadmin-only, and the panel is never told about it).
 *
 * Without this, both merchant tills quote `cashback + fee` and are short by
 * the tax on every sale the moment a superadmin throws the switch — a
 * runtime flip with no deploy behind it.
 */
it('publishes the live GST terms on the merchant rate endpoint, for the pre-record estimate', function () {
    // OFF: the identity, and what every till quotes today.
    $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.tax.gst_rate_percent', '0.00')
        ->assertJsonPath('data.tax.fee_treatment', 'on_top');

    GstFixture::enable(800, 'inclusive');

    $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        // PLAN §1 wire format: a percent string, never basis points.
        ->assertJsonPath('data.tax.gst_rate_percent', '8.00')
        ->assertJsonPath('data.tax.fee_treatment', 'inclusive');

    // And on the WRITE, so a merchant who just changed their rate gets the
    // terms back with it rather than re-fetching.
    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertOk()
        ->assertJsonPath('data.tax.gst_rate_percent', '8.00')
        ->assertJsonPath('data.tax.fee_treatment', 'inclusive');
});

it('quotes a cost the recorded sale then matches, under on_top', function () {
    GstFixture::enable(800, 'on_top');

    $terms = $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->json('data');

    // What a till computes from that payload: cashback + fee + the tax on
    // the fee (2.00% and 0.75% of 100,000, then 8% of the fee).
    $cashback = intdiv(100_000 * 200 + 9999, 10000);
    $fee = intdiv(100_000 * 75 + 9999, 10000);
    $gst = intdiv($fee * 800 + 9999, 10000);

    expect($terms['tax'])->toBe(['gst_rate_percent' => '8.00', 'fee_treatment' => 'on_top'])
        ->and($terms['current']['platform_fee_percent'])->toBe('0.75');

    $recorded = $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-QUOTE',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data');

    // The quote and the receipt, laari for laari.
    expect($recorded['cashback_laari'] + $recorded['fee_laari'] + $recorded['fee_gst_laari'])
        ->toBe($cashback + $fee + $gst)
        ->and($recorded['fee_gst_laari'])->toBe($gst);
});
