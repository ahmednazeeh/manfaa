<?php

declare(strict_types=1);

use App\Http\Controllers\V1\CustomerLookupController;
use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * §11 regression: the customer-code space is only 10^6, so a credential
 * walking /v1/customers/lookup could confirm which codes exist and harvest
 * masked-name fragments. Failed lookups are counted per credential; past
 * MISS_LIMIT misses in a day the credential is locked out of the endpoint
 * with 429 — a till's real misses are occasional typos, never dozens.
 */

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
    $this->token = $this->merchant->createToken('till', ['customers:lookup'])->plainTextToken;
});

function lookupAs(string $token, string $ref): TestResponse
{
    // In-process requests share resolved guards; a till request always
    // authenticates afresh, so the test must too (same idiom as the Phase 2
    // lifecycle test).
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/customers/lookup?ref='.$ref);
}

it('locks a credential out of lookups after too many misses in a day', function () {
    // Every miss up to the limit answers the normal 404.
    for ($i = 0; $i < CustomerLookupController::MISS_LIMIT; $i++) {
        lookupAs($this->token, sprintf('%06d', 900000 + $i))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'customer_not_found');
    }

    // Tripped: even a VALID ref now answers 429 on this credential — the
    // enumeration sweep is cut off, not steered toward hits.
    lookupAs($this->token, '482917')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    lookupAs($this->token, '900999')->assertStatus(429);
});

it('does not throttle successful lookups and scopes the miss count per credential', function () {
    for ($i = 0; $i < CustomerLookupController::MISS_LIMIT; $i++) {
        lookupAs($this->token, '111111')->assertNotFound();
    }

    lookupAs($this->token, '482917')->assertStatus(429);

    // A different credential (same merchant) is unaffected: the counter is
    // per token, exactly like the vendor-api throttle.
    $fresh = $this->merchant->createToken('till-2', ['customers:lookup'])->plainTextToken;

    lookupAs($fresh, '482917')
        ->assertOk()
        ->assertJsonPath('valid', true);
});
