<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Domain\Platform\PlatformConfig;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The validation window at SIGNUP (owner, 2026-08-25): both doors — the
 * website's register and the till app's — let a store choose how long it
 * holds a sale before its cashback validates, and both validate against the
 * SAME admin-governed ceiling the preferences screen already enforces.
 *
 * The property that matters is not "signup validates the field". It is that
 * a merchant can never be signed up on a window the preferences screen
 * would then turn round and refuse — which is why every assertion here that
 * accepts a value at signup goes on to prove the preferences PATCH accepts
 * it too, and why the ceiling is read from platform settings at request
 * time rather than copied into either door.
 */
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

/** The 6-digit code out of the last SMS captured for a phone. */
function windowOtpFor(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

/** A verified signup token from the WEB door. */
function webSignupToken(string $phone): string
{
    test()->postJson('/api/merchant/signup/request-otp', ['phone' => $phone])->assertOk();

    return test()->postJson('/api/merchant/signup/verify-otp', [
        'phone' => $phone,
        'code' => windowOtpFor(test()->sms, $phone),
    ])->assertOk()->json('data.signup_token');
}

/** A verified signup token from the MOBILE door. */
function appSignupToken(string $phone): string
{
    test()->postJson('/api/mobile/v1/merchant/signup/request-otp', ['phone' => $phone])->assertOk();

    return test()->postJson('/api/mobile/v1/merchant/signup/verify-otp', [
        'phone' => $phone,
        'code' => windowOtpFor(test()->sms, $phone),
    ])->assertOk()->json('data.signup_token');
}

/**
 * Registers through the web door. $window === false omits the field
 * entirely — the "said nothing" case, which must behave exactly as it did
 * before the field existed.
 */
function registerOnWeb(string $phone, string $email, int|string|float|null|false $window = false): TestResponse
{
    $payload = [
        'signup_token' => webSignupToken($phone),
        'business_name' => 'Store '.$email,
        'email' => $email,
        'password' => 'a-strong-password',
    ];

    if ($window !== false) {
        $payload['validation_window_days'] = $window;
    }

    return test()->postJson('/api/merchant/signup/register', $payload);
}

/** The same, through the till app's door (which mints a token, not a session). */
function registerOnApp(string $phone, string $email, int|string|float|null|false $window = false): TestResponse
{
    $payload = [
        'signup_token' => appSignupToken($phone),
        'business_name' => 'App store '.$email,
        'email' => $email,
        'password' => 'a-strong-password',
        'device_name' => 'Counter tablet',
    ];

    if ($window !== false) {
        $payload['validation_window_days'] = $window;
    }

    return test()->postJson('/api/mobile/v1/merchant/signup/register', $payload);
}

/** The store an email now owns. */
function storeFor(string $email): Merchant
{
    return MerchantUser::query()->where('email', $email)->firstOrFail()->merchant;
}

it('publishes the allowed range on BOTH signup doors before anything is submitted', function () {
    // The default platform posture: ceiling 3, new stores start on 2.
    $expected = ['min_days' => 0, 'max_days' => 3, 'default_days' => 2];

    foreach (['/api/merchant/signup/options', '/api/mobile/v1/merchant/signup/options'] as $url) {
        $response = $this->getJson($url)->assertOk();

        $response->assertJsonPath('data.validation_window.min_days', $expected['min_days'])
            ->assertJsonPath('data.validation_window.max_days', $expected['max_days'])
            ->assertJsonPath('data.validation_window.default_days', $expected['default_days']);

        // Merchant-facing copy travels in both languages, and the numbers
        // inside it are the REAL bounds, not prose that goes stale.
        $window = $response->json('data.validation_window');

        expect($window['label_en'])->not->toBeEmpty()
            ->and($window['label_dv'])->not->toBeEmpty()
            ->and($window['help_en'])->toContain('0 and 3 days')
            ->and($window['help_dv'])->toContain('0')
            ->and($window['help_dv'])->toContain('3')
            // Real Dhivehi, not English sitting in a dv key.
            ->and(preg_match('/[\x{0780}-\x{07BF}]/u', $window['help_dv']))->toBe(1)
            ->and(preg_match('/[\x{0780}-\x{07BF}]/u', $window['invalid_dv']))->toBe(1);
    }

    // No auth of any kind: a form must be able to read its own limit before
    // the person filling it in has an account.
    $this->getJson('/api/merchant/signup/options')->assertOk();

    // Move the platform ceiling and BOTH doors publish the new one on the
    // next read — no deploy, and no chance of the two disagreeing.
    app(PlatformConfig::class)->set('default_validation_window_days', 7);

    $this->getJson('/api/merchant/signup/options')
        ->assertOk()
        ->assertJsonPath('data.validation_window.max_days', 7);

    $this->getJson('/api/mobile/v1/merchant/signup/options')
        ->assertOk()
        ->assertJsonPath('data.validation_window.max_days', 7);
});

it('takes the floor and the ceiling at the web signup, and refuses either side of them', function () {
    // The FLOOR — immediate validation.
    registerOnWeb('+9607712301', 'floor@web.mv', 0)->assertCreated();
    expect(storeFor('floor@web.mv')->validation_window_days)->toBe(0);

    // The CEILING — the admin-governed maximum, today 3.
    registerOnWeb('+9607712302', 'ceiling@web.mv', 3)->assertCreated();
    expect(storeFor('ceiling@web.mv')->validation_window_days)->toBe(3);

    // One past the ceiling, and the message names the whole allowed range.
    $refusal = registerOnWeb('+9607712303', 'over@web.mv', 4)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['validation_window_days']);

    expect($refusal->json('errors.validation_window_days.0'))
        ->toBe('The validation window must be a whole number of days between 0 and 3.');

    // Below the floor.
    registerOnWeb('+9607712304', 'under@web.mv', -1)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['validation_window_days']);

    // Days are whole days.
    registerOnWeb('+9607712305', 'half@web.mv', 2.5)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['validation_window_days']);

    // Neither refusal created anything.
    expect(MerchantUser::query()->whereIn('email', ['over@web.mv', 'under@web.mv', 'half@web.mv'])->count())->toBe(0);

    // OMITTED — today's default, unchanged. This is the behaviour every
    // store had before the field existed and it must not have moved.
    registerOnWeb('+9607712306', 'quiet@web.mv')->assertCreated();
    expect(storeFor('quiet@web.mv')->validation_window_days)
        ->toBe(app(PlatformConfig::class)->newMerchantValidationWindowDays())
        ->toBe(2);
});

