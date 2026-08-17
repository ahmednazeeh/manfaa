<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN-mobile-api.md M1. The cross-audience refusals below are the whole
 * reason EnsureMobileToken exists: Sanctum's Guard checks only that a
 * tokenable USES HasApiTokens, and all four models do — so without the type
 * assert, every one of these would pass instead of 401.
 */
function mobileToken(Customer|MerchantUser $user, MobileAudience $audience): string
{
    return app(MobileTokenService::class)
        ->issue($user, $audience, 'Test device')
        ->plainTextToken;
}

function asBearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

// ---------------------------------------------------------------- minting

it('mints a customer token from phone and password', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);

    $response = $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '7712345',
        'password' => 'password',
        'device_name' => "Ahmed's iPhone",
    ])->assertCreated();

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.device_name'))->toBe("Ahmed's iPhone");
    expect($response->json('data.customer.customer_code'))->toBe($customer->customer_code);

    // Seven local digits and the stored E.164 shape are one account.
    expect($customer->tokens()->count())->toBe(1);
});

it('mints a merchant token and returns the permissions the till builds its navigation from', function () {
    $user = MerchantUser::factory()->create(['email' => 'till@store.mv']);

    $response = $this->postJson('/api/mobile/v1/merchant/auth/token', [
        'email' => 'till@store.mv',
        'password' => 'password',
        'device_name' => 'Counter tablet',
    ])->assertCreated();

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.permissions'))->toBeArray();
});

it('gives a customer token a year and a merchant token a quarter', function () {
    $customer = Customer::factory()->create();
    $user = MerchantUser::factory()->create();

    $issued = app(MobileTokenService::class)->issue($customer, MobileAudience::Customer, 'phone');
    expect(now()->diffInDays($issued->expiresAt))->toBeGreaterThan(360);

    $issued = app(MobileTokenService::class)->issue($user, MobileAudience::Merchant, 'till');
    expect(now()->diffInDays($issued->expiresAt))->toBeLessThan(95);
});

// ------------------------------------------------------------- refusals

it('refuses a wrong password, an unknown phone and a suspended account identically', function () {
    Customer::factory()->create(['phone' => '+9607712345']);
    Customer::factory()->create(['phone' => '+9607799999', 'status' => 'suspended']);

    $wrongPassword = $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607712345', 'password' => 'nope', 'device_name' => 'd',
    ])->assertStatus(422);

    $unknownPhone = $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607700000', 'password' => 'password', 'device_name' => 'd',
    ])->assertStatus(422);

    $suspended = $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607799999', 'password' => 'password', 'device_name' => 'd',
    ])->assertStatus(422);

    // One refusal for every cause — no account-state oracle. Asserted against
    // the M2 envelope (error.meta.fields), NOT the old top-level `errors`:
    // once the envelope landed, that key was null on every response and the
    // comparison passed by comparing null to null.
    expect($wrongPassword->json('error.meta.fields'))->not->toBeNull();
    expect($unknownPhone->json('error.meta.fields'))->toEqual($wrongPassword->json('error.meta.fields'));
    expect($suspended->json('error.meta.fields'))->toEqual($wrongPassword->json('error.meta.fields'));
    expect($suspended->json('error.code'))->toBe('validation_failed');
});

it('refuses a deactivated staff member exactly like a wrong password', function () {
    MerchantUser::factory()->create(['email' => 'gone@store.mv', 'is_active' => false]);

    $this->postJson('/api/mobile/v1/merchant/auth/token', [
        'email' => 'gone@store.mv', 'password' => 'password', 'device_name' => 'd',
    ])->assertStatus(422);
});

// -------------------------------------------------- the type assert itself

it('refuses a customer token on every merchant mobile route', function () {
    $token = mobileToken(Customer::factory()->create(), MobileAudience::Customer);

    $this->withHeaders(asBearer($token))
        ->deleteJson('/api/mobile/v1/merchant/auth/token')
        ->assertStatus(401)
        // The M2 envelope, identical for all four failure modes.
        ->assertExactJson(['error' => [
            'code' => 'unauthenticated',
            'message' => 'Please sign in again.',
        ]]);
});

