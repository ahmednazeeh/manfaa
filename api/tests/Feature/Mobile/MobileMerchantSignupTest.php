<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Onboarding\MerchantLogo;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merchant app MR1 — signup and the setup wizard on the mobile surface:
 * phone → OTP → register mints a WORKING merchant token (no session on a
 * phone), and the wizard runs on that token through the same SetupController
 * and permission gates as the web, refusals in the mobile envelope.
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
});

/** Pulls the 6-digit code out of the last captured SMS for a phone. */
function mobileMerchantOtpFor(object $sms, string $phone): string
{
    $messages = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
    expect($messages)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

/** Runs mobile request-otp + verify-otp and returns the signup token. */
function mobileMerchantSignupToken(string $phone = '+9607712345'): string
{
    test()->postJson('/api/mobile/v1/merchant/signup/request-otp', ['phone' => $phone])
        ->assertOk()
        ->assertJsonPath('data.sent', true);

    return test()->postJson('/api/mobile/v1/merchant/signup/verify-otp', [
        'phone' => $phone,
        'code' => mobileMerchantOtpFor(test()->sms, $phone),
    ])->assertOk()->json('data.signup_token');
}

/** Bearer headers for a merchant user, minted straight from the service. */
function mobileMerchantAuth(MerchantUser $user): array
{
    $token = app(MobileTokenService::class)
        ->issue($user, MobileAudience::Merchant, 'Counter tablet')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/** A blank draft store with an owner — the state right after self-signup. */
function mobileWizardOwner(array $merchantAttributes = []): MerchantUser
{
    $merchant = Merchant::factory()->draft()->create([
        'category' => null,
        'eligibility_basis' => null,
        'channel' => 'in_store',
        'setup_state' => [],
        ...$merchantAttributes,
    ]);

    return MerchantUser::factory()->for($merchant)->owner()->create();
}

/*
|--------------------------------------------------------------------------
| Signup: phone → OTP → register mints a working token
|--------------------------------------------------------------------------
*/

it('signs a store up from the app: OTP over SMS, register mints a token that works, store is draft', function () {
    $token = mobileMerchantSignupToken('+9607712345');

    $response = $this->postJson('/api/mobile/v1/merchant/signup/register', [
        'signup_token' => $token,
        'business_name' => 'Fresh Mart',
        'email' => 'owner@freshmart.mv',
        'password' => 'a-strong-password',
        'device_name' => "Owner's iPhone",
    ])->assertCreated();

    // The exact signed-in shape /merchant/auth/token answers — one parser
    // in the app for both doors.
    $data = $response->json('data');
    expect($data['token'])->toBeString()->not->toBeEmpty()
        ->and($data['expires_at'])->toBeString()
        ->and($data['device_name'])->toBe("Owner's iPhone")
        ->and($data['user']['email'])->toBe('owner@freshmart.mv')
        ->and($data['merchant']['slug'])->toBe('fresh-mart')
        ->and($data['merchant']['name'])->toBe('Fresh Mart')
        ->and($data['permissions'])->toContain('setup.view', 'setup.edit', 'setup.submit');

    $merchant = Merchant::query()->where('slug', 'fresh-mart')->firstOrFail();
    expect($merchant->status)->toBe('draft')
        ->and($merchant->contact_phone)->toBe('+9607712345');

    // The minted token WORKS: me answers fresh identity with draft status…
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => 'Bearer '.$data['token']])
        ->getJson('/api/mobile/v1/merchant/me')
        ->assertOk()
        ->assertJsonPath('data.merchant.status', 'draft')
        ->assertJsonPath('data.user.email', 'owner@freshmart.mv');

    // …and reaches the wizard, which starts blank.
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => 'Bearer '.$data['token']])
        ->getJson('/api/mobile/v1/merchant/setup')
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.steps.profile', false);

    // The signup token was single-use.
    $second = $this->postJson('/api/mobile/v1/merchant/signup/register', [
        'signup_token' => $token,
        'business_name' => 'Copy Mart',
        'email' => 'copy@freshmart.mv',
        'password' => 'a-strong-password',
        'device_name' => 'Another phone',
    ])->assertStatus(422);
    expect($second->json('error.code'))->toBe('signup_token_invalid');
});

