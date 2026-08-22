<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression cover for the M2–M5 adversarial review. Each test names the
 * finding it pins; every one of them FAILS against the code as it stood
 * before the fix.
 */
function reviewToken(Customer|MerchantUser $user, MobileAudience $audience): array
{
    $token = app(MobileTokenService::class)->issue($user, $audience, 'Device')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/** A staff account holding exactly the named permissions. */
function staffWith(Merchant $merchant, array $permissions): MerchantUser
{
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Limited',
        'slug' => 'limited-'.$merchant->id,
        'permissions' => $permissions,
        'is_owner' => false,
        'is_system' => false,
    ]);

    return MerchantUser::factory()->for($merchant)->withRole($role)->create();
}

// ---------------------------------------------- the money bug (critic #1)

it('runs no query inside the caller transaction', function () {
    // THE defect of the round, pinned as the property that prevents it.
    //
    // NotificationService used to SELECT recipients inside the caller's money
    // transaction and swallow any error. On PostgreSQL a swallowed error
    // leaves the transaction ABORTED, so the COMMIT that follows is executed
    // as a ROLLBACK *and reports no error at all* — the caller is told the
    // settlement succeeded while every ledger posting was discarded. That was
    // demonstrated directly against Postgres; it cannot be reproduced inside
    // this suite, because RefreshDatabase's own enclosing transaction is
    // poisoned by the same abort and the assertions can no longer run.
    //
    // So the guarantee is asserted instead of the symptom: while the caller's
    // transaction is open, this service touches the database ZERO times.
    // Everything — the template lookup, the recipient query, the fan-out —
    // waits behind DB::afterCommit, where a failure can no longer share the
    // caller's fate. Revert deferred() to run inline and this fails.
    $merchant = Merchant::factory()->create();
    MerchantUser::factory()->for($merchant)->owner()->create();

    DB::table('notification_templates')
        ->where('key', NotificationTemplateKey::SettlementAccepted->value)
        ->update(['active' => true]);

    $duringTransaction = null;

    DB::transaction(function () use ($merchant, &$duringTransaction) {
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(NotificationService::class)->sendToMerchantStaff(
            NotificationTemplateKey::SettlementAccepted,
            $merchant,
            ['amount' => 'MVR 1.00', 'reference' => 'ST-1'],
            Permission::SettlementsView,
        );

        $duringTransaction = count(DB::getQueryLog());
        DB::disableQueryLog();
    });

    expect($duringTransaction)->toBe(0);
});

// --------------------------------------------------- A1 permission gates

