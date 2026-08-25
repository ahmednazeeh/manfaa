<?php

declare(strict_types=1);

use App\Domain\Dashboard\DailySeries;
use App\Domain\Dashboard\MoneySnapshot;
use App\Domain\Reports\ReportPeriod;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The chart: one row per day of the window, every day of it, in BUSINESS
 * time — and adding up to the money panel above it.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('covers every day in the range, including the empty ones', function (): void {
    ReportFixture::payable([100_000]);

    $series = app(DailySeries::class)->forPeriod(ReportPeriod::of('2026-08-01', '2026-08-07'));

    expect($series)->toHaveCount(7)
        ->and(array_column($series, 'date'))->toBe([
            '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04',
            '2026-08-05', '2026-08-06', '2026-08-07',
        ]);

    // A quiet day is a ZERO, not a gap: a chart drawn from sparse rows draws
    // a straight line across a dead week and calls it trade.
    expect($series[0])->toBe([
        'date' => '2026-08-01',
        'cashback_laari' => 0,
        'fee_accrued_laari' => 0,
        'collected_laari' => 0,
        'paid_out_laari' => 0,
    ]);

    // 100,000 at 200bp / 75bp — the §4 line, on the fixture's own day.
    expect($series[3])->toBe([
        'date' => '2026-08-04',
        'cashback_laari' => 2_000,
        'fee_accrued_laari' => 750,
        'collected_laari' => 0,
        'paid_out_laari' => 0,
    ]);
});

it('buckets a sale on its MALDIVIAN day, not its UTC one', function (): void {
    // occurred_at 20:30 UTC on the 5th is 01:30 on the 6th in Malé. The bar
    // belongs to the 6th — the same day the Reports page files it under.
    ReportFixture::payable([100_000], base: CarbonImmutable::parse('2026-08-05T21:30:00+00:00'));

    $series = collect(app(DailySeries::class)->forPeriod(ReportPeriod::of('2026-08-01', '2026-08-10')))
        ->keyBy('date');

    expect($series['2026-08-05']['cashback_laari'])->toBe(0)
        ->and($series['2026-08-06']['cashback_laari'])->toBe(2_000);
});

it('adds up to the money panel it sits under', function (): void {
    $fixture = ReportFixture::payable([100_000, 50_000, 30_000]);
    $fixture->payAndMatch($fixture->submit(), $fixture->dueTotal());

    $august = ReportPeriod::of('2026-08-01', '2026-08-31');
    $series = app(DailySeries::class)->forPeriod($august);
    $money = app(MoneySnapshot::class)->forPeriod($august);

    $sum = fn (string $key): int => array_sum(array_column($series, $key));

    expect($sum('cashback_laari'))->toBe($money['cashback_generated_laari'])
        ->and($sum('collected_laari'))->toBe($money['collected_from_merchants_laari'])
        ->and($sum('paid_out_laari'))->toBe($money['paid_out_to_customers_laari'])
        // Not a zero-sum tie: there is real money in these bars.
        ->and($sum('cashback_laari'))->toBeGreaterThan(0)
        ->and($sum('collected_laari'))->toBeGreaterThan(0);

    // fee_accrued is the fee ON THOSE SALES, and says so in its name — the
    // money panel's platform_fees_net_laari is the LEDGER's recognition
    // after discounts, which is a different question with a different clock.
    expect($sum('fee_accrued_laari'))->toBe(1_350);
});

it('rides on the HTTP payload in the same order', function (): void {
    ReportFixture::payable([100_000]);

    $series = $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/dashboard?from=2026-08-03&to=2026-08-05')
        ->assertOk()
        ->json('series');

    expect($series)->toHaveCount(3)
        ->and(array_column($series, 'date'))->toBe(['2026-08-03', '2026-08-04', '2026-08-05'])
        ->and($series[1]['cashback_laari'])->toBe(2_000);
});
