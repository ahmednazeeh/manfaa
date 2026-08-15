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
    expect(intdiv(125000 * 200 + 9999, 10000))->toBe(2500)
        ->and(intdiv(125000 * 75 + 9999, 10000))->toBe(938);

    $this->postJson('/api/merchant/credits', manualCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.origin', 'manual')
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.platform_fee_percent', '0.75')
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

it('records a below-minimum credit with zero cashback, no journal, immediately reversed', function () {
    // Nothing ever accrues below the minimum, so the row must not sit in
    // tracked showing the customer a Pending that can never confirm — it goes
    // terminal at once, with the full event trail and no ledger posting.
    $this->postJson('/api/merchant/credits', manualCreditPayload(['eligible_amount' => 4999]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'below_minimum')
        ->assertJsonPath('data.cashback_laari', 0)
        ->assertJsonPath('data.fee_laari', 0)
        ->assertJsonPath('data.cashback_rate_percent', '2.00');

    $transaction = Transaction::query()->sole();
    $events = $transaction->events()->orderBy('id')->get();

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and($transaction->state)->toBe(TransactionState::Reversed)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->from_state)->toBeNull()
        ->and($events[0]->to_state)->toBe('tracked')
        ->and($events[0]->reason_code)->toBe('below_minimum')
        ->and($events[1]->from_state)->toBe('tracked')
        ->and($events[1]->to_state)->toBe('reversed')
        ->and($events[1]->actor_type)->toBe('system')
        ->and($events[1]->reason_code)->toBe('below_minimum');
});

it('reverses a stale below-minimum credit terminally instead of holding it', function () {
    // Staleness gates validation; a below-minimum row has nothing to
    // validate, so the terminal reversal wins over the stale hold.
    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'eligible_amount' => 4999,
        'occurred_at' => now()->subDays(7)->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'below_minimum');

    expect(DB::table('ledger_journals')->count())->toBe(0);
});

it('ceils tiny credits to at least one laari of cashback and fee', function () {
    // Ceiling rule: 66 @ 200bp -> cashback ceil(1.32) = 2, fee ceil(0.495) = 1.
    // No nonzero eligible ever rounds to a zero reward, so every accrual above
    // the minimum posts a full journal.
    $this->merchant->update(['min_eligible_laari' => 50]);

    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'eligible_amount' => 66,
        'sale_amount' => 66,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.cashback_laari', 2)
        ->assertJsonPath('data.fee_laari', 1);

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(3)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('only produces zero cashback via the below-minimum path, never via rounding', function () {
    // 24 @ 200bp under ceiling is cashback 1 / fee 1 — rounding can no longer
    // zero a reward. Below the merchant minimum stays the one zero path.
    $this->merchant->update(['min_eligible_laari' => 10]);

    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'eligible_amount' => 24,
        'sale_amount' => 24,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 1)
        ->assertJsonPath('data.fee_laari', 1);

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(1)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('reads an offsetless occurred_at as Maldives time, not UTC', function () {
    // PLAN §1 (2026-08-15): a plain wall clock from a Maldivian till is
    // Maldives time. Reading it as UTC would misdate the sale by five hours
    // (and freeze the rate at the wrong instant), so the offsetless form is
    // interpreted, not refused.
    $local = now('Indian/Maldives')->subHour()->startOfSecond();

    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => $local->format('Y-m-d H:i:s'),
    ]))->assertCreated();

    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe($local->getTimestamp());
});

it('defaults occurred_at to now when it is omitted', function () {
    $payload = manualCreditPayload();
    unset($payload['occurred_at']);

    $this->postJson('/api/merchant/credits', $payload)
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation');

    expect(Transaction::query()->sole()->occurred_at->diffInSeconds(now()))->toBeLessThan(5);
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

it('makes a backdated occurred_at immediately payable, never on hold', function () {
    // validation_window_days (3) + 3 grace days = 6; 7 days ago is backdated.
    // PLAN §1: no admin approval, immediately payable, merchant-irreversible.
    $this->postJson('/api/merchant/credits', manualCreditPayload([
        'occurred_at' => now()->subDays(7)->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'payable_unfunded')
        ->assertJsonPath('data.reason_code', 'backdated_final')
        ->assertJsonPath('data.backdated', true)
        ->assertJsonPath('data.cashback_laari', 2500);

    // The clock started NOW, not at the (long past) occurred_at.
    $transaction = Transaction::query()->sole();

    expect($transaction->clock_start_at)->not->toBeNull()
        ->and($transaction->due_at->getTimestamp())
        ->toBe(now()->setTimezone(config('app.business_timezone'))->addDays(15)->setTimezone('UTC')->getTimestamp());

    // The accrual posts exactly as it always did.
    expect(DB::table('ledger_journals')->count())->toBe(1);

    // Never through on_hold: the append-only history shows tracked →
    // awaiting_validation → payable_unfunded and nothing else.
    expect($transaction->events()->orderBy('id')->pluck('to_state')->all())
        ->toBe(['tracked', 'awaiting_validation', 'payable_unfunded']);
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
        ->assertJsonPath('data.cashback_rate_percent', '1.00')
        ->assertJsonPath('data.platform_fee_percent', '0.50')
        ->assertJsonPath('data.cashback_laari', intdiv(125000 * 100 + 9999, 10000))
        ->assertJsonPath('data.fee_laari', intdiv(125000 * 50 + 9999, 10000));

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
