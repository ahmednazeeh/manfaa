<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function v1SalePayload(array $overrides = []): array
{
    return [
        'invoice_no' => 'INV-1001',
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * Recursively key-sorts a decoded JSON array so replayed bodies (stored in
 * jsonb, which does not preserve object key order) compare value-identical.
 *
 * @param  array<array-key, mixed>  $json
 * @return array<array-key, mixed>
 */
function canonicalJson(array $json): array
{
    ksort($json);

    return array_map(fn ($value) => is_array($value) ? canonicalJson($value) : $value, $json);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postSale(array $payload, ?string $key = null, ?string $token = null): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.($token ?? test()->token)];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return test()->withHeaders($headers)->postJson('/api/v1/transactions', $payload);
}

it('records a sale with exact §4 ceiling integers and one balanced accrual journal', function () {
    // The true integers from the rule itself: 118000 @ 200bp / 75bp.
    expect(intdiv(118000 * 200 + 9999, 10000))->toBe(2360)
        ->and(intdiv(118000 * 75 + 9999, 10000))->toBe(885);

    postSale(v1SalePayload(), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('reason', null)
        ->assertJsonPath('transaction.origin', 'pos')
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.rate_bp', 200)
        ->assertJsonPath('transaction.fee_bp', 75)
        ->assertJsonPath('transaction.eligible_laari', 118000)
        ->assertJsonPath('transaction.sale_laari', 125000)
        ->assertJsonPath('transaction.cashback_laari', 2360)
        ->assertJsonPath('transaction.cashback_mvr', '23.60')
        ->assertJsonPath('transaction.fee_laari', 885)
        ->assertJsonPath('transaction.fee_mvr', '8.85');

    $transaction = Transaction::query()->sole();
    $balances = new Balances;

    expect($transaction->state)->toBe(TransactionState::AwaitingValidation)
        ->and($transaction->cashback_laari)->toBe(2360)
        ->and($transaction->fee_laari)->toBe(885)
        ->and($transaction->idempotency_key)->not->toBeNull()
        ->and(DB::table('ledger_journals')->count())->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(2360 + 885)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2360)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(885);
});

it('replays the same key + same body with the identical body and no second journal', function () {
    $key = (string) Str::uuid();
    $payload = v1SalePayload();

    $first = postSale($payload, $key)->assertCreated();

    $replay = postSale($payload, $key)
        ->assertOk()
        ->assertHeader('Idempotency-Replay', 'true');

    expect(canonicalJson($replay->json()))->toBe(canonicalJson($first->json()))
        ->and(Transaction::query()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->count())->toBe(1);
});

it('rejects the same key with a different body as 422 idempotency_key_reuse_mismatch', function () {
    $key = (string) Str::uuid();

    postSale(v1SalePayload(), $key)->assertCreated();

    postSale(v1SalePayload(['invoice_no' => 'INV-1002']), $key)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'idempotency_key_reuse_mismatch');

    expect(Transaction::query()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->count())->toBe(1);
});

it('requires the Idempotency-Key header with 422 idempotency_key_required', function () {
    postSale(v1SalePayload())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'idempotency_key_required');

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects a duplicate invoice under a different key as 409 with the existing transaction id', function () {
    postSale(v1SalePayload(), (string) Str::uuid())->assertCreated();

    $existing = Transaction::query()->sole();

    postSale(v1SalePayload(), (string) Str::uuid())
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_invoice')
        ->assertJsonPath('error.meta.transaction_id', $existing->id);

    expect(Transaction::query()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->count())->toBe(1);
});

it('records a suspended merchant sale as 200 recorded_ineligible with zero laari, no journal, terminal state', function () {
    // §7: suspension stops cashback CREATION, not ingestion — the till keeps
    // POSTing and the cashier sees something truthful, never an error.
    $this->merchant->update(['status' => 'suspended']);

    postSale(v1SalePayload(), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('status', 'recorded_ineligible')
        ->assertJsonPath('reason', 'merchant_suspended')
        ->assertJsonPath('transaction.state', 'reversed')
        ->assertJsonPath('transaction.reason_code', 'merchant_suspended')
        ->assertJsonPath('transaction.cashback_laari', 0)
        ->assertJsonPath('transaction.fee_laari', 0)
        // The frozen rate is still present — the row evidences the terms the sale met.
        ->assertJsonPath('transaction.rate_bp', 200)
        ->assertJsonPath('transaction.fee_bp', 75);

    $transaction = Transaction::query()->sole();
    $events = $transaction->events()->orderBy('id')->get();

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and($transaction->state)->toBe(TransactionState::Reversed)
        ->and($transaction->cashback_laari)->toBe(0)
        ->and($transaction->fee_laari)->toBe(0)
        ->and($events)->toHaveCount(2)
        ->and($events[1]->to_state)->toBe('reversed')
        ->and($events[1]->reason_code)->toBe('merchant_suspended');
});

it('records a below-minimum sale as 200 below_minimum with zero cashback', function () {
    postSale(v1SalePayload(['eligible_amount' => 4999, 'sale_amount' => 4999]), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('status', 'below_minimum')
        ->assertJsonPath('reason', 'below_minimum')
        ->assertJsonPath('transaction.state', 'reversed')
        ->assertJsonPath('transaction.cashback_laari', 0);

    expect(DB::table('ledger_journals')->count())->toBe(0);
});

it('returns 403 when the token lacks the transactions:write ability', function () {
    $readOnly = $this->merchant->createToken('display', ['rates:read'])->plainTextToken;

    postSale(v1SalePayload(), (string) Str::uuid(), $readOnly)->assertForbidden();

    expect(Transaction::query()->count())->toBe(0);
});

it('returns 401 without a token', function () {
    $this->postJson('/api/v1/transactions', v1SalePayload(), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnauthorized();
});

it('rejects an unknown customer_ref as 422 customer_not_found', function () {
    postSale(v1SalePayload(['customer_ref' => '999999']), (string) Str::uuid())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'customer_not_found');

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects a future-dated occurred_at as 422 future_dated and releases the key for reuse', function () {
    $key = (string) Str::uuid();

    postSale(v1SalePayload(['occurred_at' => now()->addMinutes(10)->toIso8601String()]), $key)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'future_dated');

    // Only 2xx responses are stored — the corrected resend may reuse the key.
    postSale(v1SalePayload(), $key)->assertCreated();

    expect(Transaction::query()->count())->toBe(1);
});

it('rejects an offsetless occurred_at as 422 validation_failed in the error envelope', function () {
    postSale(v1SalePayload(['occurred_at' => '2026-08-14 16:00:00']), (string) Str::uuid())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'errors' => ['occurred_at']]]);

    expect(Transaction::query()->count())->toBe(0);
});

it("rejects another merchant's branch_id as validation_failed", function () {
    $other = Merchant::factory()->create();
    $foreignBranch = $other->branches()->create(['name' => 'Main', 'address' => 'Malé']);

    postSale(v1SalePayload(['branch_id' => $foreignBranch->id]), (string) Str::uuid())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(Transaction::query()->count())->toBe(0);
});
