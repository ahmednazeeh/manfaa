<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Crash-safety regression for the idempotency middleware: the handler and
 * the response persistence commit in ONE transaction, so the only artefact
 * a mid-request worker death can leave is the claimed key row with a NULL
 * response and NO side effect. Such a row, once stale, must be taken over
 * by the retry — before this fix it poisoned the key forever (endless 409
 * idempotency_key_in_flight) while a fresh key hit duplicate_invoice.
 */

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
    Customer::factory()->create(['customer_code' => '482917']);
    $this->token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;
});

function idemPost(string $key, array $payload): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->token,
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/transactions', $payload);
}

function idemPayload(): array
{
    return [
        'invoice_no' => 'INV-CRASH-1',
        'customer_ref' => '482917',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ];
}

it('stores the response atomically with the recorded sale', function () {
    idemPost('crash-key-1', idemPayload())->assertCreated();

    $record = IdempotencyKey::query()->sole();

    // Never a committed sale behind a NULL response: both or neither.
    expect($record->response_status)->toBe(201)
        ->and($record->response_body)->not->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
});

it('takes over a stale abandoned claim instead of answering in_flight forever', function () {
    $payload = idemPayload();

    // The crash artefact: a claimed key, no response — and, because handler
    // and response commit together, no transaction either.
    IdempotencyKey::query()->create([
        'merchant_id' => $this->merchant->id,
        'key' => 'crash-key-2',
        'request_hash' => hash('sha256', 'POST|api/v1/transactions|'.json_encode($payload)),
        'created_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    // The vendor's honest same-key retry processes the sale afresh.
    idemPost('crash-key-2', $payload)
        ->assertCreated()
        ->assertJsonPath('status', 'created');

    $record = IdempotencyKey::query()->where('key', 'crash-key-2')->sole();

    expect($record->response_status)->toBe(201)
        ->and(Transaction::query()->count())->toBe(1);

    // And the stored response replays from here on.
    idemPost('crash-key-2', $payload)
        ->assertOk()
        ->assertHeader('Idempotency-Replay', 'true');

    expect(Transaction::query()->count())->toBe(1);
});

it('still answers in_flight for a FRESH concurrent claim', function () {
    $payload = idemPayload();

    IdempotencyKey::query()->create([
        'merchant_id' => $this->merchant->id,
        'key' => 'live-key',
        'request_hash' => hash('sha256', 'POST|api/v1/transactions|'.json_encode($payload)),
        'created_at' => CarbonImmutable::now('UTC'),
    ]);

    // Under 60s old: presumed genuinely in flight — poll, then 409 with the
    // retry-with-same-key contract, never a takeover of a live request.
    idemPost('live-key', $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_in_flight');

    expect(Transaction::query()->count())->toBe(0);
});
