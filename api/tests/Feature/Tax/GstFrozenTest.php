<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\AmendmentService;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Tax\FeeTreatment;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * FROZEN AT CREATION, NEVER RETROACTIVE — the invariant that matters most.
 *
 * A merchant holds a receipt for what they were quoted. Enabling GST,
 * changing the rate and switching the treatment therefore price NEW
 * transactions only: no existing row, and no settlement built from existing
 * rows, may move by a single laari.
 *
 * The reports round already proved the shape of this rule — eight live
 * settlements correctly report the 5% prompt discount they were granted
 * while new ones report 10%, because the rate that priced them is STAMPED
 * on the batch. GST follows the same law, in the same columns:
 * `transactions.fee_gst_bp` and `transactions.fee_treatment`.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->seed(LedgerAccountSeeder::class);

    // The PLAN §1 prompt-payment discount is pinned OFF here: this file is
    // about what a rate change may NOT touch, and a discount quietly
    // shaving the fee (and its GST relief) would make every assertion
    // measure two rules at once. GstPromptDiscountTest turns it back on.
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $this->admin = AdminUser::factory()->create();
    $this->merchant = Merchant::factory()->create([
        'name' => 'Frozen Terms Store',
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

function frozenCredit(string $invoice, int $eligibleLaari = 100_000): Transaction
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

it('leaves a historical row and its settlement byte-identical through a rate change and a treatment switch', function () {
    GstFixture::enable(800, 'on_top');

    $old = frozenCredit('INV-OLD');
    app(TransitionService::class)->makePayable($old, Actor::system());

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($this->merchant))->refresh();

    // 2,000 cashback + 750 fee + 60 GST.
    expect($settlement->fee_total_laari)->toBe(750)
        ->and($settlement->fee_gst_total_laari)->toBe(60)
        ->and($settlement->amount_due_laari)->toBe(2_810);

    app(SettlementAllocator::class)->matchPayment(
        app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of(2_810), 'BML-FROZEN'),
        $this->admin,
    );

    // Every stored byte of both rows, before anything moves.
    $rowBefore = $old->fresh()->getAttributes();
    $settlementBefore = $settlement->fresh()->getAttributes();

    // The platform doubles the rate and flips the treatment.
    GstFixture::rate(1600);
    GstFixture::treatment('inclusive');

    expect($old->fresh()->getAttributes())->toBe($rowBefore)
        ->and($settlement->fresh()->getAttributes())->toBe($settlementBefore)
        // Spelled out, because the whole feature turns on it.
        ->and($old->fresh()->fee_laari)->toBe(750)
        ->and($old->fresh()->fee_gst_laari)->toBe(60)
        ->and($old->fresh()->fee_gst_bp)->toBe(800)
        ->and($old->fresh()->fee_treatment)->toBe(FeeTreatment::OnTop);
});

it('prices the NEXT sale at the new terms, and only the next one', function () {
    GstFixture::enable(800, 'on_top');
    $old = frozenCredit('INV-BEFORE');

    GstFixture::rate(1600);
    GstFixture::treatment('inclusive');
    $new = frozenCredit('INV-AFTER');

    // 750 of fee at 1600bp inclusive: gst = ceil(750·1600/11600) = 104,
    // net = 646. The old row is untouched at 750 + 60 on top.
    expect(intdiv(750 * 1600 + 11_599, 11_600))->toBe(104)
        ->and($new->fee_laari)->toBe(646)
        ->and($new->fee_gst_laari)->toBe(104)
        ->and($new->fee_gst_bp)->toBe(1600)
        ->and($new->fee_treatment)->toBe(FeeTreatment::Inclusive)
        ->and($old->fresh()->fee_laari)->toBe(750)
        ->and($old->fresh()->fee_gst_laari)->toBe(60)
        ->and($old->fresh()->fee_gst_bp)->toBe(800);
});

it('disabling GST leaves the taxed rows taxed — a receipt is not withdrawn', function () {
    GstFixture::enable(800, 'on_top');
    $taxed = frozenCredit('INV-TAXED');

    GstFixture::disable();
    $untaxed = frozenCredit('INV-UNTAXED');

    expect($taxed->fresh()->fee_gst_laari)->toBe(60)
        ->and($taxed->fresh()->fee_gst_bp)->toBe(800)
        ->and($untaxed->fee_gst_laari)->toBe(0)
        ->and($untaxed->fee_gst_bp)->toBe(0);
});

it('reports read the STAMPED rate, not the platform setting', function () {
    GstFixture::enable(800, 'on_top');
    frozenCredit('INV-REPORTED');

    // The platform doubles the rate AFTER the sale.
    GstFixture::rate(1600);

    $transactions = (new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31')))
        ->sheet(CashbackReport::TRANSACTIONS);

    $gstIndex = $transactions->indexOf('gst_laari');
    $feeIndex = $transactions->indexOf('fee_laari');
    $grossIndex = $transactions->indexOf('gross_due_laari');

    // 60, the tax this sale was actually charged — not 120, what it would
    // be charged today.
    expect($transactions->count())->toBe(1)
        ->and($transactions->rows()[0][$gstIndex])->toBe(60)
        ->and($transactions->rows()[0][$feeIndex])->toBe(750)
        ->and($transactions->rows()[0][$grossIndex])->toBe(2_810)
        ->and($transactions->sum('gst_laari'))->toBe(60);
});

it('amends a sale under the terms it was rung up on, never today\'s', function () {
    GstFixture::enable(800, 'on_top');

    $transaction = frozenCredit('INV-AMEND');

    // The platform switches to inclusive at 16% before the cashier fixes
    // the amount. The correction must price under the row's OWN stamp.
    GstFixture::rate(1600);
    GstFixture::treatment('inclusive');

    $amended = app(AmendmentService::class)->amend(
        $transaction,
        Actor::merchantUser($this->user->id),
        Laari::of(200_000),
        null,
        null,
    );

    // 200,000 at the frozen 75bp = 1,500 fee; ON TOP at the frozen 800bp
    // = 120 GST. Under today's terms it would have been 1,500 inclusive at
    // 1600bp — 1,293 + 207 — which is what a re-read of the setting would
    // have produced.
    expect($amended->cashback_laari)->toBe(4_000)
        ->and($amended->fee_laari)->toBe(1_500)
        ->and($amended->fee_gst_laari)->toBe(120)
        ->and($amended->fee_gst_bp)->toBe(800)
        ->and($amended->fee_treatment)->toBe(FeeTreatment::OnTop);
});
