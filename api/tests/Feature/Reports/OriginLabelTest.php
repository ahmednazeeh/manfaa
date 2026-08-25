<?php

declare(strict_types=1);

use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\ReportLabels;
use App\Domain\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * "Manfaa App", not "Manual" (owner, 2026-08-24).
 *
 * The `manual` origin is the merchant app / panel "Credit Customer" flow — a
 * real cashier crediting a real sale through Manfaa's own software. "Manual"
 * read as a hand-keyed correction, which is the one thing it never is, and a
 * finance sheet that says it means a finance person believes it.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));
    $this->seed(LedgerAccountSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('labels the manual origin "Manfaa App"', function () {
    expect(ReportLabels::origin('manual'))->toBe('Manfaa App');
});

it('leaves every other origin label exactly as it was', function () {
    expect(ReportLabels::origin('pos'))->toBe('POS')
        ->and(ReportLabels::origin('online_link'))->toBe('Online link')
        ->and(ReportLabels::origin('api_phone'))->toBe('API (phone)')
        ->and(ReportLabels::origin('card_linked'))->toBe('Card linked')
        ->and(ReportLabels::origin('claim'))->toBe('Claim')
        ->and(ReportLabels::origin('marketplace'))->toBe('Marketplace')
        ->and(ReportLabels::origin(null))->toBe('')
        ->and(ReportLabels::origin(''))->toBe('');
});

it('prints "Manfaa App" in the cashback report, where a person actually reads it', function () {
    // ManualCreditService credits with origin `manual` — the panel/app path.
    $fixture = ReportFixture::payable([100_000]);

    $transactions = (new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31')))
        ->sheet(CashbackReport::TRANSACTIONS);

    $originIndex = $transactions->indexOf('origin');

    expect($originIndex)->not->toBeNull()
        ->and($transactions->count())->toBe(1)
        ->and($transactions->rows()[0][$originIndex])->toBe('Manfaa App')
        ->and($fixture->transactions[0]->origin)->toBe('manual');
});
