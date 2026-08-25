<?php

declare(strict_types=1);

use App\Domain\Dashboard\MoneySnapshot;
use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\ReportPeriod;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * WHAT THE ACQUISITION COST, on the money surfaces — and the standing rule
 * that governs them: the dashboard must never disagree with the Reports page.
 *
 * FORGONE FEE is derived at PRICING time and stored on the row: the sale is
 * costed twice inside TermsResolver, once at the fee it was charged and once
 * at the §4 tier fee it would otherwise have paid, and the difference (after
 * the same GST split, so both are net figures) is frozen as
 * `transactions.fee_forgone_laari`. Both surfaces then SUM that one column
 * over the same scope, so they cannot drift: the report walks a row per sale
 * and totals in PHP, the dashboard runs one aggregate in Postgres, and this
 * file asserts the two answers are the same integer on a month where the
 * figure is emphatically not zero.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->base = CarbonImmutable::parse('2026-09-05T06:00:00Z');
    Carbon::setTestNow($this->base);

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A September with three sales: two free under a platform-wide promotion and
 * one at the ordinary tier fee, after the promotion ends.
 *
 * Tier fee 0.75%: MVR 1,000 → 750 laari, MVR 400 → 300 laari.
 * Forgone = 750 + 300 = 1,050. The third sale gives up nothing.
 */
function promotedSeptember(): void
{
    $base = CarbonImmutable::parse('2026-09-05T06:00:00Z');

    FeePromotionFixture::platformWide($base->subDays(4), $base->addDays(2), 0);

    $merchant = FeePromotionFixture::merchant($base->subYear());
    $owner = FeePromotionFixture::owner($merchant);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::credit($merchant, $owner, $customer, 100_000, $base->subHour());
    FeePromotionFixture::credit($merchant, $owner, $customer, 40_000, $base->subMinutes(30));

    // The promotion ends; a later sale in the same month pays in full.
    Carbon::setTestNow($base->addDays(5));
    FeePromotionFixture::endAll();

    FeePromotionFixture::credit($merchant, $owner, $customer, 100_000, CarbonImmutable::now('UTC')->subHour());

    Carbon::setTestNow($base->addDays(6));
}

it('reports the forgone fee on the cashback report, per sale and in the totals', function (): void {
    promotedSeptember();

    $report = new CashbackReport(ReportPeriod::of('2026-09-01', '2026-09-30'));
    $sheet = $report->sheet(CashbackReport::TRANSACTIONS);
    $forgone = $sheet->indexOf('fee_forgone_laari');

    expect($sheet->count())->toBe(3)
        ->and($sheet->sum('fee_forgone_laari'))->toBe(1_050)
        // Per sale, in occurred_at order: 750, 300, then nothing.
        ->and((int) $sheet->rows()[0][$forgone])->toBe(750)
        ->and((int) $sheet->rows()[1][$forgone])->toBe(300)
        ->and((int) $sheet->rows()[2][$forgone])->toBe(0);

    // GROSS DUE EXCLUDES IT. What the merchant never had to pay is not part
    // of what the merchant owes — adding it back would bill them for the
    // promotion.
    $due = $sheet->indexOf('gross_due_laari');

    expect((int) $sheet->rows()[0][$due])->toBe(2_000)
        ->and((int) $sheet->rows()[2][$due])->toBe(2_750);
});

it('agrees, figure for figure, between the report totals, its own summary and the dashboard', function (): void {
    promotedSeptember();

    $september = ReportPeriod::of('2026-09-01', '2026-09-30');
    $report = new CashbackReport($september);

    $sheetTotal = $report->sheet(CashbackReport::TRANSACTIONS)->sum('fee_forgone_laari');
    $summary = $report->summary();
    $totals = (new CashbackReport($september))->moneyTotals();
    $dashboard = app(MoneySnapshot::class)->forPeriod($september);

    // The four routes to the same number: the sheet built row by row, the
    // JSON summary the Reports page renders, the single aggregate the
    // dashboard runs, and the dashboard's own payload.
    expect($sheetTotal)->toBe(1_050)
        ->and($summary['transactions']['fee_forgone_laari'])->toBe(1_050)
        ->and($totals['transactions']['fee_forgone_laari'])->toBe(1_050)
        ->and($dashboard['fee_forgone_to_promotions_laari'])->toBe(1_050);
});

it('carries the same figure on the Summary sheet a reader opens the workbook to', function (): void {
    promotedSeptember();

    $report = new CashbackReport(ReportPeriod::of('2026-09-01', '2026-09-30'));
    $summary = $report->sheet(CashbackReport::SUMMARY);
    $row = collect($summary->rows())->firstWhere(0, 'Transactions — all states');

    expect((int) $row[$summary->indexOf('fee_forgone_laari')])->toBe(1_050);
});

it('serves it on the admin dashboard endpoint, under the superadmin money gate', function (): void {
    promotedSeptember();

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/dashboard?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertJsonPath('money.fee_forgone_to_promotions_laari', 1_050);

    // A plain admin gets no money panel at all, so no acquisition spend
    // either — the same gate, not a second one.
    $this->actingAs(AdminUser::factory()->create(['role' => 'admin']), 'admin')
        ->getJson('/api/admin/dashboard?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertJsonMissingPath('money');
});

it('keeps the figure at zero on a month no promotion ran', function (): void {
    promotedSeptember();

    $october = ReportPeriod::of('2026-10-01', '2026-10-31');

    expect((new CashbackReport($october))->moneyTotals()['transactions']['fee_forgone_laari'])->toBe(0)
        ->and(app(MoneySnapshot::class)->forPeriod($october)['fee_forgone_to_promotions_laari'])->toBe(0);
});

it('names WHICH offer paid for each cheap sale, and what it displaced', function (): void {
    // The money column alone cannot answer "why was this sale cheaper?": both
    // kinds share one MUTABLE settings row, so once the campaign above was
    // ended the kind survives nowhere but the frozen stamp on the row.
    promotedSeptember();

    $sheet = (new CashbackReport(ReportPeriod::of('2026-09-01', '2026-09-30')))
        ->sheet(CashbackReport::TRANSACTIONS);

    $kind = $sheet->indexOf('fee_promo_kind');
    $before = $sheet->indexOf('list_fee_bp');

    expect($sheet->rows()[0][$kind])->toBe('Platform-wide offer')
        ->and($sheet->rows()[1][$kind])->toBe('Platform-wide offer')
        // The sale rung up after the campaign ended names no offer at all.
        ->and($sheet->rows()[2][$kind])->toBe('')
        // The §4 tier fee each promoted sale displaced — the "before" price,
        // 0.75% for this store's 2.00% standing rate.
        ->and($sheet->rows()[0][$before])->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sheet->rows()[1][$before])->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sheet->rows()[2][$before])->toBeNull();
});
