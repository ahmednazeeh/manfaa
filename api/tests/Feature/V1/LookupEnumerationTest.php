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
 * masked-name fragments. Failed lookups are counted per MERCHANT; past
 * MISS_LIMIT misses in a day every credential of that store is locked out of
 * the endpoint with 429 — a till's real misses are occasional typos, never
 * dozens. Per-merchant keying is the point: owners self-issue their own
 * credentials, so a per-token budget would be one the store could multiply
 * at will.
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

it('locks the store out of lookups after too many misses in a day', function () {
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

it('shares one miss budget across every credential of the same store', function () {
    // Half the budget on one credential, half on a second: a store cannot
    // buy itself a fresh allowance by minting another token, which owners
    // can now do self-serve (§13b task #21).
    $second = $this->merchant->createToken('till-2', ['customers:lookup'])->plainTextToken;

    for ($i = 0; $i < CustomerLookupController::MISS_LIMIT / 2; $i++) {
        lookupAs($this->token, sprintf('%06d', 900000 + $i))->assertNotFound();
        lookupAs($second, sprintf('%06d', 910000 + $i))->assertNotFound();
    }

    lookupAs($this->token, '482917')->assertStatus(429)->assertHeader('Retry-After');
    lookupAs($second, '482917')->assertStatus(429);

    // Revoke-and-reissue does not reset it either — the budget belongs to
    // the store, not to the token id.
    $reissued = $this->merchant->createToken('till-3', ['customers:lookup'])->plainTextToken;

    lookupAs($reissued, '482917')->assertStatus(429);
});

it('does not throttle successful lookups and scopes the miss count per merchant', function () {
    for ($i = 0; $i < CustomerLookupController::MISS_LIMIT; $i++) {
        lookupAs($this->token, '111111')->assertNotFound();
    }

    lookupAs($this->token, '482917')->assertStatus(429);

    // Another merchant's credential is unaffected: one store's enumeration
    // sweep must never lock the rest of the network out of the till check.
    $other = Merchant::factory()->create();
    $otherToken = $other->createToken('till', ['customers:lookup'])->plainTextToken;

    lookupAs($otherToken, '482917')
        ->assertOk()
        ->assertJsonPath('valid', true);
});
