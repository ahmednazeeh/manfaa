<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
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

    $this->actingAs($this->user, 'merchant');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manualCreditPayload(array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-1001',
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

it('credits MVR 1,250 at 200bp: cashback 2500, fee 938, awaiting validation, balanced accrual', function () {
    // The true integers, from the §4 rule itself.
    expect(intdiv(125000 * 200 + 5000, 10000))->toBe(2500)
        ->and(intdiv(125000 * 75 + 5000, 10000))->toBe(938);

    $this->postJson('/api/merchant/credits', manualCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.origin', 'manual')
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.rate_bp', 200)
        ->assertJsonPath('data.fee_bp', 75)
        ->assertJsonPath('data.cashback_laari', 2500)
        ->assertJsonPath('data.fee_laari', 938);

    $transaction = Transaction::query()->sole();

    expect($transaction->state)->toBe(TransactionState::AwaitingValidation)
        ->and($transaction->cashback_laari)->toBe(2500)
        ->and($transaction->fee_laari)->toBe(938)
        ->and($transaction->events()->count())->toBe(2);

    $events = $transaction->events()->orderBy('id')->get();

    expect($events[0]->from_state)->toBeNull()
        ->and($events[0]->to_state)->toBe('tracked')
        ->and($events[0]->actor_type)->toBe('merchant_user')
        ->and($events[1]->from_state)->toBe('tracked')
        ->and($events[1]->to_state)->toBe('awaiting_validation')
        ->and($events[1]->actor_type)->toBe('system')
        ->and($events[1]->reason_code)->toBe('auto_validation_window');

    $balances = new Balances;

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(2500 + 938)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2500)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(938);
});

it('returns 409 for a duplicate invoice and posts no second journal', function () {
    $this->postJson('/api/merchant/credits', manualCreditPayload())->assertCreated();
    $this->postJson('/api/merchant/credits', manualCreditPayload())->assertConflict();

    expect(Transaction::query()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->count())->toBe(1);
});

it('records a below-minimum credit with zero cashback and no journal', function () {
    $this->postJson('/api/merchant/credits', manualCreditPayload(['eligible_amount' => 4999]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'tracked')
        ->assertJsonPath('data.reason_code', 'below_minimum')
        ->assertJsonPath('data.cashback_laari', 0)
        ->assertJsonPath('data.fee_laari', 0)
        ->assertJsonPath('data.rate_bp', 200);

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and(Transaction::query()->sole()->events()->count())->toBe(1);
});

it('credits a row whose fee rounds to zero without a zero-amount journal line', function () {
    // At 200bp/75bp an eligible of 66 rounds to cashback 1, fee 0 (§4) —
    // legal once the merchant's minimum allows it, and it must still accrue.
    $this->merchant->update(['min_eligible_laari' => 50]);

    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'eligible_amount' => 66,
        'sale_amount' => 66,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.cashback_laari', 1)
        ->assertJsonPath('data.fee_laari', 0);

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(2)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('records a zero-cashback credit above the minimum without posting an empty journal', function () {
    // Eligible 24 at 200bp rounds everything to zero — nothing accrues.
    $this->merchant->update(['min_eligible_laari' => 10]);

    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'eligible_amount' => 24,
        'sale_amount' => 24,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 0)
        ->assertJsonPath('data.fee_laari', 0);

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(1);
});

it('rejects an occurred_at without an explicit UTC offset', function () {
    // "16:00" Maldives wall-clock with no offset would be read as 16:00 UTC —
    // five hours in the future, resolving the rate at the wrong instant.
    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => '2026-08-14 16:00:00',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('occurred_at');

    expect(Transaction::query()->count())->toBe(0);
});

it('stores a Maldives wall-clock occurred_at as the correct UTC instant', function () {
    $local = now('Indian/Maldives')->subHour()->startOfSecond();

    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => $local->toIso8601String(), // e.g. 2026-08-14T16:00:00+05:00
    ]))->assertCreated();

    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe($local->getTimestamp());
});

it('rejects a future-dated occurred_at with 422 and creates nothing', function () {
    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => now()->addMinutes(10)->toIso8601String(),
    ]))->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0)
        ->and(DB::table('ledger_journals')->count())->toBe(0);
});

it('puts a stale occurred_at on hold with reason stale_timestamp', function () {
    // validation_window_days (3) + 3 grace days = 6; 7 days ago is stale.
    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => now()->subDays(7)->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'on_hold')
        ->assertJsonPath('data.reason_code', 'stale_timestamp')
        ->assertJsonPath('data.cashback_laari', 2500);

    // The accrual still posts — the hold gates validation, not the ledger.
    expect(DB::table('ledger_journals')->count())->toBe(1);
});

it('freezes the rate effective at occurred_at, not the current rate', function () {
    $boundary = now()->subDays(10);

    $this->merchant->rates()->delete();
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 100,
        'effective_from' => now()->subYear(),
        'effective_to' => $boundary,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $boundary,
        'effective_to' => null,
    ]);

    // Before the boundary the 100bp rate governs, even though 200bp is current.
    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => now()->subDays(20)->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 100)
        ->assertJsonPath('data.fee_bp', 50)
        ->assertJsonPath('data.cashback_laari', intdiv(125000 * 100 + 5000, 10000))
        ->assertJsonPath('data.fee_laari', intdiv(125000 * 50 + 5000, 10000));

    expect(Transaction::query()->sole()->rate_bp)->toBe(100);
});

it('refuses a manual credit for a suspended merchant', function () {
    $this->merchant->update(['status' => 'suspended']);

    $this->postJson('/api/merchant/credits', manualCreditPayload())->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0)
        ->and(DB::table('ledger_journals')->count())->toBe(0);
});

it('returns 404 for an unknown customer code', function () {
    $this->postJson('/api/merchant/credits', manualCreditPayload(['customer_code' => '999999']))
        ->assertNotFound();

    expect(Transaction::query()->count())->toBe(0);
});