it('applies the SAME rule at the till app signup', function () {
    registerOnApp('+9607712311', 'floor@app.mv', 0)->assertCreated();
    expect(storeFor('floor@app.mv')->validation_window_days)->toBe(0);

    registerOnApp('+9607712312', 'ceiling@app.mv', 3)->assertCreated();
    expect(storeFor('ceiling@app.mv')->validation_window_days)->toBe(3);

    $refusal = registerOnApp('+9607712313', 'over@app.mv', 4)->assertUnprocessable();

    // The mobile envelope carries field errors as validation_failed with
    // the fields under error.meta; the sentence a merchant reads is the web
    // door's, word for word.
    expect($refusal->json('error.code'))->toBe('validation_failed')
        ->and($refusal->json('error.meta.fields.validation_window_days.0'))
        ->toBe('The validation window must be a whole number of days between 0 and 3.');

    registerOnApp('+9607712314', 'under@app.mv', -1)->assertUnprocessable();
    registerOnApp('+9607712315', 'half@app.mv', 2.5)->assertUnprocessable();

    expect(MerchantUser::query()->whereIn('email', ['over@app.mv', 'under@app.mv', 'half@app.mv'])->count())->toBe(0);

    registerOnApp('+9607712316', 'quiet@app.mv')->assertCreated();
    expect(storeFor('quiet@app.mv')->validation_window_days)->toBe(2);
});

it('follows the platform ceiling on both doors when an admin moves it', function () {
    // Lowered: what was legal this morning is refused this afternoon, on
    // BOTH doors, with no deploy in between.
    app(PlatformConfig::class)->set('default_validation_window_days', 1);

    registerOnWeb('+9607712321', 'tight@web.mv', 3)
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.validation_window_days.0',
            'The validation window must be a whole number of days between 0 and 1.',
        );

    registerOnApp('+9607712322', 'tight@app.mv', 3)->assertUnprocessable();

    registerOnWeb('+9607712323', 'ok@web.mv', 1)->assertCreated();
    expect(storeFor('ok@web.mv')->validation_window_days)->toBe(1);

    // Raised: the doors offer the wider range immediately.
    app(PlatformConfig::class)->set('default_validation_window_days', 10);

    registerOnWeb('+9607712324', 'wide@web.mv', 10)->assertCreated();
    expect(storeFor('wide@web.mv')->validation_window_days)->toBe(10);

    registerOnApp('+9607712325', 'wide@app.mv', 10)->assertCreated();
    expect(storeFor('wide@app.mv')->validation_window_days)->toBe(10);

    registerOnWeb('+9607712326', 'too-wide@web.mv', 11)->assertUnprocessable();
});

it('never signs a store up on a window the preferences screen would then refuse', function () {
    // Sign up at the ceiling…
    registerOnWeb('+9607712331', 'parity@web.mv', 3)->assertCreated();

    $merchant = storeFor('parity@web.mv');
    $owner = MerchantUser::query()->where('email', 'parity@web.mv')->firstOrFail();

    $this->actingAs($owner, 'merchant');

    // …and the preferences screen — the other side of the same rule —
    // accepts that very window being saved again, and reports the same
    // ceiling the signup form was shown.
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 3])
        ->assertOk()
        ->assertJsonPath('data.validation_window_days', 3)
        ->assertJsonPath('data.validation_window_max_days', 3);

    // And the refusal is the same sentence on both surfaces, because it is
    // the same rule object.
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 4])
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.validation_window_days.0',
            'The validation window must be a whole number of days between 0 and 3.',
        );

    expect($merchant->refresh()->validation_window_days)->toBe(3);
});
