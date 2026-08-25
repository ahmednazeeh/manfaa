<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Dashboard\DashboardPeriod;
use App\Domain\Dashboard\MoneySnapshot;
use App\Domain\Money\Laari;
use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Payout\PayoutItemSettler;
use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\EarningsReport;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportPeriod;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * THE INVARIANT THIS WHOLE FEATURE RESTS ON: the dashboard must never
 * disagree with the Reports page.
 *
 * Not "should be close to" and not "was checked once" — EQUAL, asserted
 * figure by figure against the reports' own summaries over the same window,
 * on a month with everything in it: settled batches, a prompt discount, a
 * reversal, an unsettled shop, and real payouts.
 *
 * The two sides genuinely differ in HOW they arrive. The reports build a row
 * per sale and total the sheet in PHP; the dashboard runs one aggregate per
 * scope and never materialises a row. That is the point — if the definitions
 * ever fork, these numbers stop matching and this file fails.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A busy August, and a July before it, on real paths only. */
function agreementMonth(): void
{
    // JULY — a settled shop, so the previous-period figures are not all zero.
    $july = ReportFixture::payable(
        [80_000, 40_000],
        merchantName: 'July Shop',
        base: CarbonImmutable::parse('2026-07-04T06:00:00+00:00'),
    );
    $july->payAndMatch($july->submit(), $july->dueTotal());

    // AUGUST — settled in full, earning the PLAN §1 prompt discount.
    $settled = ReportFixture::payable([100_000, 50_000], discountRateBp: 500, merchantName: 'Settled Shop');
    $batch = $settled->submit();
    $settled->payAndMatch($batch, $batch->refresh()->amount_due_laari);

    // AUGUST — a shop that has not paid, and one sale reversed on it.
    $owing = ReportFixture::payable([200_000, 30_000], merchantName: 'Owing Shop');
    app(ReversalService::class)->reverse(
        $owing->transactions[1],
        Actor::system(),
        'customer_refund',
        CarbonImmutable::now('UTC')->subHour(),
    );

    // AUGUST — money out: a customer confirmed and actually paid.
    $customer = ReportFixture::customer('Aminath Shifa');
    $transitions = app(TransitionService::class);

    foreach ([500_000, 250_000] as $eligible) {
        $transaction = app(ManualCreditService::class)->credit(
            $settled->merchant,
            $settled->user,
            $customer->customer_code,
            'INV-'.Str::upper(Str::random(10)),
            Laari::of($eligible),
            null,
            CarbonImmutable::now('UTC')->subHour(),
        );

        $transitions->makePayable($transaction, Actor::system());
        $transitions->confirm($transaction, Actor::system());
    }

    $admin = AdminUser::factory()->create();
    $payouts = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $admin);
    app(ApprovalService::class)->approve($payouts, $admin);
    app(BankFileExporter::class)->export($payouts->refresh());
    app(PayoutItemSettler::class)->settleOne($payouts->items()->sole(), 'BML-TRX-1');
}

/**
 * The six figures as the REPORTS answer them — each from the report that
 * owns the definition, built the long way (every sheet, every row).
 *
 * @return array<string, int>
 */
function reportedMoney(ReportPeriod $period): array
{
    $cashback = (new CashbackReport($period))->summary();
    $earnings = (new EarningsReport($period))->summary();
    $payouts = (new PayoutReport($period))->summary();

    return [
        'cashback_generated_laari' => $cashback['transactions']['cashback_laari'],
        'platform_fees_net_laari' => $earnings['net_fee_income_laari'],
        'gst_collected_laari' => $earnings['gst_collected_laari'],
        // The acquisition spend (owner, 2026-08-25). On the SALE clock, with
        // cashback and not with the fees: a fee we never charged posts no
        // journal, so it is counted where its sale is counted — REPORT A.
        'fee_forgone_to_promotions_laari' => $cashback['transactions']['fee_forgone_laari'],
        'collected_from_merchants_laari' => $cashback['settlements']['amount_received_laari'],
        'paid_out_to_customers_laari' => $payouts['transactions']['cashback_laari'],
    ];
}

it('states the same money the reports do, figure for figure', function (): void {
    agreementMonth();

    $august = ReportPeriod::of('2026-08-01', '2026-08-31');
    $reported = reportedMoney($august);

    // A test that passes on a row of zeroes proves nothing. Two figures are
    // legitimately zero on this fixture — GST, which is not switched on
    // anywhere yet, and the fee forgone to promotions, because no fee
    // promotion is running here — and both are asserted as zero rather than
    // skipped. FeePromotionMoneySurfacesTest asserts the forgone figure
    // agrees on a month where it is NOT zero.
    expect(array_filter($reported))->toHaveCount(4)
        ->and($reported['gst_collected_laari'])->toBe(0)
        ->and($reported['fee_forgone_to_promotions_laari'])->toBe(0);

    expect(app(MoneySnapshot::class)->forPeriod($august))->toBe($reported);
});

it('serves those same figures over HTTP, this period and the one before it', function (): void {
    agreementMonth();

    $august = ReportPeriod::of('2026-08-01', '2026-08-31');
    $july = DashboardPeriod::preceding($august);

    expect($july->fromDate())->toBe('2026-07-01')
        ->and($july->toDate())->toBe('2026-07-31');

    $money = $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/dashboard?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->json('money');

    $previous = $money['previous'];
    unset($money['previous'], $previous['period']);

    $reportedJuly = reportedMoney($july);

    expect($money)->toBe(reportedMoney($august))
        ->and($previous)->toBe($reportedJuly)
        // July settled a batch and generated cashback, so the comparison the
        // panel draws is against real movement.
        ->and($reportedJuly['cashback_generated_laari'])->toBeGreaterThan(0)
        ->and($reportedJuly['collected_from_merchants_laari'])->toBeGreaterThan(0);
});

it('agrees with the reports on a REVERSED sale, which both leave out', function (): void {
    $fixture = ReportFixture::payable([100_000, 50_000]);

    $before = app(MoneySnapshot::class)->forPeriod(ReportPeriod::of('2026-08-01', '2026-08-31'));

    app(ReversalService::class)->reverse(
        $fixture->transactions[1],
        Actor::system(),
        'customer_refund',
        CarbonImmutable::now('UTC')->subHour(),
    );

    $august = ReportPeriod::of('2026-08-01', '2026-08-31');
    $after = app(MoneySnapshot::class)->forPeriod($august);

    // The reversed sale's cashback leaves both surfaces together.
    expect($after['cashback_generated_laari'])->toBeLessThan($before['cashback_generated_laari'])
        ->and($after)->toBe(reportedMoney($august));
});

it('agrees on an EMPTY period, where every figure is zero', function (): void {
    agreementMonth();

    $quiet = ReportPeriod::of('2026-06-01', '2026-06-30');

    expect(app(MoneySnapshot::class)->forPeriod($quiet))
        ->toBe(reportedMoney($quiet))
        ->toBe([
            'cashback_generated_laari' => 0,
            'platform_fees_net_laari' => 0,
            'gst_collected_laari' => 0,
            'fee_forgone_to_promotions_laari' => 0,
            'collected_from_merchants_laari' => 0,
            'paid_out_to_customers_laari' => 0,
        ]);
});
