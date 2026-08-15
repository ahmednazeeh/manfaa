<?php

declare(strict_types=1);

use App\Domain\Platform\InvalidSettingException;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\PromptDiscount;
use App\Domain\Settlement\PromptDiscountReason;
use App\Domain\Settlement\PromptDiscountResult;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PromptDiscount\PromptFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->discounts = app(PromptDiscount::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Evaluate the fixture's own transactions at "now". */
function evaluateAll(PromptFixture $fixture): PromptDiscountResult
{
    return test()->discounts->evaluate(
        $fixture->merchant,
        collect($fixture->transactions),
        CarbonImmutable::now('UTC'),
    );
}

it('discounts the platform fee by 5%, ceiling, and never the customer cashback', function () {
    $fixture = PromptFixture::singleLine();

    // HAND DERIVATION (§4 integers, ceiling in the merchant's favour):
    //   eligible          100,000 laari
    //   cashback @200bp   intdiv(100000 * 200 + 9999, 10000) = 2,000
    //   fee      @ 75bp   intdiv(100000 *  75 + 9999, 10000) =   750
    //   due                                        2,000 + 750 = 2,750
    //   discount @500bp   intdiv(   750 * 500 + 9999, 10000)
    //                   = intdiv(375000 + 9999, 10000)
    //                   = intdiv(384999, 10000)               =    38
    //                     (37.5 exactly, rounded UP to 38)
    //   amount due                                 2,750 − 38 = 2,712
    $transaction = $fixture->transactions[0];

    expect($transaction->cashback_laari)->toBe(2_000)
        ->and($transaction->fee_laari)->toBe(750)
        ->and($fixture->due(0))->toBe(2_750)
        ->and(intdiv(750 * 500 + 9999, 10000))->toBe(38);

    $result = evaluateAll($fixture);

    expect($result->eligible)->toBeTrue()
        ->and($result->reason)->toBe(PromptDiscountReason::Eligible)
        ->and($result->rateBp)->toBe(500)
        ->and($result->maxAgeDays)->toBe(10)
        ->and($result->feeTotalLaari)->toBe(750)
        ->and($result->discountLaari)->toBe(38)
        // The cashback is not part of the base at all: 5% of the DUE would
        // have been 138, and 5% of the cashback 100. Neither is this number.
        ->and($result->discountLaari)->not->toBe(138)
        ->and($fixture->due(0) - $result->discountLaari)->toBe(2_712);
});

it('rounds every fractional laari of discount UP, in the merchant\'s favour', function () {
    // The §4 batch: fee 3,225 → 3,225 × 5% = 161.25 → 162, never 161.
    $fixture = PromptFixture::fourLines();

    expect(array_sum(array_map(fn ($t) => $t->fee_laari, $fixture->transactions)))->toBe(3_225)
        ->and(evaluateAll($fixture)->discountLaari)->toBe(162)
        ->and(intdiv(3225 * 500, 10000))->toBe(161); // what truncation would have paid
});

it('refuses the discount when the batch leaves one outstanding transaction behind', function () {
    $fixture = PromptFixture::fourLines();

    // Three of the four: the merchant is not clearing the board.
    $partial = collect($fixture->transactions)->take(3);

    $result = $this->discounts->evaluate($fixture->merchant, $partial, CarbonImmutable::now('UTC'));

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe(PromptDiscountReason::NotAllOutstanding)
        ->and($result->discountLaari)->toBe(0)
        // The rate and window are still reported: the panel needs them to
        // say what the merchant WOULD have saved.
        ->and($result->rateBp)->toBe(500);

    // All four: eligible again.
    expect(evaluateAll($fixture)->eligible)->toBeTrue();
});

it('refuses the discount when any line has reached the age window, and grants it the day before', function () {
    $fixture = PromptFixture::fourLines();
    $clockStart = CarbonImmutable::parse(PromptFixture::CLOCK_START);

    // Day 9: still under the 10-day window — granted.
    Carbon::setTestNow($clockStart->addDays(9));
    expect(evaluateAll($fixture)->eligible)->toBeTrue();

    // Day 10: "under 10 days" is strict, so the window has closed.
    Carbon::setTestNow($clockStart->addDays(10));

    $result = evaluateAll($fixture);

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe(PromptDiscountReason::LineTooOld)
        ->and($result->discountLaari)->toBe(0);

    Carbon::setTestNow($clockStart->addDays(9));
});

it('refuses on ONE old line, and refuses again if the merchant leaves that line out', function () {
    // Four young lines plus one from eleven days earlier: the merchant who
    // has let something age cannot win either way, which is the whole point
    // of the incentive — settle everything, promptly, or pay full fee.
    $fixture = PromptFixture::fourLines();
    $old = $fixture->addPayable(50_000, CarbonImmutable::parse(PromptFixture::CLOCK_START)->subDays(11));

    expect($old->clock_start_at->toDateString())->toBe('2026-07-24');

    // Everything outstanding, but the old line is 12 days on the clock.
    expect(evaluateAll($fixture)->reason)->toBe(PromptDiscountReason::LineTooOld);

    // Leave the old one out and every included line is young — but now
    // something outstanding is left behind.
    $young = collect($fixture->transactions)->reject(fn ($t) => $t->id === $old->id);

    expect($this->discounts->evaluate($fixture->merchant, $young, CarbonImmutable::now('UTC'))->reason)
        ->toBe(PromptDiscountReason::NotAllOutstanding);
});

it('refuses a line whose settlement clock never started, however long ago that was', function () {
    // PLAN §13b: an earlier manual hold release landed rows in
    // payable_unfunded with clock_start_at AND due_at still null. Those rows
    // are real production data and they never age out — the escalation
    // ladder, the day-16 suspension and the 90-day write-off all filter on
    // the columns they are missing — so a line like this stays payable
    // forever. TransactionAge scores a null clock as age 0, which is right
    // for a dashboard bucket and fatal for an age GATE: without a refusal
    // the line is permanently young and earns the discount on every batch.
    $fixture = PromptFixture::fourLines();

    expect(evaluateAll($fixture)->eligible)->toBeTrue();

    DB::table('transactions')
        ->where('id', $fixture->transactions[0]->id)
        ->update(['clock_start_at' => null, 'due_at' => null]);

    $fixture->transactions[0]->refresh();

    $result = evaluateAll($fixture);

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe(PromptDiscountReason::ClockNotStarted)
        ->and($result->discountLaari)->toBe(0)
        // Still reported, so the panel can say what it WOULD have been.
        ->and($result->rateBp)->toBe(500)
        ->and($result->feeTotalLaari)->toBe(3_225);

    // A year later it is still refused — the point being that nothing about
    // this line ever changes on its own.
    Carbon::setTestNow(CarbonImmutable::parse(PromptFixture::CLOCK_START)->addDays(400));

    expect(evaluateAll($fixture)->reason)->toBe(PromptDiscountReason::ClockNotStarted);
});

it('measures age from clock_start_at in whole business-timezone days, not from the sale', function () {
    // The sale occurred an hour before it was keyed in on 1 August; the
    // clock only started when the validation window closed on the 4th. An
    // age measured from occurred_at would be three days older — and would
    // withdraw the discount three days early.
    $fixture = PromptFixture::fourLines();

    Carbon::setTestNow(CarbonImmutable::parse(PromptFixture::CLOCK_START)->addDays(9)->addHours(2));

    expect($fixture->transactions[0]->occurred_at->lessThan($fixture->transactions[0]->clock_start_at))->toBeTrue()
        ->and(evaluateAll($fixture)->eligible)->toBeTrue();
});

it('is switched off entirely by a zero rate, with the reason to say so', function () {
    $fixture = PromptFixture::fourLines(); // defaults: 500bp

    expect(evaluateAll($fixture)->discountLaari)->toBe(162);

    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $result = evaluateAll($fixture);

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe(PromptDiscountReason::Disabled)
        ->and($result->rateBp)->toBe(0)
        ->and($result->discountLaari)->toBe(0);
});

it('prices at whatever rate and window the platform is set to', function () {
    $fixture = PromptFixture::fourLines(rateBp: 1_000, maxAgeDays: 3);

    // 3,225 × 10% = 322.5 → 323.
    expect(evaluateAll($fixture)->discountLaari)->toBe(323);

    // A shorter window bites sooner: day 3 is already out.
    Carbon::setTestNow(CarbonImmutable::parse(PromptFixture::CLOCK_START)->addDays(3));

    expect(evaluateAll($fixture)->reason)->toBe(PromptDiscountReason::LineTooOld);
});

it('caps a discount that would outrun what the batch still owes', function () {
    $fixture = PromptFixture::singleLine();

    $result = evaluateAll($fixture);

    expect($result->discountLaari)->toBe(38)
        // A batch netted to 10 laari by §7 credits cannot give away 38.
        ->and($result->cappedTo(10)->discountLaari)->toBe(10)
        ->and($result->cappedTo(0)->discountLaari)->toBe(0)
        ->and($result->cappedTo(0)->storedRateBp())->toBeNull()
        // Capping never inflates a grant.
        ->and($result->cappedTo(10_000)->discountLaari)->toBe(38);
});

it('recomputes fee GST proportionally on the discounted fee', function () {
    // GST is zero everywhere today (§14 open item), so this exercises the
    // pure arithmetic that becomes live the day it is switched on.
    //   fee 1,000, GST 60 (6%), discount 50 (5%) →
    //   GST relief = ceiling(60 × 50 / 1000) = ceiling(3.0) = 3
    expect(PromptDiscount::gstRelief(60, 50, 1_000))->toBe(3)
        // Ceiling again in the merchant's favour: 61 × 50 / 1000 = 3.05 → 4.
        ->and(PromptDiscount::gstRelief(61, 50, 1_000))->toBe(4)
        // Never more GST than was charged.
        ->and(PromptDiscount::gstRelief(60, 1_000, 1_000))->toBe(60)
        // No fee, no discount, no GST: no relief.
        ->and(PromptDiscount::gstRelief(0, 50, 1_000))->toBe(0)
        ->and(PromptDiscount::gstRelief(60, 0, 1_000))->toBe(0)
        ->and(PromptDiscount::gstRelief(60, 50, 0))->toBe(0);
});

it('exposes both settings to the admin, with their ranges enforced', function () {
    $config = app(PlatformConfig::class);
    $admin = AdminUser::factory()->create();

    expect($config->promptDiscountRateBp())->toBe(500)
        ->and($config->promptDiscountMaxAgeDays())->toBe(10)
        // The window must stay SHORTER than the 15-day settlement clock, or
        // the incentive rewards nothing.
        ->and($config->promptDiscountMaxAgeDays())->toBeLessThan($config->settlementDueDays());

    $settings = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/platform/settings')
        ->assertOk()
        ->assertJsonPath('data.prompt_discount_rate_bp.value', 500)
        ->assertJsonPath('data.prompt_discount_rate_bp.default', 500)
        ->assertJsonPath('data.prompt_discount_rate_bp.min', 0)
        ->assertJsonPath('data.prompt_discount_rate_bp.max', 2_000)
        ->assertJsonPath('data.prompt_discount_rate_bp.overridden', false)
        ->assertJsonPath('data.prompt_discount_max_age_days.value', 10)
        ->assertJsonPath('data.prompt_discount_max_age_days.min', 1)
        ->assertJsonPath('data.prompt_discount_max_age_days.max', 15)
        ->json('data');

    expect($settings)->toHaveKeys(['prompt_discount_rate_bp', 'prompt_discount_max_age_days']);

    $this->patchJson('/api/admin/platform/settings/prompt_discount_rate_bp', ['value' => 750])
        ->assertOk()
        ->assertJsonPath('data.prompt_discount_rate_bp.value', 750)
        ->assertJsonPath('data.prompt_discount_rate_bp.overridden', true);

    // Live immediately — the write busts the 60-second cache.
    expect(app(PlatformConfig::class)->promptDiscountRateBp())->toBe(750);

    $this->patchJson('/api/admin/platform/settings/prompt_discount_rate_bp', ['value' => 2_001])
        ->assertUnprocessable();
    $this->patchJson('/api/admin/platform/settings/prompt_discount_max_age_days', ['value' => 16])
        ->assertUnprocessable();
    $this->patchJson('/api/admin/platform/settings/prompt_discount_max_age_days', ['value' => 0])
        ->assertUnprocessable();

    expect(fn () => app(PlatformConfig::class)->set('prompt_discount_rate_bp', -1))
        ->toThrow(InvalidSettingException::class);
});
