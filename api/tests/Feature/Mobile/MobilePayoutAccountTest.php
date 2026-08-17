<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * R5 — the payout account, and its fresh-OTP gate: a 365-day token is not
 * enough to redirect someone's cashback to another bank.
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
    RateLimiter::clear('otp-request:phone:+9607712345');
    RateLimiter::clear('otp-request:ip:127.0.0.1');
});

function payoutAcctAuth(Customer $customer): array
{
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

function payoutAcctOtp(object $test, Customer $customer): string
{
    $test->withHeaders(payoutAcctAuth($customer))
        ->postJson('/api/mobile/v1/customer/payout-account/otp')
        ->assertOk();

    $message = end($test->sms->sent)['message'];
    preg_match('/\b(\d{6})\b/', $message, $m);

    return $m[1];
}

it('shows the account, unset for a new customer', function () {
    $customer = Customer::factory()->create([
        'payout_bank' => null,
        'payout_account' => null,
        'payout_account_name' => null,
    ]);

    $this->withHeaders(payoutAcctAuth($customer))
        ->getJson('/api/mobile/v1/customer/payout-account')
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', false);
});

it('changes the account when a fresh code proves the phone', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $code = payoutAcctOtp($this, $customer);

    // A fresh guard resolution per request, or the cached guard answers the
    // second call stale.
    app('auth')->forgetGuards();

    $this->withHeaders(payoutAcctAuth($customer))
        ->putJson('/api/mobile/v1/customer/payout-account', [
            'bank_name' => 'bml',
            'account_no' => '7730000012894821',
            'account_name' => 'Aishath Ali',
            'otp_code' => $code,
        ])
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', true)
        ->assertJsonPath('data.account_no', '7730000012894821');

    expect($customer->fresh()->payout_account)->toBe('7730000012894821');
});

it('refuses the change without a valid code — the money-critical gate', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    // Never requested a code; a stolen token alone must not redirect payouts.
    payoutAcctOtp($this, $customer); // a code exists, but we submit a wrong one

    app('auth')->forgetGuards();

    $response = $this->withHeaders(payoutAcctAuth($customer))
        ->putJson('/api/mobile/v1/customer/payout-account', [
            'bank_name' => 'bml',
            'account_no' => '7730000012894821',
            'account_name' => 'Thief',
            'otp_code' => '000000',
        ])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('otp_invalid');
    // Nothing changed.
    expect($customer->fresh()->payout_account)->not->toBe('7730000012894821');
});

it('will not reuse a code across two changes', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $code = payoutAcctOtp($this, $customer);

    app('auth')->forgetGuards();
    $this->withHeaders(payoutAcctAuth($customer))
        ->putJson('/api/mobile/v1/customer/payout-account', [
            'bank_name' => 'bml',
            'account_no' => '7730000011111111',
            'account_name' => 'Aishath',
            'otp_code' => $code,
        ])->assertOk();

    app('auth')->forgetGuards();

    // The code is spent — a second change with it is refused, so a captured
    // code cannot be replayed to point the account somewhere else later.
    $this->withHeaders(payoutAcctAuth($customer))
        ->putJson('/api/mobile/v1/customer/payout-account', [
            'bank_name' => 'mib',
            'account_no' => '9999999999999999',
            'account_name' => 'Thief',
            'otp_code' => $code,
        ])->assertStatus(422);

    expect($customer->fresh()->payout_account)->toBe('7730000011111111');
});

it('shares the SMS budget with sign-in', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $headers = payoutAcctAuth($customer);

    for ($i = 0; $i < 3; $i++) {
        app('auth')->forgetGuards();
        $this->withHeaders($headers)
            ->postJson('/api/mobile/v1/customer/payout-account/otp')
            ->assertOk();
    }

    app('auth')->forgetGuards();
    $refused = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/customer/payout-account/otp')
        ->assertStatus(429);

    expect($refused->json('error.code'))->toBe('rate_limited');
});

it('requires all fields, including the code', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeaders(payoutAcctAuth($customer))
        ->putJson('/api/mobile/v1/customer/payout-account', [
            'bank_name' => 'bml',
            'account_no' => '7730000012894821',
            'account_name' => 'Aishath',
        ])
        ->assertStatus(422);

    expect($response->json('error.meta.fields'))->toHaveKey('otp_code');
});
