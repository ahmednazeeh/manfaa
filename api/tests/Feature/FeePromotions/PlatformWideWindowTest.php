<?php

declare(strict_types=1);

use App\Domain\Cashback\LineInput;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * THE PLATFORM-WIDE OFFER — a superadmin-set [from, to) window that covers
 * every merchant, whatever their age, and the rule for when both kinds apply
 * at once: THE MERCHANT WINS, which means the LOWER fee prices the sale.
 *
 * The store here was approved 200 days before the window, so its own
 * introductory days are long gone: nothing but the platform-wide offer can
 * be pricing anything, except where a test switches the introductory one on
 * for a NEW store on purpose.
 */

const WIDE_FROM = '2026-09-01T00:00:00Z';

const WIDE_TO = '2026-09-15T00:00:00Z';

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

    $this->merchant = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-02-01T06:00:00Z'));
    $this->owner = FeePromotionFixture::owner($this->merchant);
    $this->customer = FeePromotionFixture::customer();
});

/** A sale occurring at $at, priced by the real credit path. */
function wideSale(string $at, int $eligibleLaari = 100_000): object
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

it('prices the first instant of the window at the promotional fee', function (): void {
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 0);

    $sale = wideSale(WIDE_FROM);

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_laari)->toBe(0)
        ->and($sale->fee_promo_kind)->toBe('platform_wide')
        ->and($sale->fee_promo_fee_bp)->toBe(0)
        ->and($sale->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_forgone_laari)->toBe(750);
});

it('prices the last instant inside the window, and the first instant outside it', function (): void {
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 0);

    // Half-open, like every other window in this codebase: `to` names the
    // first instant the offer is OVER.
    expect(wideSale('2026-09-14T23:59:59Z')->fee_bp)->toBe(0)
        ->and(wideSale(WIDE_TO)->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP);
});

it('prices a sale BEFORE the window opens at the ordinary tier fee', function (): void {
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 0);

    $sale = wideSale('2026-08-31T23:59:59Z');

    expect($sale->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_promo_kind)->toBeNull()
        ->and($sale->fee_forgone_laari)->toBe(0);
});

it('covers a merchant far past their own introductory days — that is the whole point of it', function (): void {
    // Approved in February; both promotions on. The introductory window
    // closed months ago, so the platform-wide one is what prices this.
    FeePromotionFixture::intro(30, 0);
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 25);

    $sale = wideSale('2026-09-05T05:00:00Z');

    expect($sale->fee_bp)->toBe(25)
        ->and($sale->fee_promo_kind)->toBe('platform_wide');
});

it('gives the MERCHANT the lower fee when both kinds apply', function (): void {
    // A brand-new store, inside its own introductory window, while a
    // platform-wide offer also runs. The introductory fee is 0.00% and the
    // platform-wide one 0.25%, so the introductory offer wins — and the
    // stamp names it, so an invoice can explain the cheaper price.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

    $new = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
    $owner = FeePromotionFixture::owner($new);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::intro(30, 0);
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 25);

    $sale = FeePromotionFixture::credit($new, $owner, $customer, 100_000, CarbonImmutable::parse('2026-09-05T05:00:00Z'));

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_promo_kind)->toBe('introductory')
        ->and($sale->fee_forgone_laari)->toBe(750);
});

it('gives the MERCHANT the lower fee the other way round too', function (): void {
    // Same two promotions, opposite prices: the platform-wide offer is now
    // the cheaper one, and it takes the sale even from a store inside its
    // own introductory window.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

    $new = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
    $owner = FeePromotionFixture::owner($new);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::intro(30, 25);
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 0);

    $sale = FeePromotionFixture::credit($new, $owner, $customer, 100_000, CarbonImmutable::parse('2026-09-05T05:00:00Z'));

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_promo_kind)->toBe('platform_wide')
        ->and($sale->fee_forgone_laari)->toBe(750);
});

it('reports the merchant-specific kind on an exact tie, because the money is identical either way', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

    $new = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
    $owner = FeePromotionFixture::owner($new);
    $customer = FeePromotionFixture::customer();

    FeePromotionFixture::intro(30, 25);
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 25);

    $sale = FeePromotionFixture::credit($new, $owner, $customer, 100_000, CarbonImmutable::parse('2026-09-05T05:00:00Z'));

    expect($sale->fee_bp)->toBe(25)
        // The fee is the same under both, so the choice is purely about what
        // the merchant is told — and "your first 30 days" is the more useful
        // sentence than "everyone this fortnight".
        ->and($sale->fee_promo_kind)->toBe('introductory');
});

it('prices a LINED sale exactly as it prices a plain one', function (): void {
    // The seam is applied in both resolve() and resolveLines(), so a split
    // basket and a single-amount sale of the same size cost the same.
    FeePromotionFixture::platformWide(CarbonImmutable::parse(WIDE_FROM), CarbonImmutable::parse(WIDE_TO), 0);

    $plain = wideSale('2026-09-05T05:00:00Z', 100_000);

    $occurredAt = CarbonImmutable::parse('2026-09-05T05:10:00Z');
    Carbon::setTestNow($occurredAt->addSecond());

    $lined = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        $occurredAt,
        lines: [new LineInput(null, 60_000), new LineInput(null, 40_000)],
    );

    expect($lined->fee_bp)->toBe($plain->fee_bp)
        ->and($lined->fee_laari)->toBe($plain->fee_laari)
        ->and($lined->cashback_laari)->toBe($plain->cashback_laari)
        ->and($lined->fee_promo_kind)->toBe('platform_wide')
        ->and($lined->fee_forgone_laari)->toBe($plain->fee_forgone_laari);

    // And the header's forgone figure is the SUM of the stored line
    // integers, never a second computation over the aggregate.
    $lines = $lined->lines()->orderBy('sort')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->sum('fee_forgone_laari'))->toBe($lined->fee_forgone_laari)
        ->and((int) $lines[0]->fee_forgone_laari)->toBe(450)
        ->and((int) $lines[1]->fee_forgone_laari)->toBe(300)
        ->and((int) $lines[0]->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and((int) $lines[0]->fee_bp)->toBe(0);
});
