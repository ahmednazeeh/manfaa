<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500, // standing 5%
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->fruits = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    $this->veggies = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 200, 'active' => true, 'sort' => 2,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mixedBasketPayload(array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ],
        ...$overrides,
    ];
}

it('prices the mixed basket per line with exact hand-derived integers, totals = sum of stored lines', function () {
    $this->actingAs($this->user, 'merchant');

    // HAND DERIVATION (standing 500bp, veggies override 200bp, fruits
    // excluded; eligible 100,000 = 30,000 fruits + 25,000 veggies + 45,000
    // default; §4 fee tiers: 200bp→75bp, 500bp→100bp):
    //   fruits   30,000 → excluded             → cashback 0, fee 0
    //   veggies  25,000 @200bp → intdiv(25,000·200 + 9,999, 10,000)
    //            = intdiv(5,009,999, 10,000)   → cashback   500
    //            fee 75bp → intdiv(25,000·75 + 9,999, 10,000)
    //            = intdiv(1,884,999, 10,000)   → fee        188
    //   default  45,000 @500bp → intdiv(45,000·500 + 9,999, 10,000)
    //            = intdiv(22,509,999, 10,000)  → cashback 2,250
    //            fee 100bp → intdiv(45,000·100 + 9,999, 10,000)
    //            = intdiv(4,509,999, 10,000)   → fee        450
    //   TOTALS: cashback 500 + 2,250 = 2,750; fee 188 + 450 = 638.
    expect(intdiv(25000 * 200 + 9999, 10000))->toBe(500)
        ->and(intdiv(25000 * 75 + 9999, 10000))->toBe(188)
        ->and(intdiv(45000 * 500 + 9999, 10000))->toBe(2250)
        ->and(intdiv(45000 * 100 + 9999, 10000))->toBe(450);

    $response = $this->postJson('/api/merchant/credits', mixedBasketPayload())
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        // Row-level bp are the STANDING base-rate snapshot; per-line truth
        // lives in the lines.
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.fee_bp', 100)
        ->assertJsonPath('data.cashback_laari', 2750)
        ->assertJsonPath('data.fee_laari', 638);

    // The priced lines, in submitted order, with per-line integers.
    $response->assertJsonPath('data.lines.0.category', 'fruits')
        ->assertJsonPath('data.lines.0.priced_by', 'excluded')
        ->assertJsonPath('data.lines.0.amount_laari', 30000)
        ->assertJsonPath('data.lines.0.effective_rate_bp', 0)
        ->assertJsonPath('data.lines.0.fee_bp', 0)
        ->assertJsonPath('data.lines.0.cashback_laari', 0)
        ->assertJsonPath('data.lines.0.fee_laari', 0)
        ->assertJsonPath('data.lines.1.category', 'veggies')
        ->assertJsonPath('data.lines.1.priced_by', 'category')
        ->assertJsonPath('data.lines.1.effective_rate_bp', 200)
        ->assertJsonPath('data.lines.1.fee_bp', 75)
        ->assertJsonPath('data.lines.1.cashback_laari', 500)
        ->assertJsonPath('data.lines.1.fee_laari', 188)
        ->assertJsonPath('data.lines.2.category', null)
        ->assertJsonPath('data.lines.2.priced_by', 'standing')
        ->assertJsonPath('data.lines.2.effective_rate_bp', 500)
        ->assertJsonPath('data.lines.2.fee_bp', 100)
        ->assertJsonPath('data.lines.2.cashback_laari', 2250)
        ->assertJsonPath('data.lines.2.fee_laari', 450);

    // Stored truth: the transaction totals EQUAL the sums of the stored
    // line integers — never recomputed on the aggregate (which would give
    // intdiv(100,000·500+9,999, 10,000) = 5,000, not 2,750).
    $transaction = Transaction::query()->sole();
    $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('sort')->get();

    expect($lines)->toHaveCount(3)
        ->and($transaction->cashback_laari)->toBe((int) $lines->sum('cashback_laari'))
        ->and($transaction->fee_laari)->toBe((int) $lines->sum('fee_laari'))
        ->and($transaction->cashback_laari)->toBe(2750)
        ->and($transaction->fee_laari)->toBe(638)
        ->and($lines[0]->product_category_id)->toBe($this->fruits->id)
        ->and($lines[1]->product_category_id)->toBe($this->veggies->id)
        ->and($lines[1]->category_name_en)->toBe('Veggies')
        ->and($lines[2]->product_category_id)->toBeNull()
        ->and($lines[2]->category_slug)->toBeNull();

    // Accrual journal equals the summed line integers and balances.
    $balances = new Balances;

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(2750 + 638)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2750)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(638);
});

