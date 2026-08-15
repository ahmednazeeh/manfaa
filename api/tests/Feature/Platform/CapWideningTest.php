<?php

declare(strict_types=1);

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
use App\Domain\Platform\RateNotPricedException;
use App\Domain\Platform\TierScheduleService;
use App\Domain\Promotions\PromotionService;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\FeeTierSchedule;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Cap widening (PLAN §13b item 3): structural ceiling 1000 -> 2000 bp, with
 * SELLABILITY governed by the active fee tier schedule's own ceiling.
 * Intended behaviour proven here: while the seeded 50-1000 schedule is
 * active, merchants cannot set rates above 10% (rate_not_priced) — the fee
 * must be priced before a rate is sellable; the static map's 2000 bp
 * fallback ceiling applies only when NO schedule row exists.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-14T12:00:00+05:00'));

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('DB CHECKs accept 2000 bp and reject 2001 bp on merchant_rates and promotions', function () {
    $now = CarbonImmutable::now('UTC');

    $rateRow = fn (int $bp): array => [
        'merchant_id' => $this->merchant->id,
        'rate_bp' => $bp,
        'effective_from' => $now,
        'effective_to' => null,
    ];
    $promoRow = fn (int $bp): array => [
        'merchant_id' => $this->merchant->id,
        'rate_bp' => $bp,
        'starts_at' => $now->addDay(),
        'ends_at' => $now->addDays(8),
        'status' => 'draft',
    ];

    // 2000 passes both widened CHECKs.
    expect(DB::table('merchant_rates')->insert($rateRow(2000)))->toBeTrue()
        ->and(DB::table('promotions')->insert($promoRow(2000)))->toBeTrue();

    // 2001 violates them. Each failing insert runs inside a nested
    // transaction (savepoint) so the test transaction survives the abort.
    expect(fn () => DB::transaction(fn () => DB::table('merchant_rates')->insert($rateRow(2001))))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('promotions')->insert($promoRow(2001))))
        ->toThrow(QueryException::class);
});

