<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * THE BANNERS — two endpoints, two audiences, and the line between them is
 * the point of having two.
 *
 *   GET /api/merchant/fee-promotion   authenticated. THIS store's offer, with
 *                                     THIS store's window end and days left.
 *                                     Mounted on the panel and on the till
 *                                     app, one controller.
 *   GET /api/public/fee-promotion     unauthenticated, for the merchant
 *                                     landing page. The OFFER only: no
 *                                     merchant, no merchant's dates, nothing
 *                                     a stranger could learn about a
 *                                     particular store.
 */
beforeEach(function (): void {
    $this->base = CarbonImmutable::parse('2026-09-05T06:00:00Z');
    Carbon::setTestNow($this->base);

    // Approved 3 days ago (2 September in Malé), so a 30-day introductory
    // window closes at the start of 2 October — 27 days left today.
    $this->merchant = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
    $this->owner = FeePromotionFixture::owner($this->merchant);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('answers a merchant with their own window end and days remaining', function (): void {
    FeePromotionFixture::intro(30, 0);

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.kind', 'introductory')
        ->assertJsonPath('data.kind_label', 'Introductory offer')
        // PLAN §1: a percent string, never basis points.
        ->assertJsonPath('data.platform_fee_percent', '0.00')
        // Start of 2 September in Malé (2026-09-01T19:00Z) plus 30 days —
        // so the offer's last usable day is 1 October, and 5 September has
        // 27 of them left.
        ->assertJsonPath('data.ends_at', '2026-10-01T19:00:00+00:00')
        ->assertJsonPath('data.days_remaining', 27)
        ->assertJsonPath('data.banner_en', 'No platform fee for your first 30 days.')
        ->assertJsonPath('data.banner_dv', 'ފުރަތަމަ 30 ދުވަހު ޕްލެޓްފޯމް ފީއެއް ނުނަގާނެ.');
});

it('answers a merchant whose window has closed with an inactive banner in the same shape', function (): void {
    FeePromotionFixture::intro(30, 0);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-03T06:00:00Z'));

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', false)
        // Every key is present as null: a client must never have to guess
        // which fields it is allowed to expect.
        ->assertJsonPath('data.kind', null)
        ->assertJsonPath('data.platform_fee_percent', null)
        ->assertJsonPath('data.ends_at', null)
        ->assertJsonPath('data.days_remaining', null)
        ->assertJsonPath('data.banner_en', null)
        ->assertJsonPath('data.banner_dv', null);
});

it('names the promotion the merchant actually gets when both kinds run', function (): void {
    FeePromotionFixture::intro(30, 25);
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(10), 0);

    // The platform-wide offer is cheaper, so it is what prices this store's
    // sales — and therefore what the banner says, with the PLATFORM's end
    // date rather than the merchant's own.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.kind', 'platform_wide')
        ->assertJsonPath('data.platform_fee_percent', '0.00')
        ->assertJsonPath('data.ends_at', $this->base->addDays(10)->toIso8601String());
});

it('serves the same banner to the till app', function (): void {
    FeePromotionFixture::intro(30, 0);

    // The mobile door mounts the same controller; without a token it is shut.
    $this->getJson('/api/mobile/v1/merchant/fee-promotion')->assertUnauthorized();

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.kind', 'introductory');
});

it('shuts the merchant banner to an unauthenticated caller', function (): void {
    FeePromotionFixture::intro(30, 0);

    $this->getJson('/api/merchant/fee-promotion')->assertUnauthorized();
});

it('publishes the OFFER on the public landing endpoint, with no dates belonging to any merchant', function (): void {
    FeePromotionFixture::intro(30, 0);

    $response = $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.offers.0.kind', 'introductory')
        ->assertJsonPath('data.offers.0.platform_fee_percent', '0.00')
        // How long the offer RUNS FOR, which is a property of the offer...
        ->assertJsonPath('data.offers.0.intro_days', 30)
        // ...and no date at all, because a visitor has no approval stamp and
        // no window to be inside.
        ->assertJsonPath('data.offers.0.ends_at', null)
        ->assertJsonPath('data.offers.0.banner_en', 'No platform fee for your first 30 days.')
        ->assertJsonPath('data.offers.0.banner_dv', 'ފުރަތަމަ 30 ދުވަހު ޕްލެޓްފޯމް ފީއެއް ނުނަގާނެ.');

    // NOTHING MERCHANT-SPECIFIC LEAKS. Not this store's name, not its id, not
    // its approval date, not the window computed from it, and not a count of
    // who is enrolled.
    $body = $response->getContent();

    expect($body)->not->toContain($this->merchant->name)
        ->and($body)->not->toContain($this->merchant->slug)
        ->and($body)->not->toContain('2026-10-01')
        ->and($body)->not->toContain('days_remaining')
        ->and($body)->not->toContain('merchant')
        ->and($body)->not->toContain('approved')
        // And no basis points, in either direction.
        ->and($body)->not->toContain('_bp');
});

it('publishes a platform-wide window END, which is the platform own campaign deadline', function (): void {
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(10), 0);

    $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.offers.0.kind', 'platform_wide')
        ->assertJsonPath('data.offers.0.ends_at', $this->base->addDays(10)->toIso8601String())
        // Not an introductory offer, so no day count.
        ->assertJsonPath('data.offers.0.intro_days', null);
});

it('lists both offers when both are on, and none when neither is', function (): void {
    $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.offers', []);

    FeePromotionFixture::intro(30, 0);
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(10), 25);

    $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', true)
        ->assertJsonCount(2, 'data.offers');
});

it('hides a platform-wide offer whose window has not opened, and one whose window has closed', function (): void {
    FeePromotionFixture::platformWide($this->base->addDays(5), $this->base->addDays(10), 0);

    $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', false);

    Carbon::setTestNow($this->base->addDays(7));

    $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', true);

    Carbon::setTestNow($this->base->addDays(11));

    $this->getJson('/api/public/fee-promotion')
        ->assertOk()
        ->assertJsonPath('data.active', false);
});
