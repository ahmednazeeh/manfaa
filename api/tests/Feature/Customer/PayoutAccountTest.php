<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The website's payout account — gated by the SAME fresh-OTP proof the app
 * enforces (2026-08-17): a session cookie alone must never be enough to
 * redirect someone's cashback to another bank.
 */
beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    $this->customer = Customer::factory()->create(['phone' => '+9607712345']);

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

/** Request a code via the web route and lift it from the captured SMS. */
function webPayoutOtp(object $test): string
{
    $test->postJson('/api/customer/payout-account/otp')->assertOk();

    $message = end($test->sms->sent)['message'];
    preg_match('/\b(\d{6})\b/', $message, $m);

    return $m[1];
}

it('starts with no payout account', function () {
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/payout-account')
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', false)
        ->assertJsonPath('data.bank_name', null);
});

it('refuses to save without a code — the gate the web was missing', function () {
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/payout-account', [
            'bank_name' => 'bml',
            'account_no' => '7701234567890',
            'account_name' => 'AISHATH MANIKE',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('otp_code');

    expect($this->customer->refresh()->payout_bank)->toBeNull();
});

it('refuses a wrong code and never writes', function () {
    $this->actingAs($this->customer, 'customer');
    webPayoutOtp($this);

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'bml',
        'account_no' => '7701234567890',
        'account_name' => 'AISHATH MANIKE',
        'otp_code' => '000000',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'otp_invalid');

    expect($this->customer->refresh()->payout_bank)->toBeNull();
});

it('registers a payout account with a fresh code and reads it back', function () {
    $this->actingAs($this->customer, 'customer');
    $code = webPayoutOtp($this);

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'bml',
        'account_no' => '7701234567890',
        'account_name' => 'AISHATH MANIKE',
        'otp_code' => $code,
    ])
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', true)
        // Snapshot semantics: a change during a processing batch takes
        // effect from the NEXT batch (payout_items snapshot at build time).
        ->assertJsonPath('data.change_effective', 'next_batch');

    $this->getJson('/api/customer/payout-account')
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'bml')
        ->assertJsonPath('data.account_no', '7701234567890')
        ->assertJsonPath('data.account_name', 'AISHATH MANIKE');

    $this->customer->refresh();
    expect($this->customer->payout_bank)->toBe('bml');
    expect($this->customer->payout_account)->toBe('7701234567890');
    expect($this->customer->payout_account_name)->toBe('AISHATH MANIKE');
});

it('allows updating an existing account with a fresh code (effective next batch)', function () {
    $this->customer->forceFill([
        'payout_bank' => 'bml',
        'payout_account' => '7701234567890',
        'payout_account_name' => 'AISHATH MANIKE',
    ])->save();

    $this->actingAs($this->customer, 'customer');
    $code = webPayoutOtp($this);

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'mib',
        'account_no' => '990001112223',
        'account_name' => 'AISHATH MANIKE',
        'otp_code' => $code,
    ])
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'mib');
});

it('validates the payload', function () {
    $this->actingAs($this->customer, 'customer');
    $code = webPayoutOtp($this);

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'bml',
        'account_name' => 'AISHATH MANIKE',
        'otp_code' => $code,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_no');

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'bml',
        'account_no' => 'not-a-number',
        'account_name' => 'AISHATH MANIKE',
        'otp_code' => $code,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_no');

    $this->postJson('/api/customer/payout-account', [
        'account_no' => '7701234567890',
        'account_name' => 'AISHATH MANIKE',
        'otp_code' => $code,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_name');
});

it('requires customer auth', function () {
    $this->getJson('/api/customer/payout-account')->assertUnauthorized();
    $this->postJson('/api/customer/payout-account', [])->assertUnauthorized();
    $this->postJson('/api/customer/payout-account/otp')->assertUnauthorized();
});
