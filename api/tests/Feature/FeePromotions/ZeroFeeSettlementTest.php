<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\PromptDiscount;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A ZERO FEE, ALL THE WAY THROUGH — the state the owner actually intends to
 * run during merchant acquisition, put through every downstream computation
 * that has ever divided by, or taken a percentage of, a fee.
 *
 * Nothing here is special-cased anywhere in the codebase, and that is what
 * these assertions are for: zero has to fall out of the ordinary arithmetic.
 *
 *   GST              8% ON TOP of a fee of nothing is nothing — FeeTax::split
 *                    returns [0, 0] because the fee is 0, not because the
 *                    rate is.
 *   PROMPT DISCOUNT  a percentage OF the fee. 10% of zero is zero, and the
 *                    ceiling division never sees a zero divisor (the divisor
 *                    is 10000, and PromptDiscount::ceilingBp short-circuits
 *                    on a zero amount).
 *   SETTLEMENT       the merchant owes the cashback alone.
 *   LEDGER           the accrual posts no 4100 line at all (Postings::accrue
 *                    omits a zero leg, because the poster rejects one), and
 *                    every journal still balances.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->base = CarbonImmutable::parse('2026-09-05T06:00:00Z');
    Carbon::setTestNow($this->base);

    // Both levers that take a slice of the fee, switched ON, so the zero has
    // to survive them rather than being untested.
    GstFixture::enable();
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 1000);

    FeePromotionFixture::platformWide(
        $this->base->subDay(),
        $this->base->addDays(30),
        0,
    );

    $this->merchant = FeePromotionFixture::merchant($this->base->subYear());
    $this->owner = FeePromotionFixture::owner($this->merchant);
    $this->customer = FeePromotionFixture::customer();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('prices a zero fee with zero GST, naturally — 8% of nothing', function (): void {
    $sale = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_laari)->toBe(0)
        ->and($sale->fee_gst_laari)->toBe(0)
        // The GST terms are still STAMPED on the row: the sale met an 8%
        // on-top regime and simply had nothing to tax. Re-pricing this row
        // from its own stamp reproduces it exactly.
        ->and($sale->fee_gst_bp)->toBe(GstFixture::RATE_BP)
        ->and($sale->fee_treatment->value)->toBe('on_top')
        ->and($sale->cashback_laari)->toBe(2_000)
        ->and($sale->fee_forgone_laari)->toBe(750);
});

it('grants a zero prompt discount without dividing by zero, and settles the cashback alone', function (): void {
    $sale = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    Carbon::setTestNow($this->base->addDays(4));
    app(TransitionService::class)->makePayable($sale, Actor::system());

    // The discount evaluates rather than throws, is GRANTED (every condition
    // is met), and comes to nothing because there is no fee to discount.
    $result = app(PromptDiscount::class)->evaluate(
        $this->merchant->refresh(),
        [$sale->id],
        CarbonImmutable::now('UTC'),
    );

    expect($result->eligible)->toBeTrue()
        ->and($result->feeTotalLaari)->toBe(0)
        ->and($result->feeDiscountLaari)->toBe(0)
        ->and($result->gstReliefLaari)->toBe(0);

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($this->merchant->refresh()))->refresh();

    // Cashback only: fee 0, GST 0, discount 0.
    expect($settlement->cashback_total_laari)->toBe(2_000)
        ->and($settlement->fee_total_laari)->toBe(0)
        ->and($settlement->fee_gst_total_laari)->toBe(0)
        ->and($settlement->discount_laari)->toBe(0)
        ->and($settlement->amount_due_laari)->toBe(2_000);

    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($settlement, Laari::of(2_000), 'BML-'.Str::upper(Str::random(10)));
    $settled = $allocator->matchPayment($payment, AdminUser::factory()->create())->refresh();

    expect($settled->amount_received_laari)->toBe(2_000)
        ->and($settled->state->value)->toBe('settled')
        ->and($sale->refresh()->state->value)->toBe('confirmed');
});

it('keeps every journal balanced and posts no fee revenue at all', function (): void {
    $sale = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    Carbon::setTestNow($this->base->addDays(4));
    app(TransitionService::class)->makePayable($sale, Actor::system());

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($this->merchant->refresh()))->refresh();

    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($settlement, Laari::of(2_000), 'BML-'.Str::upper(Str::random(10)));
    $allocator->matchPayment($payment, AdminUser::factory()->create());

    $balances = new Balances;

    expect($balances->journalsAllBalance())->toBeTrue()
        // The receivable rose by the cashback and was cleared by the payment.
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        // Nothing ever reached fee revenue or the tax liability: there was no
        // fee, so there was no leg — not a zero leg, no leg.
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(0)
        ->and($balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe(0)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2_000);

    // The trial balance closes to zero, which is the whole-ledger version of
    // the same statement.
    expect(array_sum(array_column($balances->trialBalance(), 'balance_laari')))->toBe(0);
});

it('still discounts and taxes normally the moment the promotion is not the cheaper price', function (): void {
    // The control: the same platform, the same switches, a merchant on a tier
    // CHEAPER than the promotion. The fee, its GST and its prompt discount
    // are all present — so the zeroes above are the promotion's doing and not
    // a broken fixture.
    $cheap = FeePromotionFixture::merchant($this->base->subYear(), rateBp: 50);
    $owner = FeePromotionFixture::owner($cheap);

    FeePromotionFixture::write(['wide_fee_bp' => 50]);

    $sale = FeePromotionFixture::credit($cheap, $owner, $this->customer, 100_000, $this->base->subHour());

    expect($sale->fee_bp)->toBe(25)
        ->and($sale->fee_laari)->toBe(250)
        ->and($sale->fee_gst_laari)->toBe(20)
        ->and($sale->fee_forgone_laari)->toBe(0);
});
