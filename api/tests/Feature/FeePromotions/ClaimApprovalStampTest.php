<?php

declare(strict_types=1);

use App\Domain\Claims\ClaimApprovalService;
use App\Models\AdminUser;
use App\Models\Claim;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A CLAIMED MISSED SALE is priced through the same TermsResolver the till
 * uses, so it carries the same fee-promotion stamp — and, like the rate and
 * the fee tier beside it, the WINDOW is tested against the PURCHASE DATE. An
 * admin working the claims queue a week late cannot move what a sale cost by
 * the day they happened to click approve.
 *
 * (GST is the deliberate exception in the other direction — it is applied at
 * APPROVAL, because that is when the platform bills the fee.
 * ClaimApprovalService says so where it does it.)
 *
 * THE ONE ASYMMETRY, asserted below rather than left to be discovered: a fee
 * promotion lives on a single mutable settings row, not on an effective-dated
 * history the way cashback promotions and fee tier schedules do. So a
 * promotion that has been SWITCHED OFF prices nothing at all any more,
 * including a claim keyed in afterwards for a purchase date inside the window
 * it used to describe. That is the conservative direction — a finished
 * campaign stops giving money away — and it is the price of keeping the
 * settings a switch a superadmin can actually reason about.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = FeePromotionFixture::merchant(CarbonImmutable::parse('2026-01-01T06:00:00Z'));
    $this->customer = FeePromotionFixture::customer();
    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function claimFor(string $purchasedOn, int $amountLaari = 100_000): Claim
{
    return Claim::query()->create([
        'merchant_id' => test()->merchant->id,
        'customer_id' => test()->customer->id,
        'claimed_date' => $purchasedOn,
        'claimed_amount_laari' => $amountLaari,
        'currency' => 'MVR',
        'receipt_no' => 'RCPT-'.fake()->unique()->numberBetween(1000, 9999),
        'state' => 'open',
    ]);
}

it('stamps the promotion the PURCHASE DATE fell inside, whatever day the queue was worked', function (): void {
    // The promotion covers the first fortnight of September.
    FeePromotionFixture::platformWide(
        CarbonImmutable::parse('2026-09-01T00:00:00Z'),
        CarbonImmutable::parse('2026-09-15T00:00:00Z'),
        0,
    );

    // The shopper bought on 5 September; the admin approves on the 10th.
    $claim = claimFor('2026-09-05');

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10T06:00:00Z'));

    $transaction = app(ClaimApprovalService::class)->approve($claim, $this->admin);

    expect($transaction->origin)->toBe('claim')
        ->and($transaction->fee_bp)->toBe(0)
        ->and($transaction->fee_laari)->toBe(0)
        ->and($transaction->fee_promo_kind)->toBe('platform_wide')
        ->and($transaction->fee_promo_fee_bp)->toBe(0)
        ->and($transaction->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($transaction->fee_forgone_laari)->toBe(750);
});

it('stops promoting anything once the promotion is switched off, even for a purchase inside its old window', function (): void {
    FeePromotionFixture::platformWide(
        CarbonImmutable::parse('2026-09-01T00:00:00Z'),
        CarbonImmutable::parse('2026-09-15T00:00:00Z'),
        0,
    );

    $claim = claimFor('2026-09-05');

    // The campaign is pulled before anyone gets to the queue. There is no
    // effective-dated history of a fee promotion to fall back on — the
    // settings row is a switch, and the switch is off.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-20T06:00:00Z'));
    FeePromotionFixture::endAll();

    $transaction = app(ClaimApprovalService::class)->approve($claim, $this->admin);

    expect($transaction->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($transaction->fee_promo_kind)->toBeNull()
        ->and($transaction->fee_forgone_laari)->toBe(0);
});

it('stamps nothing on a claim for a purchase made outside every window', function (): void {
    FeePromotionFixture::platformWide(
        CarbonImmutable::parse('2026-09-01T00:00:00Z'),
        CarbonImmutable::parse('2026-09-15T00:00:00Z'),
        0,
    );

    // Bought in AUGUST, approved while the September promotion is live.
    $claim = claimFor('2026-08-20');

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05T06:00:00Z'));

    $transaction = app(ClaimApprovalService::class)->approve($claim, $this->admin);

    expect($transaction->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($transaction->fee_laari)->toBe(750)
        ->and($transaction->fee_promo_kind)->toBeNull()
        ->and($transaction->list_fee_bp)->toBeNull()
        ->and($transaction->fee_forgone_laari)->toBe(0);
});