it('publishes a 50-2000 bp schedule through the admin endpoint', function () {
    $this->actingAs($this->admin, 'admin')
        ->postJson('/api/admin/platform/fee-tiers', [
            'effective_from' => CarbonImmutable::now()->addDay()->toIso8601String(),
            'tiers' => [
                ['from_percent' => '0.50', 'to_percent' => '9.99', 'fee_percent' => '0.25'],
                ['from_percent' => '10.00', 'to_percent' => '20.00', 'fee_percent' => '1.30'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.tiers.1.to_percent', '20.00');

    expect(FeeTierSchedule::query()->count())->toBe(2);
});

it('keeps the seeded 50-1000 schedule valid and refuses a 15% merchant rate as rate_not_priced', function () {
    // The seeded row still resolves and still bounds sellability at 10%.
    expect(app(TierScheduleService::class)->activeCeiling())->toBe(1000);

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '15.00'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced')
        ->assertJsonPath('message', 'The current fee schedule prices rates up to 10.00%.');

    // Nothing was appended to the rate history.
    expect(MerchantRate::query()->count())->toBe(1);
});

it('accepts 15% once a wider schedule takes effect, and credits price the fee FROM that schedule', function () {
    $this->seed(LedgerAccountSeeder::class);

    // Admin publishes a 50-2000 table effective one hour out. Not yet
    // effective: 15% stays refused (conservative — no early unlock).
    $this->actingAs($this->admin, 'admin')
        ->postJson('/api/admin/platform/fee-tiers', [
            'effective_from' => CarbonImmutable::now()->addHour()->toIso8601String(),
            'tiers' => [
                ['from_percent' => '0.50', 'to_percent' => '9.99', 'fee_percent' => '0.25'],
                ['from_percent' => '10.00', 'to_percent' => '20.00', 'fee_percent' => '1.30'],
            ],
        ])->assertCreated();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '15.00'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced');

    // Advance past effective_from: the wider schedule is now ACTIVE.
    Carbon::setTestNow(CarbonImmutable::now()->addHours(2));
    expect(app(TierScheduleService::class)->activeCeiling())->toBe(2000);

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '15.00'])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '15.00')
        // Fee priced by the SCHEDULE's 1000-2000 band (130), not the static
        // map's 100 — display and billing read the same source.
        ->assertJsonPath('data.current.platform_fee_percent', '1.30')
        ->assertJsonPath('data.current.all_in_percent', '16.30');

    // A credit at 15%: cashback ceil(100000*1500/10000) = 15000 laari and
    // fee ceil(100000*130/10000) = 1300 laari, frozen from the schedule.
    $customer = Customer::factory()->create(['customer_code' => '482917']);
    $transaction = app(ManualCreditService::class)->credit(
        $this->merchant->refresh(),
        $this->owner,
        $customer->customer_code,
        'INV-CAP-1500',
        Laari::of(100000),
        null,
        CarbonImmutable::now('UTC'),
    );

    expect($transaction->rate_bp)->toBe(1500)
        ->and($transaction->fee_bp)->toBe(130)
        ->and($transaction->cashback_laari)->toBe(15000)
        ->and($transaction->fee_laari)->toBe(1300);
});

it('rejects a promotion above the active ceiling as rate_not_priced, at draft and again at publish', function () {
    // Draft via HTTP under the seeded 50-1000 schedule: 15% is not priced.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'cashback_rate_percent' => '15.00',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDays(8)->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced')
        ->assertJsonPath('message', 'The current fee schedule prices rates up to 10.00%.');

    expect(Promotion::query()->count())->toBe(0);

    // Authoritative re-check at publish: a draft legally created under a
    // wide schedule is refused when a narrower schedule has since become
    // active — the fee for its rate is no longer priced anywhere.
    $this->actingAs($this->admin, 'admin')->postJson('/api/admin/platform/fee-tiers', [
        'effective_from' => CarbonImmutable::now()->addHour()->toIso8601String(),
        'tiers' => [
            ['from_percent' => '0.50', 'to_percent' => '20.00', 'fee_percent' => '0.50'],
        ],
    ])->assertCreated();

    Carbon::setTestNow(CarbonImmutable::now()->addHours(2)); // wide table active

    $draft = app(PromotionService::class)->createDraft(
        $this->merchant,
        $this->owner,
        1500,
        CarbonImmutable::now()->addDays(2),
        CarbonImmutable::now()->addDays(9),
    );

    $this->actingAs($this->admin, 'admin')->postJson('/api/admin/platform/fee-tiers', [
        'effective_from' => CarbonImmutable::now()->addHour()->toIso8601String(),
        'tiers' => [
            ['from_percent' => '0.50', 'to_percent' => '10.00', 'fee_percent' => '0.50'],
        ],
    ])->assertCreated();

    Carbon::setTestNow(CarbonImmutable::now()->addHours(2)); // narrow table active

    expect(fn () => app(PromotionService::class)->publish($draft))
        ->toThrow(RateNotPricedException::class)
        ->and($draft->refresh()->status)->toBe('draft');
});

it('leaves the §4 fixture unchanged: same integers under the widened bounds', function () {
    $calculator = new CashbackCalculator;

    $fixture = [
        // eligible  cashback  fee    due
        [100000, 2000, 750, 2750],
        [50000, 1000, 375, 1375],
        [200000, 4000, 1500, 5500],
        [80000, 1600, 600, 2200],
    ];

    $cashbackSum = 0;
    $feeSum = 0;

    foreach ($fixture as [$eligible, $cashback, $fee, $due]) {
        $result = $calculator->calculate(Laari::of($eligible), Rate::cashback(200), 75);

        expect($result->cashbackLaari)->toBe($cashback)
            ->and($result->feeLaari)->toBe($fee)
            ->and($result->cashbackLaari + $result->feeLaari)->toBe($due);

        $cashbackSum += $result->cashbackLaari;
        $feeSum += $result->feeLaari;
    }

    expect($cashbackSum)->toBe(8600)
        ->and($feeSum)->toBe(3225)
        ->and($cashbackSum + $feeSum)->toBe(11825);
});
