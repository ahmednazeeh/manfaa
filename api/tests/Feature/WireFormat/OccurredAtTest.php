<?php

declare(strict_types=1);

use App\Domain\Cashback\CreditRecorder;
use App\Domain\Cashback\TransactionState;
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

/**
 * PLAN §1 `occurred_at` (decision 2026-08-15): OPTIONAL — omitted means NOW
 * — and an offsetless wall clock is read as MALDIVES time rather than
 * refused. Future-dated is still 422; the backdated rule is unchanged.
 *
 * The old rule refused an offsetless string because reading it as UTC would
 * freeze the rate five hours early. Reading it as Maldives time solves the
 * same problem honestly, so these tests pin the INSTANT, never the digits.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    // A fixed "now" so a literal wall-clock string is neither future-dated
    // nor backdated by accident.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-15T12:00:00+05:00'));

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function occurredSale(array $overrides = []): array
{
    $payload = [
        'invoice_no' => 'INV-'.random_int(100000, 999999),
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];

    return array_filter($payload, fn ($value): bool => $value !== '__omit__');
}

/**
 * @param  array<string, mixed>  $payload
 */
function postOccurredSale(array $payload): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', $payload);
}

it('defaults an omitted occurred_at to now', function () {
    postOccurredSale(occurredSale(['occurred_at' => '__omit__']))
        ->assertCreated()
        ->assertJsonPath('transaction.state', TransactionState::AwaitingValidation->value)
        ->assertJsonPath('transaction.backdated', false)
        ->assertJsonPath('transaction.occurred_at', CarbonImmutable::now('UTC')->toIso8601String());

    $transaction = Transaction::query()->sole();

    expect($transaction->occurred_at->getTimestamp())->toBe(CarbonImmutable::now('UTC')->getTimestamp())
        ->and($transaction->state)->toBe(TransactionState::AwaitingValidation)
        ->and($transaction->backdated)->toBeFalse()
        ->and($transaction->reason_code)->toBeNull();
});

it('defaults an omitted occurred_at to now on the merchant manual credit too', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-7100',
        'eligible_amount' => 118000,
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', TransactionState::AwaitingValidation->value);

    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe(CarbonImmutable::now('UTC')->getTimestamp());
});

it('reads an offsetless "YYYY-MM-DD HH:MM:SS" as Maldives time, not UTC', function () {
    // 11:30 on the till's clock in Malé is 06:30 UTC. Read as UTC it would
    // be 11:30Z — five hours late, and a different rate window.
    postOccurredSale(occurredSale(['occurred_at' => '2026-08-15 11:30:00']))
        ->assertCreated()
        ->assertJsonPath('transaction.occurred_at', '2026-08-15T06:30:00+00:00');

    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-08-15T11:30:00+05:00')->getTimestamp())
        ->not->toBe(CarbonImmutable::parse('2026-08-15T11:30:00+00:00')->getTimestamp());
});

it('reads the offsetless "T" form identically', function () {
    postOccurredSale(occurredSale(['occurred_at' => '2026-08-15T11:30:00']))
        ->assertCreated()
        ->assertJsonPath('transaction.occurred_at', '2026-08-15T06:30:00+00:00');

    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-08-15T11:30:00+05:00')->getTimestamp());
});

it('leaves an explicit offset exactly as sent, in every accepted spelling', function (string $sent) {
    postOccurredSale(occurredSale(['occurred_at' => $sent]))->assertCreated();

    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-08-15T11:30:00+05:00')->getTimestamp());
})->with([
    '+05:00' => '2026-08-15T11:30:00+05:00',
    'Z' => '2026-08-15T06:30:00Z',
    '+0500 (no colon)' => '2026-08-15T11:30:00+0500',
    'another zone, same instant' => '2026-08-15T08:30:00+02:00',
]);

it('still refuses a future-dated occurred_at with 422 future_dated', function () {
    postOccurredSale(occurredSale(['occurred_at' => now()->addHours(2)->toIso8601String()]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'future_dated');

    // The offsetless form is future-dated the same way — being interpreted
    // does not make it exempt.
    postOccurredSale(occurredSale(['occurred_at' => now('Indian/Maldives')->addHours(2)->format('Y-m-d H:i:s')]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'future_dated');

    expect(Transaction::query()->count())->toBe(0);
});

it('keeps the backdated rule unchanged for both occurred_at spellings', function (string $sent) {
    // validation_window_days (3) + 3 grace days = 6; 8 days ago is backdated:
    // immediately payable, permanently merchant-irreversible (PLAN §1).
    postOccurredSale(occurredSale(['occurred_at' => $sent]))
        ->assertCreated()
        ->assertJsonPath('transaction.state', TransactionState::PayableUnfunded->value)
        ->assertJsonPath('transaction.backdated', true)
        ->assertJsonPath('transaction.reason_code', CreditRecorder::BACKDATED_REASON);

    expect(Transaction::query()->sole()->backdated)->toBeTrue();
})->with([
    'with an offset' => fn (): string => now()->subDays(8)->toIso8601String(),
    'offsetless Maldives wall clock' => fn (): string => now('Indian/Maldives')->subDays(8)->format('Y-m-d H:i:s'),
]);

it('refuses a shape that is neither ISO 8601 nor a plain wall clock', function (string $sent) {
    postOccurredSale(occurredSale(['occurred_at' => $sent]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['errors' => ['occurred_at']]]);

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'day-first' => '15/08/2026 11:30',
    'date only' => '2026-08-15',
    'no seconds' => '2026-08-15 11:30',
    'words' => 'yesterday',
]);

it('takes the same optional, flexible occurred_at on the reverse endpoint', function () {
    $this->merchant->createToken('till', ['transactions:reverse']);
    $token = $this->merchant->createToken('reverser', ['transactions:write', 'transactions:reverse'])->plainTextToken;

    $id = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/transactions', occurredSale())
        ->assertCreated()
        ->json('transaction.id');

    // No occurred_at at all: the reversal happened now.
    $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/transactions/{$id}/reverse", ['reason' => 'customer_refund'])
        ->assertOk()
        ->assertJsonPath('outcome', 'reversed');

    expect(Transaction::query()->find($id)->state)->toBe(TransactionState::Reversed);
});