it('rejects lines that do not sum to the eligible amount with lines_sum_mismatch', function () {
    $this->actingAs($this->user, 'merchant');

    $this->postJson('/api/merchant/credits', mixedBasketPayload([
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => null, 'amount_laari' => 45000], // 75,000 ≠ 100,000
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'lines_sum_mismatch');

    expect(Transaction::query()->count())->toBe(0)
        ->and(TransactionLine::query()->count())->toBe(0)
        ->and(DB::table('ledger_journals')->count())->toBe(0);
});

it('rejects another merchant\'s category slug as unknown_category', function () {
    $this->actingAs($this->user, 'merchant');

    $other = Merchant::factory()->create();
    MerchantProductCategory::query()->create([
        'merchant_id' => $other->id, 'slug' => 'electronics', 'name_en' => 'Electronics',
        'mode' => 'rate', 'rate_bp' => 300, 'active' => true, 'sort' => 1,
    ]);

    $this->postJson('/api/merchant/credits', mixedBasketPayload([
        'lines' => [
            ['category' => 'electronics', 'amount_laari' => 55000],
            ['category' => null, 'amount_laari' => 45000],
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'unknown_category');

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects a deactivated category with inactive_category', function () {
    $this->actingAs($this->user, 'merchant');

    $this->veggies->update(['active' => false]);

    $this->postJson('/api/merchant/credits', mixedBasketPayload())
        ->assertUnprocessable()
        ->assertJsonPath('code', 'inactive_category');

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects repeated category lines — and a repeated default line — with duplicate_category_line', function () {
    $this->actingAs($this->user, 'merchant');

    $this->postJson('/api/merchant/credits', mixedBasketPayload([
        'lines' => [
            ['category' => 'veggies', 'amount_laari' => 60000],
            ['category' => 'veggies', 'amount_laari' => 40000],
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'duplicate_category_line');

    $this->postJson('/api/merchant/credits', mixedBasketPayload([
        'lines' => [
            ['category' => null, 'amount_laari' => 60000],
            ['category' => null, 'amount_laari' => 40000],
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'duplicate_category_line');

    expect(Transaction::query()->count())->toBe(0);
});

it('zeroes a below-minimum lined credit but still snapshots the lines with their frozen terms', function () {
    $this->actingAs($this->user, 'merchant');

    // eligible 4,000 < the 5,000 minimum → the whole credit is zeroed and
    // reversed terminally, exactly like the single-rate path. The lines are
    // still recorded (frozen terms evidence) with ZERO money, so the sums
    // stay equal to the zero row totals.
    $this->postJson('/api/merchant/credits', mixedBasketPayload([
        'eligible_amount' => 4000,
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 1000],
            ['category' => 'veggies', 'amount_laari' => 3000],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'below_minimum')
        ->assertJsonPath('data.cashback_laari', 0)
        ->assertJsonPath('data.fee_laari', 0)
        ->assertJsonPath('data.lines.1.effective_rate_bp', 200)
        ->assertJsonPath('data.lines.1.cashback_laari', 0)
        ->assertJsonPath('data.lines.1.fee_laari', 0);

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and(TransactionLine::query()->count())->toBe(2);
});

it('accrues nothing on a fully excluded basket yet records the split', function () {
    $this->actingAs($this->user, 'merchant');

    $this->postJson('/api/merchant/credits', mixedBasketPayload([
        'eligible_amount' => 30000,
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.cashback_laari', 0)
        ->assertJsonPath('data.fee_laari', 0)
        ->assertJsonPath('data.lines.0.priced_by', 'excluded');

    // Nothing accrued → no journal (same rule as the tiny-eligible case in
    // the single-rate path).
    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and(Transaction::query()->sole()->state)->toBe(TransactionState::AwaitingValidation);
});

it('prices the identical basket identically through /v1 (parity with the manual path)', function () {
    // No merchant session here — /v1 is bearer-token only (§9.1), and the
    // parity claim is against the SAME hand-derived integers the manual
    // test above froze: 2,750 cashback / 638 fee, lines 0+0, 500+188,
    // 2,250+450, through the one shared CreditRecorder write path.
    $token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'PAR-V',
        'customer_ref' => '482917',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ],
    ])->assertCreated();

    $response->assertJsonPath('status', 'created')
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.rate_bp', 500)
        ->assertJsonPath('transaction.fee_bp', 100)
        ->assertJsonPath('transaction.cashback_laari', 2750)
        ->assertJsonPath('transaction.fee_laari', 638)
        ->assertJsonPath('transaction.lines.0.priced_by', 'excluded')
        ->assertJsonPath('transaction.lines.0.cashback_laari', 0)
        ->assertJsonPath('transaction.lines.1.priced_by', 'category')
        ->assertJsonPath('transaction.lines.1.cashback_laari', 500)
        ->assertJsonPath('transaction.lines.1.fee_laari', 188)
        ->assertJsonPath('transaction.lines.2.priced_by', 'standing')
        ->assertJsonPath('transaction.lines.2.cashback_laari', 2250)
        ->assertJsonPath('transaction.lines.2.fee_laari', 450);

    // Stored split matches the response integers exactly.
    $api = Transaction::query()->where('invoice_no', 'PAR-V')->sole();
    $lines = TransactionLine::query()->where('transaction_id', $api->id)->orderBy('sort')->get();

    expect($api->cashback_laari)->toBe((int) $lines->sum('cashback_laari'))->toBe(2750)
        ->and($api->fee_laari)->toBe((int) $lines->sum('fee_laari'))->toBe(638);
});

it('answers /v1 line failures in the published error envelope', function () {
    $token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'ENV-1',
        'customer_ref' => '482917',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        'lines' => [
            ['category' => 'veggies', 'amount_laari' => 30000], // ≠ 100,000
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'lines_sum_mismatch');

    expect(Transaction::query()->count())->toBe(0);
});
