<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\FeeTierSchedule;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * REGRESSION — backdated lined credit vs the fee schedule at occurred_at.
 *
 * Category override rates carry no effective-dated history and are only
 * validated now-forward, so a category rate set under TODAY's wide schedule
 * (e.g. 15% under a 50-2000 table) can lawfully price a BACKDATED credit
 * whose occurred_at resolves the older, narrower seeded 50-1000 schedule.
 * TierSchedule::feeBpFor throws OutOfRangeException there; before the fix
 * that escaped the credit DB transaction as an HTTP 500 on both the manual
 * path and POST /v1/transactions — including the §7 suspended-merchant
 * ingestion that must never error — and the basket was permanently
 * uncreditable (schedules cannot be published into the past).
 *
 * The fix (TermsResolver::ownFeeBp): the line's own-rate fee falls back to
 * the static §4 FeeTier map (the exact no-schedule fallback) when the
 * occurred_at schedule does not price the category rate. Every other rate
 * source stays schedule-priced.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    // A wide 50-2000 schedule took effect YESTERDAY (appended directly —
    // the admin endpoint only accepts future dates). Instants before it
    // resolve the migration-seeded 50-1000 row (effective 1970).
    FeeTierSchedule::query()->create([
        'effective_from' => CarbonImmutable::now('UTC')->subDay(),
        'tiers' => [
            ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 25],
            ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 50],
            ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 75],
            ['from_bp' => 500, 'to_bp' => 2000, 'fee_bp' => 100],
        ],
        'created_by' => null,
        'created_at' => CarbonImmutable::now('UTC'),
    ]);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500, // standing 5% — priced by BOTH schedules
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    // 15% category — sellable under the wide schedule active NOW, but NOT
    // priced by the seeded 50-1000 schedule that governs two days ago.
    $this->gold = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'gold', 'name_en' => 'Gold',
        'mode' => 'rate', 'rate_bp' => 1500, 'active' => true, 'sort' => 1,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function backdatedGoldPayload(array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => 15000,
        // Two days back: inside the non-stale window (3d validation + 3d
        // grace) but BEFORE the wide schedule's effective_from — the seeded
        // 50-1000 schedule prices this instant.
        'occurred_at' => now()->subDays(2)->toIso8601String(),
        'lines' => [
            ['category' => 'gold', 'amount_laari' => 10000],
            ['category' => null, 'amount_laari' => 5000],
        ],
        ...$overrides,
    ];
}

it('prices a backdated lined credit whose category rate exceeds the occurred_at schedule ceiling via the static §4 fallback, never 500', function () {
    $this->actingAs($this->user, 'merchant');

    // HAND DERIVATION (gold 1500bp — unpriced by the 50-1000 schedule at
    // occurred_at, so its fee falls back to the static §4 map: 100bp;
    // default 5,000 @ standing 500bp priced by the old schedule: 100bp):
    //   gold    10,000 @1500bp → intdiv(15,009,999, 10,000) → cashback 1,500
    //           fee 100bp      → intdiv( 1,009,999, 10,000) → fee        100
    //   default  5,000 @500bp  → intdiv( 2,509,999, 10,000) → cashback   250
    //           fee 100bp      → intdiv(   509,999, 10,000) → fee         50
    //   TOTALS: cashback 1,750; fee 150.
    $this->postJson('/api/merchant/credits', backdatedGoldPayload())
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.rate_bp', 500)   // standing base-rate snapshot
        ->assertJsonPath('data.fee_bp', 100)    // old schedule's 500bp tier
        ->assertJsonPath('data.cashback_laari', 1750)
        ->assertJsonPath('data.fee_laari', 150)
        ->assertJsonPath('data.lines.0.category', 'gold')
        ->assertJsonPath('data.lines.0.priced_by', 'category')
        ->assertJsonPath('data.lines.0.effective_rate_bp', 1500)
        ->assertJsonPath('data.lines.0.fee_bp', 100)
        ->assertJsonPath('data.lines.0.cashback_laari', 1500)
        ->assertJsonPath('data.lines.0.fee_laari', 100)
        ->assertJsonPath('data.lines.1.priced_by', 'standing')
        ->assertJsonPath('data.lines.1.effective_rate_bp', 500)
        ->assertJsonPath('data.lines.1.cashback_laari', 250)
        ->assertJsonPath('data.lines.1.fee_laari', 50);

    // Stored truth: totals equal the sums of the stored line integers, and
    // the accrual journal balances on exactly those integers.
    $transaction = Transaction::query()->sole();
    $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('sort')->get();

    expect($lines)->toHaveCount(2)
        ->and($transaction->cashback_laari)->toBe((int) $lines->sum('cashback_laari'))
        ->and($transaction->fee_laari)->toBe((int) $lines->sum('fee_laari'))
        ->and($transaction->cashback_laari)->toBe(1750)
        ->and($transaction->fee_laari)->toBe(150);

    $balances = new Balances;

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(1750 + 150)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(1750)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(150);
});

it('keeps schedule-era pricing for a lined credit occurring AFTER the wide schedule took effect (no fallback needed)', function () {
    $this->actingAs($this->user, 'merchant');

    // One hour back — the wide schedule governs; gold's fee comes from ITS
    // 500-2000 band (100bp), no fallback branch involved.
    $this->postJson('/api/merchant/credits', backdatedGoldPayload([
        'occurred_at' => now()->subHour()->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.effective_rate_bp', 1500)
        ->assertJsonPath('data.lines.0.fee_bp', 100)
        ->assertJsonPath('data.lines.0.cashback_laari', 1500)
        ->assertJsonPath('data.lines.0.fee_laari', 100);
});

it('records a suspended merchant backdated lined POST as 200 recorded_ineligible — §7 ingestion never errors, even past the old schedule ceiling', function () {
    $this->merchant->update(['status' => 'suspended']);
    $token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'INV-SUSP-1',
        'customer_ref' => '482917',
        'eligible_amount' => 15000,
        'occurred_at' => now()->subDays(2)->toIso8601String(),
        'lines' => [
            ['category' => 'gold', 'amount_laari' => 10000],
            ['category' => null, 'amount_laari' => 5000],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'recorded_ineligible')
        ->assertJsonPath('reason', 'merchant_suspended');

    // Zero money, terminal state, no journal — but the line snapshots still
    // evidence the terms each line met (gold at 1500bp, fallback fee 100bp).
    $transaction = Transaction::query()->sole();
    $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('sort')->get();

    expect($transaction->state)->toBe(TransactionState::Reversed)
        ->and($transaction->cashback_laari)->toBe(0)
        ->and($transaction->fee_laari)->toBe(0)
        ->and($lines)->toHaveCount(2)
        ->and($lines[0]->effective_rate_bp)->toBe(1500)
        ->and($lines[0]->fee_bp)->toBe(100)
        ->and($lines[0]->cashback_laari)->toBe(0)
        ->and($lines[0]->fee_laari)->toBe(0)
        ->and(DB::table('ledger_journals')->count())->toBe(0);
});
