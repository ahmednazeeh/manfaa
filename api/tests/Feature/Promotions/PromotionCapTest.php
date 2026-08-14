<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    // Live 500bp promo capped at 30,000 laari (MVR 300) per customer.
    $this->promotion = Promotion::query()->create([
        'merchant_id' => $this->merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_purchase_laari' => null,
        'max_cashback_per_customer_laari' => 30000,
        'status' => 'published',
        'published_at' => now()->subDays(2),
    ]);

    $this->actingAs($this->user, 'merchant');
});

/** Books prior on-promotion cashback so the customer has $laari of the cap consumed. */
function seedCapUsage(int $laari): Transaction
{
    return Transaction::factory()->create([
        'merchant_id' => test()->merchant->id,
        'customer_id' => test()->customer->id,
        'promotion_id' => test()->promotion->id,
        'rate_bp' => 500,
        'fee_bp' => 100,
        'cashback_laari' => $laari,
        'fee_laari' => intdiv($laari * 100 + 499, 500),
        'state' => 'awaiting_validation',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function capCreditPayload(array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

it('clips the final grant to the remaining cap with the fee ceiling-derived from the GRANTED reward, ledger balanced', function () {
    seedCapUsage(27000); // 3,000 laari of headroom left

    // §4 caps: cashback = min(6250, 3000) = 3000 — still above the standing
    // 2,500, so the clip stands; fee follows the reward granted:
    // ceil(3000 × 100bp / 500bp) = intdiv(3000·100 + 499, 500) = 600.
    $this->postJson('/api/merchant/credits', capCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.fee_bp', 100)
        ->assertJsonPath('data.cashback_laari', 3000)
        ->assertJsonPath('data.fee_laari', 600);

    $transaction = Transaction::query()->orderByDesc('id')->first();

    expect($transaction->promotion_id)->toBe($this->promotion->id);

    // §4 invariants hold through the capped accrual: the journal balances
    // and each account carries exactly the granted integers (the seeded row
    // never posted — only the real credit did).
    $balances = new Balances;

    expect($balances->journalsAllBalance())->toBeTrue()
        ->and(DB::table('ledger_journals')->count())->toBe(1)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(3600)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(3000)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(600);
});

it('floors a clipped grant at the standing rate — a promotion never pays less than no promotion at all', function () {
    seedCapUsage(29900); // 100 laari of headroom left

    // min(6250, 100) = 100 would pay 25× LESS than the standing 2,500 —
    // the floor takes the standing terms instead, the row is not stamped,
    // and the promo keeps its remaining headroom for a sale it can price.
    $this->postJson('/api/merchant/credits', capCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 200)
        ->assertJsonPath('data.fee_bp', 75)
        ->assertJsonPath('data.cashback_laari', 2500)
        ->assertJsonPath('data.fee_laari', 938);

    expect(Transaction::query()->orderByDesc('id')->first()->promotion_id)->toBeNull();

    // The preserved headroom still prices a small sale whose clip meets the
    // standing result: 5,000 @500bp → min(250, 100) = 100 ≥ standing 100;
    // fee ceil(100 × 100/500) = 20.
    $this->postJson('/api/merchant/credits', capCreditPayload(['eligible_amount' => 5000]))
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.fee_bp', 100)
        ->assertJsonPath('data.cashback_laari', 100)
        ->assertJsonPath('data.fee_laari', 20);

    expect(Transaction::query()->orderByDesc('id')->first()->promotion_id)->toBe($this->promotion->id)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('falls through to the next live promotion when the best one is exhausted for the customer', function () {
    seedCapUsage(30000); // the 500bp promo is fully consumed…

    // …but a second, lower-rate, uncapped promotion is simultaneously
    // published and live. The sale earns under IT — not the standing rate.
    $second = Promotion::query()->create([
        'merchant_id' => $this->merchant->id,
        'rate_bp' => 300,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_purchase_laari' => null,
        'max_cashback_per_customer_laari' => null,
        'status' => 'published',
        'published_at' => now()->subDays(2),
    ]);

    // 125,000 @300bp → 3,750; fee from the 300bp §4 tier (75bp) → 938.
    $this->postJson('/api/merchant/credits', capCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 300)
        ->assertJsonPath('data.fee_bp', 75)
        ->assertJsonPath('data.cashback_laari', 3750)
        ->assertJsonPath('data.fee_laari', 938);

    expect(Transaction::query()->orderByDesc('id')->first()->promotion_id)->toBe($second->id)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('falls back to the STANDING rate once the cap is exhausted — the customer still earns normally', function () {
    // DECISION (documented in TermsResolver): remaining cap 0 means the
    // promotion is exhausted FOR THIS CUSTOMER; with no other live
    // candidate they keep earning at the standing rate and the row is not
    // stamped with the promotion.
    seedCapUsage(30000);

    $this->postJson('/api/merchant/credits', capCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 200)
        ->assertJsonPath('data.fee_bp', 75)
        ->assertJsonPath('data.cashback_laari', 2500)
        ->assertJsonPath('data.fee_laari', 938);

    expect(Transaction::query()->orderByDesc('id')->first()->promotion_id)->toBeNull();
});

it('returns headroom when an on-promotion transaction is reversed — only non-reversed rows consume the cap', function () {
    $seeded = seedCapUsage(30000);
    $seeded->update(['state' => 'reversed']);

    $this->postJson('/api/merchant/credits', capCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.cashback_laari', 6250);

    expect(Transaction::query()->orderByDesc('id')->first()->promotion_id)->toBe($this->promotion->id);
});

it('caps other customers independently — one customer exhausting the promo leaves the next untouched', function () {
    seedCapUsage(30000);
    Customer::factory()->create(['customer_code' => '104433']);

    $this->postJson('/api/merchant/credits', capCreditPayload(['customer_code' => '104433']))
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.cashback_laari', 6250);
});

it('serialises two rapid credits under the advisory lock — the promotion sum can never exceed the cap', function () {
    seedCapUsage(29900);

    // Evidence the race guard is wired: every capped resolution takes
    // pg_advisory_xact_lock(promotion, customer) INSIDE the accrual's DB
    // transaction, so a concurrent credit blocks until this one commits and
    // then reads the updated SUM — two credits can never both see the same
    // 100 laari of headroom.
    $lockCalls = 0;
    DB::listen(function ($query) use (&$lockCalls) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockCalls++;
            expect($query->bindings[0])->toBe($this->promotion->id % 2147483647)
                ->and($query->bindings[1])->toBe($this->customer->id % 2147483647);
        }
    });

    // Two rapid back-to-back credits racing for the last 100 laari.
    $first = $this->postJson('/api/merchant/credits', capCreditPayload(['eligible_amount' => 5000]));
    $second = $this->postJson('/api/merchant/credits', capCreditPayload());

    // First takes the whole remaining headroom (its clip of 100 meets its
    // standing 100, so the floor keeps the promo grant); second finds none
    // and earns at the standing rate.
    $first->assertCreated()
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.cashback_laari', 100);
    $second->assertCreated()
        ->assertJsonPath('data.rate_bp', 200)
        ->assertJsonPath('data.cashback_laari', 2500);

    expect($lockCalls)->toBeGreaterThanOrEqual(2);

    // The invariant itself: cashback granted on this promotion never
    // exceeds the per-customer cap.
    $granted = (int) Transaction::query()
        ->where('promotion_id', $this->promotion->id)
        ->where('customer_id', $this->customer->id)
        ->where('state', '!=', 'reversed')
        ->sum('cashback_laari');

    expect($granted)->toBe(30000)
        ->and($granted)->toBeLessThanOrEqual($this->promotion->max_cashback_per_customer_laari)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('keeps the ledger balanced across a whole capped sequence: normal, clipped, exhausted', function () {
    // 29,000 used → normal credit of 4,000-eligible grants min(200, 1000)…
    seedCapUsage(29000); // 1000 laari headroom

    // Normal: 10,000 @500bp → 500 ≤ 1000 remaining, uncapped result stands.
    $this->postJson('/api/merchant/credits', capCreditPayload(['eligible_amount' => 10000]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 500)
        ->assertJsonPath('data.fee_laari', 100);

    // Clipped: 15,000 @500bp → 750 > 500 remaining → grant 500 (still ≥
    // the standing 300, so the clip survives the floor), fee
    // ceil(500·100/500) = 100.
    $this->postJson('/api/merchant/credits', capCreditPayload(['eligible_amount' => 15000]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 500)
        ->assertJsonPath('data.fee_laari', 100);

    // Exhausted: standing 200/75.
    $this->postJson('/api/merchant/credits', capCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 200)
        ->assertJsonPath('data.cashback_laari', 2500)
        ->assertJsonPath('data.fee_laari', 938);

    $balances = new Balances;

    expect($balances->journalsAllBalance())->toBeTrue()
        ->and(DB::table('ledger_journals')->count())->toBe(3)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(600 + 600 + 3438)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(500 + 500 + 2500)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(100 + 100 + 938);
});
