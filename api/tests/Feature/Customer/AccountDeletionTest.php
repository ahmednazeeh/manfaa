<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');

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

function deletionOtpFor(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

it('deletes an account end to end: OTP proof, balance warning, anonymised row, surviving ledger', function () {
    $customer = Customer::factory()->create([
        'phone' => '+9607712345',
        'name' => 'Aishath Manike',
        'email' => 'aishath@example.mv',
        'payout_bank' => 'bml',
        'payout_account' => '7770000001234',
        'payout_account_name' => 'Aishath Manike',
    ]);
    $merchant = Merchant::factory()->create();
    $transaction = Transaction::factory()->create([
        'customer_id' => $customer->id,
        'merchant_id' => $merchant->id,
    ]);
    $customer->createToken('phone', ['mobile:customer']);

    $this->postJson('/api/customer/account-deletion/request-otp', ['phone' => '+9607712345'])
        ->assertOk();

    $verified = $this->postJson('/api/customer/account-deletion/verify', [
        'phone' => '+9607712345',
        'code' => deletionOtpFor($this->sms, '+9607712345'),
    ])->assertOk()->json('data');

    // The confirm screen can tell the member exactly what lapses.
    expect($verified['name'])->toBe('Aishath Manike')
        ->and($verified)->toHaveKeys(['deletion_token', 'confirmed_laari', 'pending_laari']);

    $this->postJson('/api/customer/account-deletion/confirm', [
        'deletion_token' => $verified['deletion_token'],
    ])->assertOk();

    $customer->refresh();

    // Identity unlinked…
    expect($customer->name)->toBe('Deleted member')
        ->and($customer->phone)->toBe('del:'.$customer->id)
        ->and($customer->email)->toBeNull()
        ->and($customer->status)->toBe('closed')
        ->and($customer->payout_bank)->toBeNull()
        ->and($customer->payout_account)->toBeNull()
        ->and($customer->tokens()->count())->toBe(0);

    // …but the ledger survives, still pointing at the anonymised row.
    expect(Transaction::query()->whereKey($transaction->id)->where('customer_id', $customer->id)->exists())
        ->toBeTrue();

    // The token is single-use.
    $this->postJson('/api/customer/account-deletion/confirm', [
        'deletion_token' => $verified['deletion_token'],
    ])->assertUnprocessable();
});

it('names the truth only after OTP proof: unknown phone gets no_account', function () {
    $this->postJson('/api/customer/account-deletion/request-otp', ['phone' => '+9607770009'])
        ->assertOk();

    $this->postJson('/api/customer/account-deletion/verify', [
        'phone' => '+9607770009',
        'code' => deletionOtpFor($this->sms, '+9607770009'),
    ])->assertUnprocessable()->assertJsonPath('errors.phone.0', 'no_account');
});

it('refuses a wrong code without leaking anything', function () {
    Customer::factory()->create(['phone' => '+9607712345']);

    $this->postJson('/api/customer/account-deletion/request-otp', ['phone' => '+9607712345'])
        ->assertOk();

    $this->postJson('/api/customer/account-deletion/verify', [
        'phone' => '+9607712345',
        'code' => '000000',
    ])->assertUnprocessable()->assertJsonPath('errors.code.0', 'otp_invalid');
});

it('frees the phone: a deleted number can register a brand-new account', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);

    $this->postJson('/api/customer/account-deletion/request-otp', ['phone' => '+9607712345'])->assertOk();
    $token = $this->postJson('/api/customer/account-deletion/verify', [
        'phone' => '+9607712345',
        'code' => deletionOtpFor($this->sms, '+9607712345'),
    ])->assertOk()->json('data.deletion_token');
    $this->postJson('/api/customer/account-deletion/confirm', ['deletion_token' => $token])->assertOk();

    // Full fresh signup on the freed number.
    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();
    $signup = $this->postJson('/api/customer/auth/verify-otp', [
        'phone' => '+9607712345',
        'code' => deletionOtpFor($this->sms, '+9607712345'),
    ])->assertOk()->json('data');

    // The old account is closed, so the number reads as fresh.
    expect($signup['already_registered'])->toBeFalse();

    $this->postJson('/api/customer/auth/register', [
        'signup_token' => $signup['signup_token'],
        'phone' => '+9607712345',
        'name' => 'Aishath Returns',
        'password' => 'secret-123',
    ])->assertCreated();

    expect(Customer::query()->where('phone', '+9607712345')->count())->toBe(1)
        ->and($customer->fresh()->phone)->toBe('del:'.$customer->id);
});

it('tells an already-registered member so at the CODE step of signup', function () {
    Customer::factory()->create(['phone' => '+9607712345']);

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();

    expect($this->postJson('/api/customer/auth/verify-otp', [
        'phone' => '+9607712345',
        'code' => deletionOtpFor($this->sms, '+9607712345'),
    ])->assertOk()->json('data.already_registered'))->toBeTrue();
});