it('refuses a merchant token on every customer mobile route', function () {
    $token = mobileToken(MerchantUser::factory()->create(), MobileAudience::Merchant);

    $this->withHeaders(asBearer($token))
        ->deleteJson('/api/mobile/v1/customer/auth/token')
        ->assertStatus(401);
});

it('refuses a customer token on the vendor API', function () {
    $token = mobileToken(Customer::factory()->create(), MobileAudience::Customer);

    // GET /v1/customers/lookup is the most sensitive vendor route there is.
    $this->withHeaders(asBearer($token))
        ->getJson('/api/v1/customers/lookup?phone=%2B9607712345')
        ->assertStatus(401);
});

it('refuses a POS vendor token on the merchant mobile tree', function () {
    $merchant = Merchant::factory()->create();
    $vendorToken = $merchant->createToken('pos', ['transactions:write'])->plainTextToken;

    $this->withHeaders(asBearer($vendorToken))
        ->deleteJson('/api/mobile/v1/merchant/auth/token')
        ->assertStatus(401);
});

it('refuses a POS vendor token on the customer mobile tree', function () {
    // A SEPARATE test, not a second request in the one above. The container
    // caches Sanctum's RequestGuard, which memoises its user, so a second
    // authenticated request in one test never re-reads the Authorization
    // header — the old combined version passed just as happily with the
    // header replaced by garbage, and would have masked a real hole the day
    // someone changed this leg to a valid customer token.
    $merchant = Merchant::factory()->create();
    $vendorToken = $merchant->createToken('pos', ['transactions:write'])->plainTextToken;

    $this->withHeaders(asBearer($vendorToken))
        ->deleteJson('/api/mobile/v1/customer/auth/token')
        ->assertStatus(401);
});

it('refuses a browser session on the mobile API', function () {
    // A session user is returned by Sanctum carrying a TransientToken whose
    // can() answers true for EVERY ability — so the mobile tree is
    // bearer-only, exactly like /v1.
    $customer = Customer::factory()->create(['phone' => '+9607712345']);

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/login', [
            'phone' => '+9607712345',
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($customer, 'customer');

    $this->withHeader('Referer', 'http://localhost')
        ->deleteJson('/api/mobile/v1/customer/auth/token')
        ->assertStatus(401);
});

it('refuses a request with no credential at all', function () {
    $this->deleteJson('/api/mobile/v1/customer/auth/token')->assertStatus(401);
});

// --------------------------------------------- state changes after minting

it('stops honouring a token once the customer is suspended', function () {
    $customer = Customer::factory()->create();
    $token = mobileToken($customer, MobileAudience::Customer);

    // ONE authenticated request per test, deliberately. A second request in
    // the same test reuses the container's cached guard instances — the
    // Sanctum RequestGuard has already memoised its user, and the session
    // guard still holds whatever setUser() put there — so a stale, unsuspended
    // model answers and the assertion passes for the wrong reason. Under
    // php-fpm every request builds a fresh container, so this is a test
    // artefact, but it is one that hides exactly the bug being tested for.
    $customer->update(['status' => 'suspended']);

    $this->withHeaders(asBearer($token))
        ->deleteJson('/api/mobile/v1/customer/auth/tokens')
        ->assertStatus(401);
});

it('stops honouring a token once the staff member is deactivated', function () {
    $user = MerchantUser::factory()->create();
    $token = mobileToken($user, MobileAudience::Merchant);

    $user->update(['is_active' => false]);

    $this->withHeaders(asBearer($token))
        ->deleteJson('/api/mobile/v1/merchant/auth/token')
        ->assertStatus(401);
});

// ------------------------------------------------------------- revocation

