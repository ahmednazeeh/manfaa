<?php

declare(strict_types=1);

use App\Domain\Platform\FeePromotionPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * THE INTRODUCTORY OFFER — every merchant's first X days, measured from
 * `merchants.approved_at` in BUSINESS days (§13).
 *
 * The fixture store is approved at 2026-08-04T06:00:00Z, which is 11:00 on
 * 4 August in Malé. A 30-day window therefore runs
 *
 *     [2026-08-03T19:00:00Z, 2026-09-02T19:00:00Z)
 *
 * — the start of the Malé day it was approved on, plus 30 days, exclusive.
 * Day 0 is 4 August, the last day inside is 2 September, and the first
 * instant of 3 September is outside. Every boundary below is one of those
 * three, plus the two instants either side of the edge itself.
 */

const APPROVED_AT = '2026-08-04T06:00:00Z';

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A sale occurring at $at, with the clock standing a second later so the
 * credit is neither future-dated nor backdated. One SECOND, not one minute:
 * the boundary assertions below turn on the exact instant a window closes,
 * and a helper that quietly moved the sale a minute earlier would test the
 * wrong side of it.
 */
function saleAt(string $at, int $eligibleLaari = 100_000): object
{
    $occurredAt = CarbonImmutable::parse($at)->utc();
    Carbon::setTestNow($occurredAt->addSecond());

    return FeePromotionFixture::credit(
        test()->merchant,
        test()->owner,
        test()->customer,
        $eligibleLaari,
        $occurredAt,
    );
}

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    Carbon::setTestNow(CarbonImmutable::parse(APPROVED_AT));

    $this->merchant = FeePromotionFixture::merchant(CarbonImmutable::parse(APPROVED_AT));
    $this->owner = FeePromotionFixture::owner($this->merchant);
    $this->customer = FeePromotionFixture::customer();
});

it('prices day 0 — the approval day itself — at the promotional fee', function (): void {
    FeePromotionFixture::intro(30, 0);

    $sale = saleAt('2026-08-04T09:00:00Z');

    // Charged nothing, and the row says what it WOULD have been charged.
    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_laari)->toBe(0)
        ->and($sale->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_promo_kind)->toBe('introductory')
        ->and($sale->fee_promo_fee_bp)->toBe(0)
        // MVR 1,000 at a 0.75% tier fee is 750 laari — exactly what the
        // promotion cost us on this sale.
        ->and($sale->fee_forgone_laari)->toBe(750)
        // The customer's reward is untouched: a fee promotion is between
        // Manfaa and the merchant and has nothing to do with the shopper.
        ->and($sale->cashback_laari)->toBe(2_000)
        ->and($sale->rate_bp)->toBe(200);
});

it('prices a sale EARLIER on the approval day too — the window starts at the start of that business day', function (): void {
    FeePromotionFixture::intro(30, 0);

    // 09:00 Malé, two hours before the store was approved at 11:00. A
    // backdated credit for that morning is inside the merchant's first day
    // by any reading a shopkeeper would give the phrase.
    $sale = saleAt('2026-08-04T04:00:00Z');

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_promo_kind)->toBe('introductory');
});

it('prices the LAST day of the window at the promotional fee', function (): void {
    FeePromotionFixture::intro(30, 0);

    // 2 September in Malé — day 29, the last day inside a 30-day window.
    $sale = saleAt('2026-09-02T10:00:00Z');

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_promo_kind)->toBe('introductory')
        ->and($sale->fee_forgone_laari)->toBe(750);
});

it('prices the FIRST day after the window at the ordinary tier fee, unstamped', function (): void {
    FeePromotionFixture::intro(30, 0);

    // 3 September in Malé — day 30, one day past the end.
    $sale = saleAt('2026-09-03T10:00:00Z');

    expect($sale->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_laari)->toBe(750)
        ->and($sale->fee_promo_kind)->toBeNull()
        ->and($sale->fee_promo_fee_bp)->toBeNull()
        ->and($sale->list_fee_bp)->toBeNull()
        ->and($sale->fee_forgone_laari)->toBe(0);
});

it('flips at the exact instant the window closes, not a minute either side', function (): void {
    FeePromotionFixture::intro(30, 0);

    // 23:59:59 on 2 September in Malé — the last instant inside.
    expect(saleAt('2026-09-02T18:59:59Z')->fee_bp)->toBe(0);

    // Midnight, 3 September in Malé — the first instant outside.
    expect(saleAt('2026-09-02T19:00:00Z')->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP);
});

