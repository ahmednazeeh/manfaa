<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * `PATCH /v1/transactions/{id}` (owner, 2026-08-22): the partial-refund
 * path for online stores — the same rules as the panel's amend, over the
 * vendor API under the writing ability.
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create(['validation_window_days' => 3, 'min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create(['rate_bp' => 200, 'effective_from' => now()->subYear(), 'effective_to' => null]);
    Customer::factory()->create(['customer_code' => '482917']);
    $this->token = $this->merchant->createToken('shop', ['transactions:write'])->plainTextToken;
});

function v1Headers(?string $token = null): array
{
    return ['Authorization' => 'Bearer '.($token ?? test()->token), 'Idempotency-Key' => (string) Str::uuid()];
}

function postedSale(array $overrides = []): int
{
    return test()->withHeaders(v1Headers())->postJson('/api/v1/transactions', array_merge([
        'invoice_no' => 'WOO-'.Str::random(6),
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'sale_amount' => 125000,
        'origin' => 'online_link',
    ], $overrides))->assertCreated()->json('transaction.id');
}

it('reduces a pending sale and re-prices the cashback at the frozen rate', function () {
    $id = postedSale();

    $this->withHeaders(v1Headers())
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000, 'sale_amount' => 107000])
        ->assertOk()
        ->assertJsonPath('status', 'amended')
        ->assertJsonPath('transaction.eligible_laari', 100000)
        ->assertJsonPath('transaction.sale_laari', 107000)
        ->assertJsonPath('transaction.cashback_laari', 2000)
        ->assertJsonPath('transaction.state', 'awaiting_validation');

    $balances = new Balances;
    expect($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2000);
});

it('zeroes a sale amended under the store minimum', function () {
    $id = postedSale();

    $this->withHeaders(v1Headers())
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 4999])
        ->assertOk()
        ->assertJsonPath('transaction.cashback_laari', 0);
});

it('refuses a sale past its window, a backdated sale, and lines that do not add up', function () {
    $id = postedSale();

    Transaction::query()->whereKey($id)->update(['state' => 'payable_unfunded']);
    $this->withHeaders(v1Headers())
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_amendable_state')
        ->assertJsonPath('error.meta.state', 'payable_unfunded');

    Transaction::query()->whereKey($id)->update(['state' => 'awaiting_validation', 'backdated' => true]);
    $this->withHeaders(v1Headers())
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'backdated_irreversible');

    Transaction::query()->whereKey($id)->update(['backdated' => false]);
    $this->withHeaders(v1Headers())
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000, 'lines' => [['category' => null, 'amount_laari' => 90000]]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'lines_sum_mismatch');

    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(118000);
});

it('refuses a sale total under the eligible amount, and other merchants\' sales', function () {
    $id = postedSale();

    $this->withHeaders(v1Headers())
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000, 'sale_amount' => 90000])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $other = Merchant::factory()->create()->createToken('x', ['transactions:write'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withHeaders(v1Headers($other))
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'transaction_not_found');
});

it('needs an idempotency key and replays under the same one', function () {
    $id = postedSale();

    // Default headers persist between requests in the test client; drop
    // the POST's key so this request genuinely carries none.
    $this->flushHeaders()
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    $first = $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Idempotency-Key' => $key])
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000])->assertOk()->json();
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Idempotency-Key' => $key])
        ->patchJson("/api/v1/transactions/{$id}", ['eligible_amount' => 100000])
        ->assertOk()->assertHeader('Idempotency-Replay', 'true')->assertJson($first);
});
