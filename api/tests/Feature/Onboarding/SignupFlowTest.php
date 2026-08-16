<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\Merchant;
use App\Models\MerchantOtpCode;
use App\Models\MerchantUser;
use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Simulate a first-party frontend so Sanctum's stateful pipeline runs.
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

/** Pulls the 6-digit code out of the last captured SMS for a phone. */
function lastMerchantOtpFor(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

/** Runs request-otp + verify-otp and returns the signup token. */
function merchantSignupToken(string $phone = '+9607712345'): string
{
    test()->postJson('/api/merchant/signup/request-otp', ['phone' => $phone])->assertOk();

    return test()->postJson('/api/merchant/signup/verify-otp', [
        'phone' => $phone,
        'code' => lastMerchantOtpFor(test()->sms, $phone),
    ])->assertOk()->json('data.signup_token');
}

it('signs a store up end to end: draft merchant, owner account, logged-in session, publicly invisible', function () {
    $token = merchantSignupToken('+9607712345');

    $response = $this->postJson('/api/merchant/signup/register', [
        'signup_token' => $token,
        'business_name' => 'Fresh Mart',
        'email' => 'owner@freshmart.mv',
        'password' => 'a-strong-password',
    ])->assertCreated();

    $response->assertJsonPath('data.role.is_owner', true)
        ->assertJsonPath('data.merchant.name', 'Fresh Mart')
        ->assertJsonPath('data.merchant.status', 'draft');

    $merchant = Merchant::query()->where('name', 'Fresh Mart')->firstOrFail();
    expect($merchant->status)->toBe('draft')
        ->and($merchant->slug)->toBe('fresh-mart')
        ->and($merchant->channel)->toBe('in_store')
        ->and($merchant->contact_phone)->toBe('+9607712345')
        ->and($merchant->contact_email)->toBe('owner@freshmart.mv');

    $owner = MerchantUser::query()->where('email', 'owner@freshmart.mv')->firstOrFail();
    expect($owner->isOwner())->toBeTrue()
        ->and($owner->is_active)->toBeTrue()
        ->and($owner->merchant_id)->toBe($merchant->id);

    // The register response logged the owner in on the merchant guard.
    $this->getJson('/api/merchant/auth/me')->assertOk()
        ->assertJsonPath('data.merchant.status', 'draft');

    // A draft store is invisible everywhere public.
    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);
    $this->getJson('/api/discover/merchants/fresh-mart')->assertNotFound();

    // The token is single-use.
    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => $token,
        'business_name' => 'Copy Mart',
        'email' => 'copy@freshmart.mv',
        'password' => 'a-strong-password',
    ])->assertUnprocessable()->assertJsonValidationErrors(['signup_token']);
});

it('uniquifies the slug when the business name collides', function () {
    Merchant::factory()->create(['slug' => 'fresh-mart']);

    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => merchantSignupToken(),
        'business_name' => 'Fresh Mart',
        'email' => 'second@freshmart.mv',
        'password' => 'a-strong-password',
    ])->assertCreated();

    expect(Merchant::query()->where('name', 'Fresh Mart')->firstOrFail()->slug)->toBe('fresh-mart-2');
});

it('slugs a fully Thaana business name to the store fallback', function () {
    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => merchantSignupToken(),
        'business_name' => 'ފިހާރަ ރީތި',
        'email' => 'thaana@store.mv',
        'password' => 'a-strong-password',
    ])->assertCreated();

    expect(Merchant::query()->where('name', 'ފިހާރަ ރީތި')->firstOrFail()->slug)->toBe('store');
});

it('refuses an email already used by any merchant panel account', function () {
    MerchantUser::factory()->create(['email' => 'taken@store.mv']);

    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => merchantSignupToken(),
        'business_name' => 'Fresh Mart',
        'email' => 'taken@store.mv',
        'password' => 'a-strong-password',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

    expect(Merchant::query()->where('name', 'Fresh Mart')->exists())->toBeFalse();
});

it('answers request-otp identically for fresh and already-registered phones — no enumeration oracle', function () {
    Merchant::factory()->create(['contact_phone' => '+9607712345']);

    $known = $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();
    $fresh = $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9609998877'])->assertOk();

    expect($known->getContent())->toBe($fresh->getContent());
});

it('throttles request-otp at 3 per hour per phone', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();
    }

    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607712345'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    expect(count($this->sms->sent))->toBe(3);
});

it('throttles request-otp at 10 per hour per IP across phones', function () {
    foreach (range(0, 9) as $i) {
        $this->postJson('/api/merchant/signup/request-otp', ['phone' => sprintf('+96077123%02d', $i)])->assertOk();
    }

    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9609990000'])
        ->assertStatus(429);
});

it('locks a code after 5 wrong attempts — even the correct code is then refused', function () {
    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = lastMerchantOtpFor($this->sms, '+9607712345');
    $wrong = $code === '000000' ? '000001' : '000000';

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/merchant/signup/verify-otp', ['phone' => '+9607712345', 'code' => $wrong])
            ->assertUnprocessable();
    }

    $this->postJson('/api/merchant/signup/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('rejects non-Maldivian and malformed phone numbers', function () {
    // '7712345' is deliberately absent: seven local digits is now the shape
    // the form asks for, and the controller folds it to E.164 before the
    // rule runs. 5 is not a Maldivian mobile prefix; the rest are the wrong
    // length or not a number at all.
    foreach (['+9605551234', '5551234', '+960771234', '+96077123456', 'not-a-phone'] as $phone) {
        $this->postJson('/api/merchant/signup/request-otp', ['phone' => $phone])->assertUnprocessable();
    }

    expect($this->sms->sent)->toBe([]);
});

it('takes seven local digits at merchant signup too', function () {
    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '7712345'])
        ->assertOk();

    // The gateway is handed E.164 whatever the form sent.
    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe('+9607712345');
});

it('keeps merchant and customer OTP storage fully separate', function () {
    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();

    expect(MerchantOtpCode::query()->count())->toBe(1)
        ->and(OtpCode::query()->count())->toBe(0);

    // A merchant-issued code never verifies on the customer flow.
    $code = lastMerchantOtpFor($this->sms, '+9607712345');
    $this->postJson('/api/customer/auth/verify-otp', ['phone' => '+9607712345', 'code' => $code])
        ->assertUnprocessable();
});
