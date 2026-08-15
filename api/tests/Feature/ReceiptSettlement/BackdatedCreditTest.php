<?php

declare(strict_types=1);

use App\Domain\Adjustment\BackdatedIrreversibleException;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Models\Adjustment;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T10:00:00+00:00'));

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
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A vendor request through the real wire path. actingAs() leaves users on the
 * session guards and config('sanctum.guard') consults those BEFORE the bearer
 * token, so the resolved guards are dropped first — a till request arrives
 * with no panel session, and the test must look exactly like one.
 *
 * @param  array<string, mixed>  $payload
 */
function backdatedVendorPost(string $path, array $payload): TestResponse
{
    app('auth')->forgetGuards();
    test()->flushHeaders();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->vendorToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson($path, $payload);
}

/** A manual credit keyed in over HTTP with an explicit occurred_at. */
function backdatedCredit(string $occurredAt, string $invoiceNo = 'INV-BD-1'): TestResponse
{
    return test()->actingAs(test()->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => $invoiceNo,
            'eligible_amount' => 100_000,
            'occurred_at' => $occurredAt,
        ]);
}

/*
 * PLAN §1 "Backdated credits" — no admin approval, immediately payable,
 * merchant-irreversible. One rule in CreditRecorder covers the manual panel
 * path AND /v1.
 */

it('sends a backdated manual credit straight to payable_unfunded, never on_hold', function () {
    backdatedCredit(now()->subDays(10)->toIso8601String())
        ->assertCreated()
        ->assertJsonPath('data.state', 'payable_unfunded')
        ->assertJsonPath('data.reason_code', 'backdated_final')
        ->assertJsonPath('data.backdated', true);

    $transaction = Transaction::query()->sole();

    expect($transaction->state)->toBe(TransactionState::PayableUnfunded)
        ->and($transaction->backdated)->toBeTrue()
        // The clock starts NOW, not at the (10-day-old) sale.
        ->and($transaction->clock_start_at->equalTo(now()))->toBeTrue()
        ->and($transaction->due_at->getTimestamp())->toBe(
            now()->setTimezone(config('app.business_timezone'))->addDays(15)->setTimezone('UTC')->getTimestamp(),
        )
        // Never through on_hold — the history proves it.
        ->and($transaction->events()->where('to_state', 'on_hold')->count())->toBe(0)
        ->and($transaction->events()->orderBy('id')->pluck('to_state')->all())
        ->toBe(['tracked', 'awaiting_validation', 'payable_unfunded']);
});

it('still routes an in-window credit through the ordinary refund window', function () {
    backdatedCredit(now()->subHour()->toIso8601String(), 'INV-BD-FRESH')
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.reason_code', null)
        ->assertJsonPath('data.backdated', false);

    expect(Transaction::query()->sole()->backdated)->toBeFalse();
});

it('never produces on_hold from staleness on any path', function () {
    // Manual, and the vendor API, at 10 and 60 days old.
    backdatedCredit(now()->subDays(10)->toIso8601String(), 'INV-BD-A')->assertCreated();
    backdatedCredit(now()->subDays(60)->toIso8601String(), 'INV-BD-B')->assertCreated();

    $this->vendorToken = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;

    backdatedVendorPost('/api/v1/transactions', [
        'invoice_no' => 'INV-BD-C',
        'customer_ref' => '482917',
        'eligible_amount' => 100_000,
        'occurred_at' => now()->subDays(30)->format('Y-m-d\TH:i:sP'),
    ])
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('reason', 'backdated_final')
        ->assertJsonPath('transaction.state', 'payable_unfunded')
        ->assertJsonPath('transaction.backdated', true);

    expect(Transaction::query()->count())->toBe(3)
        ->and(Transaction::query()->where('state', 'on_hold')->count())->toBe(0)
        ->and(Transaction::query()->where('reason_code', 'stale_timestamp')->count())->toBe(0)
        ->and(Transaction::query()->where('backdated', true)->count())->toBe(3);
});

it('refuses a merchant reversal of a backdated row through the domain service', function () {
    backdatedCredit(now()->subDays(10)->toIso8601String())->assertCreated();

    $transaction = Transaction::query()->sole();

    expect(fn () => app(ReversalService::class)->reverse(
        $transaction,
        Actor::merchantUser($this->user->id),
        'customer_refund',
        now()->toImmutable(),
    ))->toThrow(BackdatedIrreversibleException::class);

    // Nothing moved: not the state, not a credit memo the merchant could
    // collect on the next batch instead.
    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and(Adjustment::query()->count())->toBe(0);
});

it('answers 409 backdated_irreversible on the /v1 reversal endpoint', function () {
    backdatedCredit(now()->subDays(10)->toIso8601String())->assertCreated();

    $transaction = Transaction::query()->sole();
    $this->vendorToken = $this->merchant->createToken('till', ['transactions:reverse'])->plainTextToken;

    backdatedVendorPost("/api/v1/transactions/{$transaction->id}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => now()->subMinutes(5)->format('Y-m-d\TH:i:sP'),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', BackdatedIrreversibleException::ERROR_CODE)
        ->assertJsonPath('error.meta.state', 'payable_unfunded');

    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and(Adjustment::query()->count())->toBe(0);
});

it('keeps refusing after the backdated reward confirms — the flag outlives the state', function () {
    $transaction = app(ManualCreditService::class)->credit(
        $this->merchant,
        $this->user,
        '482917',
        'INV-BD-CONFIRMED',
        Laari::of(100_000),
        null,
        now()->subDays(20)->toImmutable(),
    );

    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // Reason codes get rewritten by later hops; `backdated` does not.
    app(TransitionService::class)->confirm($transaction, Actor::system());

    expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($transaction->backdated)->toBeTrue();

    // An ordinary confirmed row would become a credit adjustment here
    // (cause already_confirmed). A backdated one gets nothing.
    expect(fn () => app(ReversalService::class)->reverse(
        $transaction,
        Actor::merchantUser($this->user->id),
        'customer_refund',
        now()->toImmutable(),
    ))->toThrow(BackdatedIrreversibleException::class);

    expect(Adjustment::query()->count())->toBe(0);
});

it('leaves an ordinary in-window credit fully reversible', function () {
    backdatedCredit(now()->subHour()->toIso8601String(), 'INV-BD-OK')->assertCreated();

    $transaction = Transaction::query()->sole();

    $outcome = app(ReversalService::class)->reverse(
        $transaction,
        Actor::merchantUser($this->user->id),
        'customer_refund',
        now()->toImmutable(),
    );

    expect($outcome->outcome)->toBe('reversed')
        ->and($transaction->refresh()->state)->toBe(TransactionState::Reversed);
});

it('does not flag a below-minimum backdated sale, which is terminal anyway', function () {
    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-BD-TINY',
            'eligible_amount' => 1_000, // under the 5,000 minimum
            'occurred_at' => now()->subDays(10)->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'below_minimum')
        ->assertJsonPath('data.backdated', false);

    expect(Transaction::query()->sole()->backdated)->toBeFalse();
});