it('revokes only the calling device on token delete', function () {
    $customer = Customer::factory()->create();

    $phone = mobileToken($customer, MobileAudience::Customer);
    $tablet = mobileToken($customer, MobileAudience::Customer);

    expect($customer->tokens()->count())->toBe(2);

    $this->withHeaders(asBearer($phone))
        ->deleteJson('/api/mobile/v1/customer/auth/token')
        ->assertNoContent();

    // Precisely the tablet survives — not "one of them".
    $surviving = $customer->tokens()->pluck('id')->all();

    expect($surviving)->toBe([PersonalAccessToken::findToken($tablet)->getKey()]);
});

it('revokes every device on tokens delete', function () {
    $customer = Customer::factory()->create();

    $phone = mobileToken($customer, MobileAudience::Customer);
    mobileToken($customer, MobileAudience::Customer);

    $this->withHeaders(asBearer($phone))
        ->deleteJson('/api/mobile/v1/customer/auth/tokens')
        ->assertOk()
        ->assertJsonPath('data.revoked', 2);

    expect($customer->tokens()->count())->toBe(0);
});

it('caps live tokens per user and evicts the least recently used', function () {
    $customer = Customer::factory()->create();

    for ($i = 0; $i < MobileTokenService::MAX_TOKENS_PER_AUDIENCE + 3; $i++) {
        mobileToken($customer, MobileAudience::Customer);
    }

    expect($customer->fresh()->tokens()->count())
        ->toBe(MobileTokenService::MAX_TOKENS_PER_AUDIENCE);
});

it('does not let a customer token evict a merchant token or vice versa', function () {
    // The cap is per audience, and a person who is both a shopper and a
    // cashier holds two unrelated accounts anyway — but the scoping query is
    // worth pinning, because a broken one would silently sign people out.
    $customer = Customer::factory()->create();

    for ($i = 0; $i < MobileTokenService::MAX_TOKENS_PER_AUDIENCE + 2; $i++) {
        mobileToken($customer, MobileAudience::Customer);
    }

    $user = MerchantUser::factory()->create();
    mobileToken($user, MobileAudience::Merchant);

    expect($user->fresh()->tokens()->count())->toBe(1);
    expect($customer->fresh()->tokens()->count())
        ->toBe(MobileTokenService::MAX_TOKENS_PER_AUDIENCE);
});

