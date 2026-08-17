<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN-mobile-api.md M2 — the transport contract: one error envelope, a
 * launch config with a version gate, and a session refresh.
 */
function contractToken(Customer|MerchantUser $user, MobileAudience $audience): array
{
    $token = app(MobileTokenService::class)->issue($user, $audience, 'Device')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

// ------------------------------------------------------- the envelope

it('answers every mobile error in one envelope', function () {
    $response = $this->getJson('/api/mobile/v1/customer/me')->assertStatus(401);

    expect($response->json('error.code'))->toBe('unauthenticated');
    expect($response->json('error.message'))->toBe('Please sign in again.');
});

it('carries field errors inside the envelope meta', function () {
    $response = $this->postJson('/api/mobile/v1/customer/auth/token', [])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');
    expect($response->json('error.meta.fields'))->toHaveKeys(['phone', 'password', 'device_name']);
});

it('never renders a bare snake_case code as the human-readable message', function () {
    // The codebase deliberately throws validation messages that ARE codes
    // (OtpAuthController's `otp_attempts_exceeded`). Echoing one as the
    // fallback sentence would put snake_case on a customer's screen, which
    // is the exact thing the fallback exists to prevent.
    $response = $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607712345', 'password' => 'nope', 'device_name' => 'd',
    ])->assertStatus(422);

    expect($response->json('error.message'))->toBe('Some of the details entered need correcting.');

    // The envelope's own `code` is the machine-readable half and is always
    // present. FIELD messages are a mixed bag inherited from the panels:
    // __('auth.failed') resolves against the FRAMEWORK's bundled lang files
    // (there is no published lang/ directory, but Laravel ships its own), so
    // this one is English prose, while OtpAuthController deliberately throws
    // bare codes like `otp_attempts_exceeded`. Apps must therefore treat
    // error.code as the contract and field messages as display text.
    expect($response->json('error.code'))->toBe('validation_failed');
    expect($response->json('error.meta.fields.phone.0'))->toBeString();
});

it('reports a rate limit as a wait, not as a wrong password', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    Customer::factory()->create(['phone' => '+9607712345']);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/mobile/v1/customer/auth/token', [
            'phone' => '+9607712345', 'password' => 'wrong', 'device_name' => 'd',
        ])->assertStatus(422);
    }

    $response = $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => '+9607712345', 'password' => 'wrong', 'device_name' => 'd',
    ])->assertStatus(429);

    // A client must be able to tell "wait" from "wrong" — redrawing the form
    // with a field error against a possibly-correct password is the failure
    // this replaced.
    expect($response->json('error.code'))->toBe('rate_limited');
    expect($response->json('error.meta.retry_after_seconds'))->toBeGreaterThan(0);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('envelopes a 404 on an unknown mobile path', function () {
    $response = $this->getJson('/api/mobile/v1/nothing-here')->assertStatus(404);

    expect($response->json('error.code'))->toBe('not_found');
});

it('leaves the panels and the published vendor contract alone', function () {
    // The envelope is scoped by path for a reason: the panels deploy with
    // their API, and POS vendors parse the /v1 shapes documented in
    // docs/openapi.yaml today.
    $panel = $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/login', ['phone' => '+9607700000', 'password' => 'x'])
        ->assertStatus(422);

    expect($panel->json('errors'))->not->toBeNull();
    expect($panel->json('error'))->toBeNull();

    $vendor = $this->getJson('/api/v1/customers/lookup?phone=%2B9607712345')->assertStatus(401);

    expect($vendor->json('message'))->toBe('Unauthenticated.');
    expect($vendor->json('error'))->toBeNull();
});

// ---------------------------------------------------------- the config

it('publishes the version gate without any credential', function () {
    $response = $this->getJson('/api/mobile/v1/config')->assertOk();

    expect($response->json('data.apps.customer.ios.minimum_build'))->toBeInt();
    expect($response->json('data.apps.merchant.android.minimum_build'))->toBeInt();
    // server_time travels as a HEADER, not in the body: a per-second value
    // inside the hashed body would change the ETag on every read and the
    // 304 below could never fire.
    expect($response->headers->get('X-Server-Time'))->toBeString();
    expect($response->json('data.server_time'))->toBeNull();
    // Mirrors the server switch rather than a hardcoded expectation:
    // .env.testing turns claims ON so the dormant domain can be exercised,
    // while production leaves it off (config/features.php, 2026-08-14).
    expect($response->json('data.features.customer_claims'))
        ->toBe((bool) config('features.customer_claims'));
});

it('answers 304 when the config has not changed', function () {
    // Deliberately NOT wrapped in travelTo(). The earlier version froze time
    // because server_time sat inside the hashed body — which meant the test
    // certified a 304 that production could never serve. If this needs a
    // frozen clock again, something volatile has been put back in the body.
    $first = $this->getJson('/api/mobile/v1/config')->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $notModified = $this->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/mobile/v1/config')
        ->assertStatus(304);

    // C2: the 304 must echo the SAME validator. Symfony's setEtag() re-quotes
    // a weak tag into "W/"…"", which no client can ever match again.
    expect($notModified->headers->get('ETag'))->toBe($etag);
});

it('honours a list of etags, not just a single one', function () {
    $etag = $this->getJson('/api/mobile/v1/config')->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => 'W/"stale", '.$etag])
        ->getJson('/api/mobile/v1/config')
        ->assertStatus(304);
});

it('never lets a shared cache hold a mobile answer', function () {
    // Cloudflare fronts this origin and must never serve one customer's
    // answer to another.
    $response = $this->getJson('/api/mobile/v1/config')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private');
});

// --------------------------------------------------------- the session

it('refreshes merchant permissions that would otherwise be frozen for 90 days', function () {
    $merchant = Merchant::factory()->create();
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Cashier',
        'slug' => 'cashier-'.$merchant->id,
        'permissions' => [Permission::CreditsCreate->value],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $user = MerchantUser::factory()->for($merchant)->withRole($role)->create();

    $headers = contractToken($user, MobileAudience::Merchant);

    expect($this->withHeaders($headers)->getJson('/api/mobile/v1/merchant/me')->assertOk()
        ->json('data.permissions'))->toBe([Permission::CreditsCreate->value]);

    // The owner widens the role. The SAME token must now see it — before
    // this route existed the till's navigation was fixed at sign-in, so a
    // demoted cashier kept rendering the settlement builder.
    $role->forceFill([
        'permissions' => [Permission::CreditsCreate->value, Permission::SettlementsView->value],
    ])->save();

    app('auth')->forgetGuards();

    expect($this->withHeaders($headers)->getJson('/api/mobile/v1/merchant/me')->assertOk()
        ->json('data.permissions'))->toContain(Permission::SettlementsView->value);
});

it('tells the till whether its store may trade', function () {
    $user = MerchantUser::factory()->create();

    $this->withHeaders(contractToken($user, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/me')
        ->assertOk()
        ->assertJsonPath('data.merchant.status', $user->merchant->status);
});

it('gives the customer app its identity and whether it must nag about a bank account', function () {
    $customer = Customer::factory()->create(['payout_bank' => null]);

    $this->withHeaders(contractToken($customer, MobileAudience::Customer))
        ->getJson('/api/mobile/v1/customer/me')
        ->assertOk()
        ->assertJsonPath('data.customer_code', $customer->customer_code)
        ->assertJsonPath('data.has_payout_account', false);
});

it('refuses a merchant token on the customer session route', function () {
    $user = MerchantUser::factory()->create();

    $this->withHeaders(contractToken($user, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/customer/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