it('refuses a wrong code with the enveloped otp_invalid, and kills the code after five', function () {
    $this->postJson('/api/mobile/v1/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();
    $code = mobileMerchantOtpFor($this->sms, '+9607712345');
    $wrong = $code === '000000' ? '000001' : '000000';

    $refused = $this->postJson('/api/mobile/v1/merchant/signup/verify-otp', [
        'phone' => '+9607712345',
        'code' => $wrong,
    ])->assertStatus(422);

    // The full envelope contract: code is the machine key, message is prose.
    expect($refused->json('error.code'))->toBe('otp_invalid')
        ->and($refused->json('error.message'))->toBeString()->not->toContain('_');

    foreach (range(1, 4) as $i) {
        $this->postJson('/api/mobile/v1/merchant/signup/verify-otp', [
            'phone' => '+9607712345',
            'code' => $wrong,
        ])->assertStatus(422);
    }

    // Even the correct code is dead now.
    $exhausted = $this->postJson('/api/mobile/v1/merchant/signup/verify-otp', [
        'phone' => '+9607712345',
        'code' => $code,
    ])->assertStatus(422);
    expect($exhausted->json('error.code'))->toBe('otp_attempts_exceeded');
});

it('refuses an already-registered email at register, keeping the store uncreated', function () {
    MerchantUser::factory()->create(['email' => 'taken@store.mv']);

    $response = $this->postJson('/api/mobile/v1/merchant/signup/register', [
        'signup_token' => mobileMerchantSignupToken(),
        'business_name' => 'Fresh Mart',
        'email' => 'taken@store.mv',
        'password' => 'a-strong-password',
        'device_name' => 'Phone',
    ])->assertStatus(422);

    expect($response->json('error.code'))->toBe('email_already_registered')
        ->and(Merchant::query()->where('name', 'Fresh Mart')->exists())->toBeFalse();
});

it('requires device_name at register — the mobile difference from the web flow', function () {
    $response = $this->postJson('/api/mobile/v1/merchant/signup/register', [
        'signup_token' => mobileMerchantSignupToken(),
        'business_name' => 'Fresh Mart',
        'email' => 'owner@freshmart.mv',
        'password' => 'a-strong-password',
    ])->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.meta.fields'))->toHaveKey('device_name');
});

it('answers request-otp identically for fresh and already-registered phones', function () {
    Merchant::factory()->create(['contact_phone' => '+9607712345']);

    $known = $this->postJson('/api/mobile/v1/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();
    $fresh = $this->postJson('/api/mobile/v1/merchant/signup/request-otp', ['phone' => '+9609998877'])->assertOk();

    expect($known->getContent())->toBe($fresh->getContent());
});

it('shares the SMS budget with the WEB signup — alternating surfaces buys nothing', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607712345'])->assertOk();
    }

    $refused = $this->postJson('/api/mobile/v1/merchant/signup/request-otp', ['phone' => '+9607712345'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    expect($refused->json('error.code'))->toBe('rate_limited')
        ->and($refused->json('error.meta.retry_after_seconds'))->toBeInt()
        ->and(count($this->sms->sent))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Setup wizard on the token
|--------------------------------------------------------------------------
*/

it('walks the wizard on the token: profile, location, rate, submit → pending_review', function () {
    $owner = mobileWizardOwner(['name' => 'Reef Mart']);
    $headers = mobileMerchantAuth($owner);

    // Fresh state with the pieces the app renders from.
    app('auth')->forgetGuards();
    $state = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/setup')
        ->assertOk()->json('data');
    expect($state['status'])->toBe('draft')
        ->and($state['steps'])->toBe(['profile' => false, 'location' => false, 'logo' => false, 'rate' => false])
        ->and(collect($state['categories'])->pluck('slug'))->toContain('grocery')
        ->and($state['rate_bounds'])->toHaveKeys(['min_percent', 'max_percent']);

    // Submitting too early names what is missing, through the envelope.
    app('auth')->forgetGuards();
    $early = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/setup/submit')
        ->assertStatus(422);
    expect($early->json('error.code'))->toBe('setup_incomplete')
        ->and($early->json('error.meta.missing'))->toBe(['category', 'rate', 'terms']);

    // Profile.
    app('auth')->forgetGuards();
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/setup/profile', [
            'category' => 'grocery',
            'channel' => 'both',
            'eligibility_basis' => 'Full invoice total excluding delivery.',
        ])->assertOk()
        ->assertJsonPath('data.steps.profile', true)
        ->assertJsonPath('data.values.category', 'grocery');

    // Location — the primary branch pin.
    app('auth')->forgetGuards();
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/setup/location', ['lat' => 4.1755, 'lng' => 73.5093])
        ->assertOk()
        ->assertJsonPath('data.steps.location', true)
        ->assertJsonPath('data.values.primary_branch.name', 'Reef Mart')
        ->assertJsonPath('data.values.primary_branch.lat', 4.1755);

    // Rate.
    app('auth')->forgetGuards();
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/setup/rate', ['cashback_rate_percent' => '2.00'])
        ->assertOk()
        ->assertJsonPath('data.steps.rate', true)
        ->assertJsonPath('data.values.cashback_rate_percent', '2.00');

    expect(MerchantRate::query()->where('merchant_id', $owner->merchant->id)->count())->toBe(1);

    // Submit: draft → pending_review.
    app('auth')->forgetGuards();
    $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/setup/submit')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    // Post-submit writes 409 setup_not_editable through the envelope…
    app('auth')->forgetGuards();
    $locked = $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/setup/profile', ['category' => 'cafe'])
        ->assertStatus(409);
    expect($locked->json('error.code'))->toBe('setup_not_editable');

    // …while the read stays open: the waiting screen renders from it.
    app('auth')->forgetGuards();
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/setup')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_review');
});

it('stores a logo over multipart on the token, and refuses svg and oversize uploads', function () {
    Storage::fake(MerchantLogo::DISK);

    $owner = mobileWizardOwner();
    $headers = [...mobileMerchantAuth($owner), 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/setup/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ])->assertOk()
        ->assertJsonStructure(['data' => ['logo_url']]);

    $merchant = $owner->merchant->refresh();
    expect($merchant->logo_path)->toStartWith('merchants/'.$merchant->id.'/');
    Storage::disk(MerchantLogo::DISK)->assertExists($merchant->logo_path);

    // Scriptable content is refused outright, in the envelope.
    app('auth')->forgetGuards();
    $svg = $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/setup/logo', [
            'logo' => UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            ),
        ])->assertStatus(422);
    expect($svg->json('error.code'))->toBe('validation_failed')
        ->and($svg->json('error.meta.fields'))->toHaveKey('logo');

    // So is anything over the 2MB cap.
    app('auth')->forgetGuards();
    $big = $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/setup/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400)->size(3000),
        ])->assertStatus(422);
    expect($big->json('error.meta.fields'))->toHaveKey('logo');

    // The happy upload is still the only file on disk.
    expect(Storage::disk(MerchantLogo::DISK)->allFiles())->toHaveCount(1);
});