it('refuses to mint a token for the wrong audience', function () {
    $customer = Customer::factory()->create();

    expect(fn () => app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Merchant, 'device'))
        ->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------- the gate must ALLOW, not only refuse

it('lets a merchant token through the merchant gate', function () {
    // Until this existed, EVERY merchant assertion in this file expected 401.
    // Deleting the whole of EnsureMobileToken's success path — or denying the
    // merchant audience outright — would have left the suite green. A gate is
    // only proven by both answers.
    $user = MerchantUser::factory()->create();
    $token = mobileToken($user, MobileAudience::Merchant);

    $this->withHeaders(asBearer($token))
        ->deleteJson('/api/mobile/v1/merchant/auth/token')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('lets a customer token through the customer gate', function () {
    $customer = Customer::factory()->create();
    $token = mobileToken($customer, MobileAudience::Customer);

    $this->withHeaders(asBearer($token))
        ->deleteJson('/api/mobile/v1/customer/auth/token')
        ->assertNoContent();

    expect($customer->tokens()->count())->toBe(0);
});

// ----------------------------------------------------- eviction ordering

it('evicts the least recently used token and never the freshly minted one', function () {
    // Regression for the NULLS FIRST defect: last_used_at is NULL from mint
    // until the first authenticated request, so a brand-new token sorted
    // ahead of EVERY used one and was the first thing the next sign-in
    // deleted — signing a till in, then signing in anywhere else a minute
    // later, killed the till.
    $customer = Customer::factory()->create();

    $used = [];

    for ($i = 0; $i < 4; $i++) {
        $used[$i] = PersonalAccessToken::findToken(mobileToken($customer, MobileAudience::Customer));
        $used[$i]->forceFill(['last_used_at' => now()->subDays(10 - $i)])->save();
    }

    // The fifth is minted and never used — last_used_at stays NULL.
    $fresh = PersonalAccessToken::findToken(mobileToken($customer, MobileAudience::Customer));

    expect($fresh->last_used_at)->toBeNull();
    expect($customer->tokens()->count())->toBe(5);

    // A sixth sign-in prunes back to the cap.
    mobileToken($customer, MobileAudience::Customer);

    $surviving = $customer->tokens()->pluck('id')->all();

    // The genuine LRU (10 days idle) went; the never-used one stayed.
    expect($surviving)->not->toContain($used[0]->getKey());
    expect($surviving)->toContain($fresh->getKey());
    expect($surviving)->toHaveCount(MobileTokenService::MAX_TOKENS_PER_AUDIENCE);
});

it('reaps expired tokens instead of letting them hold a slot against live ones', function () {
    // Regression for the second half: Sanctum refuses an expired token before
    // stamping last_used_at, so a dead token's timestamp freezes at its final
    // valid use — often MORE recent than a live-but-idle one's. Ranked purely
    // on last_used_at, four dead rows kept their slots while the account's
    // only working credential was deleted.
    $customer = Customer::factory()->create();

    // Mint the whole set FIRST, then age it. Expiring as we go would let each
    // later mint reap the earlier ones and the fixture would never reach the
    // state under test.
    $minted = [];

    for ($i = 0; $i < 5; $i++) {
        $minted[$i] = PersonalAccessToken::findToken(mobileToken($customer, MobileAudience::Customer));
    }

    $dead = array_slice($minted, 0, 3);
    $live = array_slice($minted, 3, 2);

    foreach ($dead as $token) {
        // Frozen at its last VALID use — more recent than the live ones,
        // which is precisely what made it win the old eviction contest.
        $token->forceFill([
            'expires_at' => now()->subDays(10),
            'last_used_at' => now()->subDays(11),
        ])->save();
    }

    foreach ($live as $token) {
        $token->forceFill(['last_used_at' => now()->subDays(30)])->save();
    }

    expect($customer->tokens()->count())->toBe(5);

    $new = PersonalAccessToken::findToken(mobileToken($customer, MobileAudience::Customer));

    $surviving = $customer->tokens()->pluck('id')->all();

    // Three dead rows gone; both live credentials and the new one intact.
    expect($surviving)->toHaveCount(3);
    expect($surviving)->toContain($live[0]->getKey(), $live[1]->getKey(), $new->getKey());
});

// ----------------------------------------------------- per-account limit

it('throttles sign-in per ACCOUNT, which a forged forwarding header cannot rotate around', function () {
    // The route throttle is removed on purpose: it keys on $request->ip(),
    // which is exactly the control this counter exists to back up. What is
    // under test is the limit an attacker cannot spin around by changing a
    // header.
    $this->withoutMiddleware(ThrottleRequests::class);

    Customer::factory()->create(['phone' => '+9607712345']);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/mobile/v1/customer/auth/token', [
            'phone' => '+9607712345',
            'password' => 'wrong-'.$attempt,
            'device_name' => 'attacker',
        ])->assertStatus(422);
    }

    // The eleventh is refused before the password is even checked — and with
    // 429, so a client can tell "wait" from "wrong".
    $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607712345',
        'password' => 'wrong-again',
        'device_name' => 'attacker',
    ])->assertStatus(429);
});

it('clears the per-account counter on a correct password', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    Customer::factory()->create(['phone' => '+9607712345']);

    for ($attempt = 0; $attempt < 9; $attempt++) {
        $this->postJson('/api/mobile/v1/customer/auth/token', [
            'phone' => '+9607712345', 'password' => 'wrong', 'device_name' => 'd',
        ])->assertStatus(422);
    }

    $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607712345', 'password' => 'password', 'device_name' => 'd',
    ])->assertCreated();

    // A busy shop signing tills in all morning must never throttle itself
    // out: the successful attempt wiped the slate.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/mobile/v1/customer/auth/token', [
            'phone' => '+9607712345', 'password' => 'wrong', 'device_name' => 'd',
        ])->assertStatus(422);
    }
});
