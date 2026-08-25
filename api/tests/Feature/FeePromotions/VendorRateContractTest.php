<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * THE THIRD-PARTY POS DOOR — GET /api/v1/merchants/me/rate.
 *
 * The two first-party surfaces (the web panel and the till app) learn about a
 * fee promotion from GET /merchant/fee-promotion, which carries the
 * superadmin's banner copy. A VENDOR till has no banner and no such
 * endpoint, and every `platform_fee_percent` on this response resolves from
 * the §4 tier schedule — which, since fee promotions, is a LIST price rather
 * than always the billed one (TermsResolver charges min(promotion, tier)).
 *
 * So a vendor that never learns the promotion exists prints a fee the
 * platform will not charge. `active_fee_promotion` is how it learns.
 */
beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

    $this->merchant = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-09-04T06:00:00Z'));
    $this->token = $this->merchant->createToken('till', ['rates:read'])->plainTextToken;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function vendorRate(): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->getJson('/api/v1/merchants/me/rate');
}

it('omits active_fee_promotion entirely when none is in force — the base contract stays byte-stable', function (): void {
    vendorRate()
        ->assertOk()
        ->assertExactJson([
            'cashback_rate_percent' => '2.00',
            'platform_fee_percent' => '0.75',
            'currency' => 'MVR',
            'min_eligible_laari' => 5000,
            'has_category_overrides' => false,
            'pending_decrease' => null,
        ]);
});

it('tells a vendor till the fee the platform is ACTUALLY charging, not just the tier price', function (): void {
    FeePromotionFixture::platformWide(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDays(30), 0);

    vendorRate()
        ->assertOk()
        // The tier price is untouched — it is still what this store pays the
        // moment the campaign stops, and a vendor that predates promotions
        // reads exactly what it always read.
        ->assertJsonPath('platform_fee_percent', '0.75')
        ->assertJsonPath('active_fee_promotion.kind', 'platform_wide')
        ->assertJsonPath('active_fee_promotion.kind_label', 'Platform-wide offer')
        // The CEILING the promotion puts on every fee above...
        ->assertJsonPath('active_fee_promotion.platform_fee_percent', '0.00')
        // ...and the same ceiling already applied to the headline rate, so a
        // till that only quotes that rate needs no arithmetic of its own.
        ->assertJsonPath('active_fee_promotion.effective_platform_fee_percent', '0.00')
        ->assertJsonPath('active_fee_promotion.banner_en', 'Launch offer: reduced platform fee for every store.');
});

it('never quotes an effective fee ABOVE the tier a merchant already sits on', function (): void {
    // The min() the seam prices by, said on the wire: an offer of 0.75%
    // against this store's own 0.75% tier changes nothing, and the effective
    // figure has to say so rather than advertising a discount that is not one.
    FeePromotionFixture::platformWide(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDays(30), FeePromotionFixture::TIER_FEE_BP);

    vendorRate()
        ->assertOk()
        ->assertJsonPath('active_fee_promotion.platform_fee_percent', '0.75')
        ->assertJsonPath('active_fee_promotion.effective_platform_fee_percent', '0.75');
});

it('carries the introductory window as an EXCLUSIVE instant, with the days left beside it', function (): void {
    // Approved 2026-09-04 (Malé), 30 days: the window opens at the start of
    // that Malé day and closes at the start of the Malé day 30 days later.
    FeePromotionFixture::intro(30, 0);

    vendorRate()
        ->assertOk()
        ->assertJsonPath('active_fee_promotion.kind', 'introductory')
        ->assertJsonPath('active_fee_promotion.effective_platform_fee_percent', '0.00')
        ->assertJsonPath(
            'active_fee_promotion.ends_at',
            CarbonImmutable::parse('2026-10-04T00:00:00+05:00')->toIso8601String(),
        )
        ->assertJsonPath('active_fee_promotion.days_remaining', 29);
});