it('refuses a staff token on the wizard with the enveloped permission refusal', function () {
    $owner = mobileWizardOwner();
    $staff = MerchantUser::factory()->for($owner->merchant)->staff()->create();

    $refused = $this->withHeaders(mobileMerchantAuth($staff))
        ->getJson('/api/mobile/v1/merchant/setup')
        ->assertForbidden();

    expect($refused->json('error.code'))->toBe('permission_required')
        ->and($refused->json('error.meta.permission'))->toBe('setup.view');

    app('auth')->forgetGuards();
    $this->withHeaders(mobileMerchantAuth($staff))
        ->patchJson('/api/mobile/v1/merchant/setup/profile', ['category' => 'grocery'])
        ->assertForbidden();
});

it('requires a merchant token for every setup route — enveloped 401 without one', function () {
    $bare = $this->getJson('/api/mobile/v1/merchant/setup')->assertUnauthorized();
    expect($bare->json('error.code'))->toBe('unauthenticated');

    $this->postJson('/api/mobile/v1/merchant/setup/submit')->assertUnauthorized();

    // A CUSTOMER token is not a merchant token, whatever ids collide.
    $customerToken = app(MobileTokenService::class)
        ->issue(Customer::factory()->create(), MobileAudience::Customer, 'Phone')
        ->plainTextToken;

    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => 'Bearer '.$customerToken])
        ->getJson('/api/mobile/v1/merchant/setup')
        ->assertUnauthorized();
});
