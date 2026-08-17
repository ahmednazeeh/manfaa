<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\Customer;
use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Customer round R1 — passwordless OTP sign-in/signup on the mobile tree.
 * One proof of phone possession, two outcomes, bearer tokens minted the M1
 * way. ONE authenticated request per test (the container caches Sanctum's
 * guard) unless forgetGuards is called.
 */
beforeEach(function () {
    $this->sms = new class implements SmsSender
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): void
        {
            $this->sent[] = ['phone' => $phone, 'message' => $message];
        }
    };
    $this->app->instance(SmsSender::class, $this->sms);

    // The dual limiter is Cache-backed state shared across tests in this
    // file's process — start each test with a clean SMS budget.
    RateLimiter::clear('otp-request:phone:+9607712345');
    RateLimiter::clear('otp-request:ip:127.0.0.1');
});

function mobileOtpCode(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

/** request → verify, returning the raw verify response. */
function verifyFor(object $test, string $phone, ?string $code = null): TestResponse
{
    $test->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => $phone])->assertOk();

    return $test->postJson('/api/mobile/v1/customer/auth/otp/verify', [
        'phone' => $phone,
        'code' => $code ?? mobileOtpCode($test->sms, $phone),
        'device_name' => "Ahmed's iPhone",
    ]);
}

// ------------------------------------------------------------- request

it('answers identically for known and unknown phones', function () {
    Customer::factory()->create(['phone' => '+9607712345']);

    $known = $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607712345'])
        ->assertOk();
    $unknown = $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607799999'])
        ->assertOk();

    // Byte-identical bodies: request() must never be an account oracle.
    expect($known->getContent())->toBe($unknown->getContent());
    expect(OtpCode::query()->count())->toBe(2);
});

it('accepts the seven local digits a person actually types', function () {
    $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '771-2345'])
        ->assertOk();

    expect(OtpCode::query()->sole()->phone)->toBe('+9607712345');
});

it('shares the per-phone SMS budget with the web signup endpoint', function () {
    // Same limiter keys on purpose: alternating surfaces must not double
    // the 3/hour budget a number can be bombed with.
    $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607712345'])->assertOk();
    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607712345'])->assertOk();

    $refused = $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607712345'])
        ->assertStatus(429);

    // Enveloped by the mobile tree, Retry-After intact.
    expect($refused->json('error.code'))->toBe('rate_limited');
    expect($refused->headers->get('Retry-After'))->not->toBeNull();
    // The platform's LONGEST wait must carry a countdown the app can show —
    // it reads the body, not the header.
    expect($refused->json('error.meta.retry_after_seconds'))->toBeGreaterThan(0);
});

// -------------------------------------------------------------- verify

it('signs an existing customer in and the token actually works', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);

    $response = verifyFor($this, '+9607712345')->assertOk();

    expect($response->json('data.status'))->toBe('signed_in');
    expect($response->json('data.customer.customer_code'))->toBe($customer->customer_code);

    $token = $response->json('data.token');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/mobile/v1/customer/me')
        ->assertOk()
        ->assertJsonPath('data.customer_code', $customer->customer_code);

    // The minting invariant, asserted on the ROW, not just a round-trip:
    // EnsureMobileToken accepts any token whose can() answers true, which a
    // '*' wildcard satisfies — and a wildcard would ALSO escape the device
    // list, the cap and every revocation surface. This is the guard against
    // a refactor that keeps /me green while minting never-expiring wildcards
    // from a 6-digit SMS code.
    $row = $customer->tokens()->sole();
    expect($row->abilities)->toBe(['mobile:customer']);
    expect($row->expires_at)->not->toBeNull();
    expect($response->json('data.expires_at'))
        ->toBe($row->expires_at->toIso8601ZuluString());
});

it('offers registration for an unknown phone', function () {
    $response = verifyFor($this, '+9607712345')->assertOk();

    expect($response->json('data.status'))->toBe('registration_required');
    expect($response->json('data.signup_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.token'))->toBeNull();
});

it('refuses a wrong code with its own error code', function () {
    Customer::factory()->create(['phone' => '+9607712345']);

    $response = verifyFor($this, '+9607712345', '000000')->assertStatus(422);

    expect($response->json('error.code'))->toBe('otp_invalid');
    // Prose for the human, never a bare snake_case string.
    expect($response->json('error.message'))->toContain('code');
});

