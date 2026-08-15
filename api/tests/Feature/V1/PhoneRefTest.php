<?php

declare(strict_types=1);

use App\Http\Controllers\V1\CustomerLookupController;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Original-spec online model 3: the API posts against a customer PHONE
 * NUMBER — full +960 E.164 or the 7-digit local mobile form — as an
 * alternative to the 6-digit code, rewarding points at Pending. Phone-keyed
 * credits record origin 'api_phone'; code-keyed sales stay 'pos'. An
 * unknown phone answers exactly like an unknown code, and lookup misses by
 * phone burn the same per-credential miss budget as code misses.
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

    $this->customer = Customer::factory()->create([
        'customer_code' => '482917',
        'phone' => '+9607712345',
        'name' => 'Aisha Mohamed',
    ]);

    $this->token = $this->merchant->createToken('till', ['transactions:write', 'customers:lookup'])->plainTextToken;
});

/**
 * @param  array<string, mixed>  $overrides
 */
function phoneRefSale(array $overrides = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ]);
}

function phoneRefLookup(string $ref): TestResponse
{
    // Fresh auth per request, same idiom as the enumeration test.
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->getJson('/api/v1/customers/lookup?ref='.urlencode($ref));
}

it('still records a code-keyed sale with origin pos', function () {
    phoneRefSale(['customer_ref' => '482917'])
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('transaction.origin', 'pos')
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.cashback_laari', 2360);

    expect(Transaction::query()->sole())
        ->origin->toBe('pos')
        ->customer_id->toBe($this->customer->id);
});

it('records a +960 phone-keyed sale with origin api_phone at Pending', function () {
    phoneRefSale(['customer_ref' => '+9607712345'])
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('transaction.origin', 'api_phone')
        // awaiting_validation is customer-facing Pending (§6).
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.cashback_laari', 2360)
        ->assertJsonPath('transaction.fee_laari', 885);

    expect(Transaction::query()->sole())
        ->origin->toBe('api_phone')
        ->customer_id->toBe($this->customer->id);
});

it('normalises a 7-digit local mobile to +960 E.164 and credits the same customer', function () {
    phoneRefSale(['customer_ref' => '7712345'])
        ->assertCreated()
        ->assertJsonPath('transaction.origin', 'api_phone');

    // A 9-prefixed local mobile resolves too — both mobile prefixes count.
    $nine = Customer::factory()->create(['phone' => '+9609345678']);

    phoneRefSale(['customer_ref' => '9345678'])
        ->assertCreated()
        ->assertJsonPath('transaction.origin', 'api_phone');

    expect(Transaction::query()->orderBy('id')->pluck('customer_id')->all())
        ->toBe([$this->customer->id, $nine->id]);
});

it('answers 422 customer_not_found for an unknown phone, exactly like an unknown code', function () {
    phoneRefSale(['customer_ref' => '+9607999999'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'customer_not_found');

    phoneRefSale(['customer_ref' => '9999999'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'customer_not_found');

    // The unknown-code answer is the same status and machine code — nothing
    // in the response distinguishes the two ref spaces.
    phoneRefSale(['customer_ref' => '999999'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'customer_not_found');

    expect(Transaction::query()->count())->toBe(0);
});

it('resolves a phone ref at lookup to the masked name, in both accepted forms', function () {
    phoneRefLookup('+9607712345')
        ->assertOk()
        ->assertExactJson([
            'ref' => '+9607712345',
            'valid' => true,
            'masked_name' => 'Ais*** Moh***',
        ]);

    phoneRefLookup('7712345')
        ->assertOk()
        ->assertJsonPath('ref', '7712345')
        ->assertJsonPath('valid', true)
        ->assertJsonPath('masked_name', 'Ais*** Moh***');
});

it('counts phone misses toward the same per-credential lookup lockout as code misses', function () {
    phoneRefLookup('+9607999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'customer_not_found');

    // Burn the remaining miss budget with phone refs alone…
    for ($i = 1; $i < CustomerLookupController::MISS_LIMIT; $i++) {
        phoneRefLookup(sprintf('9%06d', $i))->assertNotFound();
    }

    // …and the credential is locked out for VALID code and phone refs alike.
    phoneRefLookup('482917')->assertStatus(429)->assertHeader('Retry-After');
    phoneRefLookup('+9607712345')->assertStatus(429);
});

it('rejects garbage refs as 422 validation_failed on both endpoints', function (string $ref) {
    phoneRefSale(['customer_ref' => $ref])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    phoneRefLookup($ref)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'five digits' => ['12345'],
    'eight digits' => ['12345678'],
    'landline-shaped local (starts 3)' => ['3312345'],
    '+960 landline-shaped (starts 3)' => ['+9603312345'],
    'foreign E.164' => ['+9107712345'],
    'letters' => ['ABC917'],
    'code with spaces' => ['482 917'],
]);
