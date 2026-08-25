<?php

declare(strict_types=1);

use App\Domain\Cashback\LineInput;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * BOTH FEE FIGURES SIT ON THE SAME SIDE OF GST.
 *
 * `fee_laari` is Manfaa's NET fee revenue, so the forgone figure has to be
 * the difference between two NET fees — what we would have KEPT minus what we
 * DID keep. Splitting only the charged fee and comparing it against a gross
 * list fee would overstate the acquisition spend by exactly the tax on the
 * discount, and would put a number on the dashboard that reconciles against
 * nothing.
 *
 * Under `on_top` the two sides are the same number and nothing can go wrong.
 * Under `inclusive` they are not, which is why this file exists.
 *
 * THE ARITHMETIC, by hand (MVR 1,000 eligible, 2.00% cashback → 0.75% tier
 * fee, a 0.25% promotion, GST 8% INCLUSIVE):
 *
 *   charged gross  ceil(100000 * 25 / 10000)            = 250
 *   charged GST    ceil(250 * 800 / 10800)              =  19
 *   charged NET    250 − 19                             = 231
 *
 *   list gross     ceil(100000 * 75 / 10000)            = 750
 *   list GST       ceil(750 * 800 / 10800)              =  56
 *   list NET       750 − 56                             = 694
 *
 *   forgone        694 − 231                            = 463
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->base = CarbonImmutable::parse('2026-09-05T06:00:00Z');
    Carbon::setTestNow($this->base);

    $this->merchant = FeePromotionFixture::merchant($this->base->subYear());
    $this->owner = FeePromotionFixture::owner($this->merchant);
    $this->customer = FeePromotionFixture::customer();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('measures the forgone fee net of an INCLUSIVE GST, on both sides', function (): void {
    GstFixture::enable(treatment: 'inclusive');
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(30), 25);

    $sale = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    expect($sale->fee_bp)->toBe(25)
        ->and($sale->fee_laari)->toBe(231)
        ->and($sale->fee_gst_laari)->toBe(19)
        ->and($sale->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        // 694 − 231, not 750 − 231: a difference between two NET fees.
        ->and($sale->fee_forgone_laari)->toBe(463);
});

it('measures it gross-equals-net under an ON TOP GST, where the two sides coincide', function (): void {
    GstFixture::enable();
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(30), 25);

    $sale = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    // On top leaves the fee alone and adds the tax, so both the charged fee
    // and the list fee are already net: 750 − 250.
    expect($sale->fee_laari)->toBe(250)
        ->and($sale->fee_gst_laari)->toBe(20)
        ->and($sale->fee_forgone_laari)->toBe(500);
});

it('keeps a lined credit header equal to the sum of its lines under inclusive GST', function (): void {
    GstFixture::enable(treatment: 'inclusive');
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(30), 25);

    $lined = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        $this->base->subHour(),
        lines: [new LineInput(null, 100_000)],
    );

    $line = $lined->lines()->sole();

    // §4: round at the line, then sum — the header can never disagree with
    // its own lines by a laari of rounding, forgone fee included.
    expect((int) $line->fee_forgone_laari)->toBe($lined->fee_forgone_laari)
        ->and($lined->fee_forgone_laari)->toBe(463)
        ->and((int) $line->fee_laari)->toBe($lined->fee_laari)
        ->and((int) $line->fee_gst_laari)->toBe($lined->fee_gst_laari);
});