it('gives NOTHING to a merchant whose first X days ran out before the promotion existed', function (): void {
    // The store has been trading for 200 days. A superadmin switches on a
    // 30-day introductory offer TODAY. There is no enrolment record and no
    // backdating: this merchant's first 30 days are long over.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-04T06:00:00Z'));

    $old = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-01-16T06:00:00Z'));
    $owner = FeePromotionFixture::owner($old);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::intro(30, 0);

    $sale = FeePromotionFixture::credit($old, $owner, $customer, 100_000, CarbonImmutable::parse('2026-08-04T05:00:00Z'));

    expect($sale->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_promo_kind)->toBeNull()
        ->and($sale->fee_forgone_laari)->toBe(0);
});

it('gives a merchant approved BEFORE the promotion existed the REMAINDER of their own window, and no more', function (): void {
    // Approved 10 days ago; a 30-day offer switched on today. They get the
    // 20 days they have left — their window is a function of their own
    // approval date and nothing else.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-14T06:00:00Z'));

    $merchant = FeePromotionFixture::merchant(CarbonImmutable::parse(APPROVED_AT));
    $owner = FeePromotionFixture::owner($merchant);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::intro(30, 0);

    $inside = FeePromotionFixture::credit($merchant, $owner, $customer, 100_000, CarbonImmutable::parse('2026-08-14T05:00:00Z'));

    expect($inside->fee_bp)->toBe(0);

    // And the same store, past the same 2 September edge.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-03T10:00:00Z'));

    $outside = FeePromotionFixture::credit($merchant, $owner, $customer, 100_000, CarbonImmutable::parse('2026-09-03T09:00:00Z'));

    expect($outside->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP);
});

it('gives nothing to a store that has never been approved', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-04T06:00:00Z'));

    // An active store with no approval stamp is a data state that exists
    // (§13b), and a store that was never approved has never had a first day.
    $merchant = FeePromotionFixture::merchant(null);
    $owner = FeePromotionFixture::owner($merchant);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::intro(30, 0);

    $sale = FeePromotionFixture::credit($merchant, $owner, $customer, 100_000, CarbonImmutable::parse('2026-08-04T05:00:00Z'));

    expect($sale->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_promo_kind)->toBeNull();
});

it('charges a REDUCED promotional fee, not only a zero one, and records what it displaced', function (): void {
    // 0.25% instead of the 0.75% this merchant's tier charges.
    FeePromotionFixture::intro(30, 25);

    $sale = saleAt('2026-08-05T09:00:00Z');

    expect($sale->fee_bp)->toBe(25)
        ->and($sale->fee_laari)->toBe(250)
        ->and($sale->list_fee_bp)->toBe(75)
        ->and($sale->fee_promo_fee_bp)->toBe(25)
        ->and($sale->fee_forgone_laari)->toBe(500);
});

it('leaves a merchant on a CHEAPER tier alone — a promotion may only ever reduce', function (): void {
    // This store trades at 0.50% cashback, which §4 prices at a 0.25% fee.
    // The promotion offers 0.50%, which is MORE. The seam takes the lower of
    // the two, so the store keeps its own tier and the row is stamped with
    // nothing — it is indistinguishable from a sale priced with no promotion
    // running, which is exactly what it is.
    Carbon::setTestNow(CarbonImmutable::parse(APPROVED_AT));

    $cheap = FeePromotionFixture::merchant(CarbonImmutable::parse(APPROVED_AT), rateBp: 50);
    $owner = FeePromotionFixture::owner($cheap);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::write([
        'intro_enabled' => true,
        'intro_days' => 30,
        'intro_fee_bp' => 50,
        'intro_banner_en' => 'x',
        'intro_banner_dv' => 'x',
    ]);

    $sale = FeePromotionFixture::credit($cheap, $owner, $customer, 100_000, CarbonImmutable::parse('2026-08-04T05:00:00Z'));

    expect($sale->fee_bp)->toBe(25)
        ->and($sale->fee_promo_kind)->toBeNull()
        ->and($sale->fee_forgone_laari)->toBe(0);
});

it('computes the window in BUSINESS time, from the start of the approval day', function (): void {
    // The arithmetic on its own, so the boundary tests above are not the
    // only thing standing between a timezone slip and production.
    [$start, $end] = FeePromotionPolicy::introWindow(CarbonImmutable::parse(APPROVED_AT), 30);

    expect($start->toIso8601String())->toBe('2026-08-03T19:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-09-02T19:00:00+00:00');
});
