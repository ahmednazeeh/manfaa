<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\Merchant;
use App\Models\MerchantUser;
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

function closureOtpFor(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

it('closes a settled store end to end and shuts every staff door', function () {
    $store = Merchant::factory()->create(['contact_phone' => '+9609990001']);
    $owner = MerchantUser::factory()->for($store)->owner()->create();
    $owner->createToken('phone', ['mobile:merchant']);

    $this->postJson('/api/merchant/account-closure/request-otp', ['phone' => '+9609990001'])
        ->assertOk();

    $verified = $this->postJson('/api/merchant/account-closure/verify', [
        'phone' => '+9609990001',
        'code' => closureOtpFor($this->sms, '+9609990001'),
    ])->assertOk()->json('data');

    expect($verified['stores'][0])->toMatchArray([
        'id' => $store->id,
        'outstanding_laari' => 0,
        'can_close' => true,
    ]);

    $this->postJson('/api/merchant/account-closure/confirm', [
        'closure_token' => $verified['closure_token'],
        'merchant_id' => $store->id,
    ])->assertOk();

    expect($store->refresh()->status)->toBe('closed')
        ->and($owner->refresh()->is_active)->toBeFalse()
        ->and($owner->tokens()->count())->toBe(0);
});

it('refuses to close a store that still owes money — settling stays open, closing waits', function () {
    $store = Merchant::factory()->create(['contact_phone' => '+9609990002']);
    MerchantUser::factory()->for($store)->owner()->create();
    Transaction::factory()->create([
        'merchant_id' => $store->id,
        'state' => 'payable_unfunded',
        'cashback_laari' => 2000,
        'fee_laari' => 750,
    ]);

    $this->postJson('/api/merchant/account-closure/request-otp', ['phone' => '+9609990002'])->assertOk();

    $verified = $this->postJson('/api/merchant/account-closure/verify', [
        'phone' => '+9609990002',
        'code' => closureOtpFor($this->sms, '+9609990002'),
    ])->assertOk()->json('data');

    expect($verified['stores'][0]['can_close'])->toBeFalse()
        ->and($verified['stores'][0]['outstanding_laari'])->toBeGreaterThan(0);

    $this->postJson('/api/merchant/account-closure/confirm', [
        'closure_token' => $verified['closure_token'],
        'merchant_id' => $store->id,
    ])->assertUnprocessable()->assertJsonPath('errors.merchant_id.0', 'outstanding_balance');

    expect($store->refresh()->status)->not->toBe('closed');
});

it('names no_store only after OTP proof', function () {
    $this->postJson('/api/merchant/account-closure/request-otp', ['phone' => '+9609990003'])->assertOk();

    $this->postJson('/api/merchant/account-closure/verify', [
        'phone' => '+9609990003',
        'code' => closureOtpFor($this->sms, '+9609990003'),
    ])->assertUnprocessable()->assertJsonPath('errors.phone.0', 'no_store');
});