it('refuses mobile transaction history to a staff account without transactions.view', function () {
    $merchant = Merchant::factory()->create();
    $cashier = staffWith($merchant, [Permission::CreditsCreate->value]);

    $this->withHeaders(reviewToken($cashier, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/transactions')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_required');
});

it('withholds the outstanding table and open batch from a till that may not see settlements', function () {
    $merchant = Merchant::factory()->create();
    $cashier = staffWith($merchant, [Permission::CreditsCreate->value]);

    $response = $this->withHeaders(reviewToken($cashier, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/home')
        ->assertOk();

    // Still useful to a cashier...
    expect($response->json('data.today.credit_count'))->toBe(0);
    expect($response->json('data.merchant.status'))->toBeString();
    // ...but the store's commercial standing is the panel's settlements.view
    // surface and must not arrive merely because they opened the app.
    expect($response->json('data.outstanding'))->toBeNull();
    expect($response->json('data.open_settlement'))->toBeNull();
});

it('still shows settlements to an account that may see them', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();

    $this->withHeaders(reviewToken($owner, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/home')
        ->assertOk()
        ->assertJsonPath('data.outstanding.total.payable_laari', 0);
});

// ------------------------------------------------------------ B1 the ETag

it('echoes the identical ETag on a 304', function () {
    // C2: no test read the tag off a 304, which is why the mangling shipped.
    // Symfony's setEtag() re-quotes a weak validator into "W/"…"" — a
    // different string that no client can ever match again.
    $customer = Customer::factory()->create();
    $headers = reviewToken($customer, MobileAudience::Customer);

    $etag = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/home')->assertOk()
        ->headers->get('ETag');

    app('auth')->forgetGuards();

    $notModified = $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->getJson('/api/mobile/v1/customer/home')
        ->assertStatus(304);

    expect($notModified->headers->get('ETag'))->toBe($etag);
    expect($etag)->toStartWith('W/"');
});

// --------------------------------------------------- B3 merchant home 304

it('serves an unchanged merchant home as a 304', function () {
    // C3: merchant home had no 304 test at all, and could not have passed
    // one — OutstandingSummary stamps a per-second `as_of` into the body.
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = reviewToken($owner, MobileAudience::Merchant);

    $etag = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/home')->assertOk()
        ->headers->get('ETag');

    app('auth')->forgetGuards();

    $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->getJson('/api/mobile/v1/merchant/home')
        ->assertStatus(304);
});

// ------------------------------------------------------------- B5 cursor

it('answers a malformed cursor with 422, not 500', function () {
    $customer = Customer::factory()->create();

    // Decodes cleanly but carries none of the ordering parameters, which
    // reaches Laravel's addCursorConditions and used to throw unmapped.
    $cursor = base64_encode(json_encode(['_pointsToNextItems' => true]));

    $this->withHeaders(reviewToken($customer, MobileAudience::Customer))
        ->getJson('/api/mobile/v1/customer/transactions?cursor='.$cursor)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

// -------------------------------------------------------- B8/B9 push token

it('does not let one account take over another account\'s push registration', function () {
    $victim = Customer::factory()->create();
    $attacker = Customer::factory()->create();

    $this->withHeaders(reviewToken($victim, MobileAudience::Customer))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'victim-handset', 'platform' => 'ios',
        ])->assertNoContent();

    app('auth')->forgetGuards();

    $this->withHeaders(reviewToken($attacker, MobileAudience::Customer))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'victim-handset', 'platform' => 'ios',
        ])->assertNoContent();

    // The handover itself is legitimate — one handset, a new person — but the
    // victim must not be left holding a row somebody else now controls.
    expect(DeviceToken::query()->count())->toBe(1);
    expect($victim->fresh()->deviceTokens ?? collect())->toHaveCount(0);
    expect(DeviceToken::query()->firstOrFail()->tokenable_id)->toBe($attacker->getKey());
});

it('keeps a stored locale when a launch refresh omits it', function () {
    $customer = Customer::factory()->create();
    $headers = reviewToken($customer, MobileAudience::Customer);

    $this->withHeaders($headers)->putJson('/api/mobile/v1/customer/push-token', [
        'token' => 'handset', 'platform' => 'ios', 'locale' => 'dv', 'app_build' => 7,
    ])->assertNoContent();

    app('auth')->forgetGuards();

    // The shape an app sends on every launch.
    $this->withHeaders($headers)->putJson('/api/mobile/v1/customer/push-token', [
        'token' => 'handset', 'platform' => 'ios',
    ])->assertNoContent();

    $device = DeviceToken::query()->firstOrFail();

    expect($device->locale)->toBe('dv');
    expect($device->app_build)->toBe(7);
});

// ------------------------------------------------------------ B7 expired

it('does not push to a device whose auth token has expired', function () {
    Queue::fake();

    $customer = Customer::factory()->create();
    $auth = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Old phone')->plainTextToken;

    DeviceToken::query()->create([
        'tokenable_type' => $customer->getMorphClass(),
        'tokenable_id' => $customer->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
        'token' => 'stale', 'platform' => 'ios',
    ]);

    // Expired but not yet swept: hidden from the owner's device list, so they
    // are given no way to stop it receiving settlement references.
    PersonalAccessToken::findToken($auth)->forceFill(['expires_at' => now()->subDay()])->save();

    DB::table('notification_templates')
        ->where('key', NotificationTemplateKey::PayoutPaid->value)->update(['active' => true]);

    app(NotificationService::class)->send(NotificationTemplateKey::PayoutPaid, $customer, [
        'amount' => 'MVR 100.00', 'reference' => 'PB-1',
    ]);

    Queue::assertNotPushed(SendPushNotification::class);
});

// --------------------------------------------------- D2 credit error codes

it('gives a mistyped customer code its own error code', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();

    $response = $this->withHeaders(reviewToken($owner, MobileAudience::Merchant) + ['Idempotency-Key' => 'k'])
        ->postJson('/api/mobile/v1/merchant/credits', [
            'customer_code' => '999999',
            'invoice_no' => 'INV-1',
            'eligible_amount' => 1000,
        ])->assertStatus(404);

    // Previously indistinguishable from a wrong URL: "That could not be found."
    expect($response->json('error.code'))->toBe('customer_not_found');
    expect($response->json('error.message'))->toContain('999999');
});

it('gives a future-dated sale its own error code rather than validation_failed', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $customer = Customer::factory()->create();

    $response = $this->withHeaders(reviewToken($owner, MobileAudience::Merchant) + ['Idempotency-Key' => 'k'])
        ->postJson('/api/mobile/v1/merchant/credits', [
            'customer_code' => $customer->customer_code,
            'invoice_no' => 'INV-1',
            'eligible_amount' => 1000,
            'occurred_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertStatus(422);

    // C5: the original test asserted only the status, which is why this went
    // unnoticed — a terminal refusal arriving as a form error.
    expect($response->json('error.code'))->toBe('future_dated');
});

// -------------------------------------------------------- A3 throttle key

it('keys the credit limiter on the model as well as the id', function () {
    // ThrottleRequests sorts ABOVE EnsureMobileToken, so a customer token's
    // rejected requests reach the limiter. With the inline `throttle:60,1`
    // form the key was sha1(id) with no model discriminator — customer #42
    // could lock out merchant-user #42 at an unrelated store.
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    // Same numeric id, different model — the exact collision.
    $customer = (new Customer)->forceFill(['id' => $owner->getKey()]);

    $limiter = RateLimiter::limiter('mobile-credits');

    expect($limiter)->not->toBeNull();

    $keyFor = function ($user) use ($limiter) {
        $request = Request::create('/api/mobile/v1/merchant/credits', 'POST');
        $request->setUserResolver(fn () => $user);

        return $limiter($request)->key;
    };

    expect($keyFor($owner))->not->toBe($keyFor($customer));
    expect($keyFor($owner))->toContain(MerchantUser::class);
});