it('caps guessing at five attempts per code', function () {
    $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607712345'])->assertOk();

    $last = null;
    foreach (range(1, 6) as $i) {
        $last = $this->postJson('/api/mobile/v1/customer/auth/otp/verify', [
            'phone' => '+9607712345', 'code' => '000000', 'device_name' => 'd',
        ])->assertStatus(422);
    }

    expect($last->json('error.code'))->toBe('otp_attempts_exceeded');

    // Even the CORRECT code is dead once the cap is hit.
    $this->postJson('/api/mobile/v1/customer/auth/otp/verify', [
        'phone' => '+9607712345',
        'code' => mobileOtpCode($this->sms, '+9607712345'),
        'device_name' => 'd',
    ])->assertStatus(422);
});

it('tells a suspended customer plainly, and still consumes the code', function () {
    Customer::factory()->create(['phone' => '+9607712345', 'status' => 'suspended']);

    $code = null;
    $this->postJson('/api/mobile/v1/customer/auth/otp/request', ['phone' => '+9607712345'])->assertOk();
    $code = mobileOtpCode($this->sms, '+9607712345');

    $response = $this->postJson('/api/mobile/v1/customer/auth/otp/verify', [
        'phone' => '+9607712345', 'code' => $code, 'device_name' => 'd',
    ])->assertStatus(403);

    // Possession is proven, so naming the standing is consistent with the
    // register() precedent — and no token was minted.
    expect($response->json('error.code'))->toBe('account_unavailable');
    expect(Customer::query()->sole()->tokens()->count())->toBe(0);

    // The correct code is spent: replaying it earns the ordinary refusal.
    $this->postJson('/api/mobile/v1/customer/auth/otp/verify', [
        'phone' => '+9607712345', 'code' => $code, 'device_name' => 'd',
    ])->assertStatus(422);
});

it('requires a device name at verify time', function () {
    $response = $this->postJson('/api/mobile/v1/customer/auth/otp/verify', [
        'phone' => '+9607712345', 'code' => '123456',
    ])->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');
    expect($response->json('error.meta.fields'))->toHaveKey('device_name');
});

// ------------------------------------------------------------ register

it('registers a new customer end to end and the account is passwordless-but-hashed', function () {
    $signupToken = verifyFor($this, '+9607712345')->json('data.signup_token');

    $response = $this->postJson('/api/mobile/v1/customer/auth/register', [
        'signup_token' => $signupToken,
        'name' => 'Aishath',
        'device_name' => "Aishath's phone",
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('signed_in');

    $customer = Customer::query()->sole();
    expect($customer->phone)->toBe('+9607712345');
    expect($customer->phone_verified_at)->not->toBeNull();

    // Passwordless the RIGHT way: `->not->toBe('')` is a tautology under the
    // 'hashed' cast (every non-null input bcrypts to non-empty), so it would
    // pass even if the controller regressed to a constant password — which
    // would make every app account takeover-able via phone + that constant
    // on both password-login surfaces. Assert instead that no GUESSABLE
    // value verifies.
    foreach (['', 'password', 'Aishath', '+9607712345', $signupToken] as $guess) {
        expect(Hash::check($guess, (string) $customer->password))->toBeFalse();
    }

    $row = $customer->tokens()->sole();
    expect($row->abilities)->toBe(['mobile:customer']);
    expect($row->expires_at)->not->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$response->json('data.token')])
        ->getJson('/api/mobile/v1/customer/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Aishath');
});

it('refuses a garbage or spent signup token with its own code', function () {
    $response = $this->postJson('/api/mobile/v1/customer/auth/register', [
        'signup_token' => 'nonsense',
        'name' => 'X',
        'device_name' => 'd',
    ])->assertStatus(422);

    expect($response->json('error.code'))->toBe('signup_token_invalid');
});

it('spends the signup token on first use', function () {
    $signupToken = verifyFor($this, '+9607712345')->json('data.signup_token');

    $this->postJson('/api/mobile/v1/customer/auth/register', [
        'signup_token' => $signupToken, 'name' => 'A', 'device_name' => 'd',
    ])->assertCreated();

    app('auth')->forgetGuards();

    // A double-submitted register must not mint a second account or token.
    $this->postJson('/api/mobile/v1/customer/auth/register', [
        'signup_token' => $signupToken, 'name' => 'B', 'device_name' => 'd',
    ])->assertStatus(422);

    expect(Customer::query()->count())->toBe(1);
});
