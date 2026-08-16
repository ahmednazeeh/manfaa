<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\Customer;
use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
    $this->withHeader('Referer', 'http://localhost');

    // Capture outbound SMS instead of logging — the SmsSender interface is
    // the §14 provider swap point, and this is exactly how a real provider
    // will replace LogSmsSender.
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
});

/** Pulls the 6-digit code out of the last captured SMS for a phone. */
function lastOtpCodeFor(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

it('signs up a customer end to end: request-otp, verify-otp, register', function () {
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])
        ->assertOk();

    $code = lastOtpCodeFor($this->sms, '+9607712345');

    $token = $this->postJson('/api/customer/auth/verify-otp', [
        'phone' => '+9607712345',
        'code' => $code,
    ])
        ->assertOk()
        ->json('data.signup_token');

    expect($token)->toBeString()->not->toBeEmpty();

    $response = $this->postJson('/api/customer/auth/register', [
        'signup_token' => $token,
        'name' => 'Aishath Manike',
        'password' => 'correct-horse-battery',
    ])->assertCreated();

    $customer = Customer::query()->where('phone', '+9607712345')->firstOrFail();

    expect($customer->phone_verified_at)->not->toBeNull();
    expect($customer->customer_code)->toMatch('/^\d{6}$/');
    expect($response->json('data.customer_code'))->toBe($customer->customer_code);

    // Registration logs the customer in.
    $this->assertAuthenticatedAs($customer, 'customer');
    $this->getJson('/api/customer/auth/me')->assertOk();

    // The existing password login path still works for the new account.
    $this->postJson('/api/customer/auth/logout')->assertNoContent();
    $this->postJson('/api/customer/auth/login', [
        'phone' => '+9607712345',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

it('rejects a malformed phone number', function () {
    // A Maldivian mobile starts 7 or 9; 1 is not a mobile prefix.
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '1112345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9601112345'])
        ->assertUnprocessable();

    expect($this->sms->sent)->toBeEmpty();
});

it('answers 200 with an identical body for known and unknown phones (no enumeration)', function () {
    Customer::factory()->create(['phone' => '+9607770001']);

    $known = $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607770001'])
        ->assertOk()
        ->json();

    $unknown = $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607770002'])
        ->assertOk()
        ->json();

    expect($known)->toBe($unknown);
});

it('refuses the code after 5 failed attempts, even the correct one', function () {
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = lastOtpCodeFor($this->sms, '+9607712345');
    $wrong = $code === '000000' ? '000001' : '000000';

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'otp_invalid');
    }

    // The 5th wrong attempt burns the code.
    $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $wrong])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'otp_attempts_exceeded');

    // Even the CORRECT code is now refused.
    $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'otp_attempts_exceeded');
});

it('expires codes after 10 minutes', function () {
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = lastOtpCodeFor($this->sms, '+9607712345');

    $this->travel(11)->minutes();

    $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'otp_invalid');
});

it('supersedes the previous code when a new one is requested', function () {
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $first = lastOtpCodeFor($this->sms, '+9607712345');

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $second = lastOtpCodeFor($this->sms, '+9607712345');

    if ($first !== $second) {
        $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $first])
            ->assertUnprocessable();
    }

    $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $second])
        ->assertOk();
});

it('throttles OTP requests to 3 per hour per phone', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    }

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // A different phone from the same IP still works (under the IP cap).
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712399'])->assertOk();
});

it('throttles OTP requests to 10 per hour per IP across phones', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/customer/auth/request-otp', [
            'phone' => sprintf('+96077123%02d', $i),
        ])->assertOk();
    }

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712398'])
        ->assertStatus(429);
});

it('rejects an invalid or expired signup token', function () {
    $this->postJson('/api/customer/auth/register', [
        'signup_token' => str_repeat('x', 48),
        'name' => 'Nobody',
        'password' => 'irrelevant-pass',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.signup_token.0', 'signup_token_invalid');

    // Expired: verify, then outlive the token TTL.
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = lastOtpCodeFor($this->sms, '+9607712345');
    $token = $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->json('data.signup_token');

    $this->travel(16)->minutes();

    $this->postJson('/api/customer/auth/register', [
        'signup_token' => $token,
        'name' => 'Late Arrival',
        'password' => 'irrelevant-pass',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.signup_token.0', 'signup_token_invalid');
});

it('refuses registration when the verified phone already has an account', function () {
    Customer::factory()->create(['phone' => '+9607712345']);

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = lastOtpCodeFor($this->sms, '+9607712345');
    $token = $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->assertOk()
        ->json('data.signup_token');

    $this->postJson('/api/customer/auth/register', [
        'signup_token' => $token,
        'name' => 'Duplicate',
        'password' => 'irrelevant-pass',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', 'phone_already_registered');

    expect(Customer::query()->where('phone', '+9607712345')->count())->toBe(1);
});

it('stores only hashes — never the code or token in the clear', function () {
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = lastOtpCodeFor($this->sms, '+9607712345');

    $row = OtpCode::query()->where('phone', '+9607712345')->latest('id')->firstOrFail();
    expect($row->code_hash)->not->toContain($code);

    $token = $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->json('data.signup_token');

    $row->refresh();
    expect($row->signup_token_hash)->not->toBe($token)
        ->and($row->signup_token_hash)->toHaveLength(64);
});

it('takes a bare seven-digit number and signs the same person up once', function () {
    // The forms ask for seven digits, because that is how a number is said
    // here. Storage stays E.164, so the two shapes have to meet before the
    // number is used as a key.
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '7712345'])
        ->assertOk();

    expect($this->sms->sent)->toHaveCount(1)
        // The gateway is handed E.164 whatever the form sent.
        ->and($this->sms->sent[0]['phone'])->toBe('+9607712345');

    preg_match('/\b(\d{6})\b/', $this->sms->sent[0]['message'], $matches);

    $token = $this->postJson('/api/customer/auth/verify-otp', [
        'phone' => '7712345',
        'code' => $matches[1],
    ])->assertOk()->json('data.signup_token');

    $this->postJson('/api/customer/auth/register', [
        'signup_token' => $token,
        'name' => 'Aishath',
        'password' => 'correct-horse-battery',
    ])->assertCreated();

    $customer = Customer::query()->sole();

    // One person, one row, stored the one way — not "7712345" beside a
    // "+9607712345" that some other screen created.
    expect($customer->phone)->toBe('+9607712345');
});

it('cannot be used to slip the per-phone throttle by changing format', function () {
    // Three requests an hour, per PHONE. If the key were the raw input,
    // alternating between the local and E.164 forms would buy six.
    foreach (['7712345', '+9607712345', '960 771 2345'] as $shape) {
        $this->postJson('/api/customer/auth/request-otp', ['phone' => $shape])
            ->assertOk();
    }

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])
        ->assertStatus(429);

    // And the reverse direction is the same number too.
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '7712345'])
        ->assertStatus(429);
});

it('still refuses a number that is not a Maldivian mobile', function () {
    foreach (['1234567', '77123', '+447700900123', '77123456'] as $bad) {
        $this->postJson('/api/customer/auth/request-otp', ['phone' => $bad])
            ->assertStatus(422);
    }

    expect($this->sms->sent)->toBeEmpty();
});
