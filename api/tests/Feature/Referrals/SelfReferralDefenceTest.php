<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Referrals\DeviceIdentity;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Models\DeviceToken;
use App\Models\OtpCode;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Self-referral defence (owner, 2026-08-24 — NO TOLERANCE): every surface
 * feeds a hashed device identity into customer_devices — the app via the
 * X-Device-Id header, the web via the long-lived mfa_did browser cookie —
 * and a referral whose referrer and referred have EVER shared a device (or
 * currently share an FCM token) is disqualified at award time: stamped,
 * never paid, never retried.
 *
 * Reuses the ReferralTest helpers (signupTokenFor, referredFriend,
 * pendingSpend, walletBalance, referralEntries) — Pest loads every suite
 * file before any test runs, so the globals are shared on purpose.
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    // Simulate a first-party frontend so the web flows run the stateful
    // pipeline (cookies included) exactly as the real page does.
    $this->withHeader('Referer', 'http://localhost');

    $this->referrer = Customer::factory()->create([
        'customer_code' => '482917',
        'name' => 'Hassan Rasheed',
    ]);
});

/** Both customers seen on one handset, as the app middleware records it. */
function sharedHandset(Customer $a, Customer $b, string $raw = 'ssaid-shared-handset'): void
{
    app(DeviceIdentity::class)->record($a, $raw, 'android');
    app(DeviceIdentity::class)->record($b, $raw, 'android');
}

/** The decoded app key, exactly as Laravel's encrypter reads it. */
function decodedAppKey(): string
{
    $key = (string) config('app.key');

    return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
}

// --------------------------------------------------------- app ingestion

it('records a hashed device identity from the mobile header, never the raw id', function () {
    $auth = app(MobileTokenService::class)
        ->issue($this->referrer, MobileAudience::Customer, 'Phone')
        ->plainTextToken;

    $headers = [
        'Authorization' => 'Bearer '.$auth,
        'X-Device-Id' => 'ssaid-1234567890abcdef',
        'X-Device-Platform' => 'android',
    ];

    $this->withHeaders($headers)->getJson('/api/mobile/v1/customer/me')->assertOk();
    // A repeat request lands on the hourly cache guard, not a second row.
    $this->withHeaders($headers)->getJson('/api/mobile/v1/customer/me')->assertOk();

    $row = CustomerDevice::query()->where('customer_id', $this->referrer->getKey())->sole();

    expect($row->device_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->device_hash)->not->toBe('ssaid-1234567890abcdef')
        ->and($row->platform)->toBe('android')
        ->and($row->first_seen_at)->not->toBeNull()
        ->and($row->last_seen_at)->not->toBeNull();
});

it('stores the HMAC-SHA256 of the raw id under the app key', function () {
    app(DeviceIdentity::class)->record($this->referrer, 'raw-device-id', 'ios');

    $row = CustomerDevice::query()->where('customer_id', $this->referrer->getKey())->sole();

    // The keyed hash, and nothing else: a different APP_KEY would store a
    // different value for the same raw id.
    expect($row->device_hash)->toBe(hash_hmac('sha256', 'raw-device-id', decodedAppKey()))
        ->and($row->platform)->toBe('ios');
});

it('ignores an empty or oversize device id', function () {
    app(DeviceIdentity::class)->record($this->referrer, '', 'android');
    app(DeviceIdentity::class)->record($this->referrer, str_repeat('x', 600), 'android');

    expect(CustomerDevice::query()->count())->toBe(0);
});

it('records BOTH identities when iOS sends X-Device-Ref alongside X-Device-Id', function () {
    $auth = app(MobileTokenService::class)
        ->issue($this->referrer, MobileAudience::Customer, 'Phone')
        ->plainTextToken;

    // The reinstall defence: the ifv: rotates when the last vendor app is
    // deleted; the kc: Keychain UUID survives. Both must land as rows.
    $this->withHeaders([
        'Authorization' => 'Bearer '.$auth,
        'X-Device-Id' => 'ifv:AAAA-BBBB-CCCC',
        'X-Device-Ref' => 'kc:3f2b8c44-9d1e-4e7a-8b2f-6a5d4c3b2a19',
        'X-Device-Platform' => 'ios',
    ])->getJson('/api/mobile/v1/customer/me')->assertOk();

    $hashes = CustomerDevice::query()
        ->where('customer_id', $this->referrer->getKey())
        ->pluck('device_hash')->sort()->values()->all();

    $expected = collect([
        'ifv:AAAA-BBBB-CCCC',
        'kc:3f2b8c44-9d1e-4e7a-8b2f-6a5d4c3b2a19',
    ])->map(fn (string $raw) => app(DeviceIdentity::class)->hash($raw))->sort()->values()->all();

    expect($hashes)->toBe($expected);
});

it('caps distinct devices per customer — a rotating header stops inserting rows', function () {
    for ($i = 1; $i <= 40; $i++) {
        app(DeviceIdentity::class)->record($this->referrer, "rotated-device-$i", 'android');
    }

    // 30 (the cap), not 40: past the cap a NEVER-seen id records nothing.
    expect(CustomerDevice::query()->where('customer_id', $this->referrer->getKey())->count())->toBe(30);
});

it('keeps the FCM trail for the longest token validation accepts (512 chars)', function () {
    $auth = app(MobileTokenService::class)
        ->issue($this->referrer, MobileAudience::Customer, 'Phone')
        ->plainTextToken;

    // Firebase warns token length may grow: the day it does, the trail —
    // the defence's only permanent FCM record — must not silently die.
    $token = str_repeat('t', 512);

    $this->withHeader('Authorization', 'Bearer '.$auth)
        ->putJson('/api/mobile/v1/customer/push-token', ['token' => $token, 'platform' => 'android'])
        ->assertNoContent();

    expect(
        CustomerDevice::query()
            ->where('customer_id', $this->referrer->getKey())
            ->where('device_hash', app(DeviceIdentity::class)->hash('fcm:'.$token))
            ->exists(),
    )->toBeTrue();
});

// --------------------------------------------------------- web ingestion

it('records the browser ref cookie at web signup, as a web-platform hash', function () {
    $uuid = '3f2b8c44-9d1e-4e7a-8b2f-6a5d4c3b2a19';

    $this->withCredentials()
        ->withUnencryptedCookie('mfa_did', $uuid)
        ->postJson('/api/customer/auth/register', [
            'signup_token' => signupTokenFor('+9607811111'),
            'name' => 'Aisha Ahmed',
        ])->assertCreated();

    $customer = Customer::query()->where('phone', '+9607811111')->sole();
    $row = CustomerDevice::query()->where('customer_id', $customer->getKey())->sole();

    expect($row->platform)->toBe('web')
        ->and($row->device_hash)->toBe(app(DeviceIdentity::class)->hash($uuid));
});

it('records the browser ref again on web OTP sign-in — collisions keep accruing', function () {
    OtpCode::query()->create([
        'phone' => $this->referrer->phone,
        'code_hash' => Hash::make('123456'),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
        'attempts' => 0,
    ]);

    $uuid = 'b1f9702e-58f5-4ac6-9c1c-2f2f0d9a4b11';

    $this->withCredentials()
        ->withUnencryptedCookie('mfa_did', $uuid)
        ->postJson('/api/customer/auth/otp/verify', [
            'phone' => $this->referrer->phone,
            'code' => '123456',
        ])->assertOk();

    $row = CustomerDevice::query()->where('customer_id', $this->referrer->getKey())->sole();

    expect($row->platform)->toBe('web')
        ->and($row->device_hash)->toBe(app(DeviceIdentity::class)->hash($uuid));
});

it('plants the browser ref cookie when the signup flow arrives without one', function () {
    $response = $this->postJson('/api/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607822222'),
        'name' => 'Mariyam Naseem',
    ])->assertCreated();

    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'mfa_did');

    expect($cookie)->not->toBeNull()
        ->and(DeviceIdentity::isBrowserRef($cookie->getValue()))->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue();

    // Freshly minted means nothing to record yet: the ref proves nothing
    // about the PAST, and the next sign-in records it.
    expect(CustomerDevice::query()->count())->toBe(0);
});

it('records nothing from a cookie that is not a UUID v4', function () {
    $this->withCredentials()
        ->withUnencryptedCookie('mfa_did', 'not-a-uuid-at-all')
        ->postJson('/api/customer/auth/register', [
            'signup_token' => signupTokenFor('+9607833333'),
            'name' => 'Ali Waheed',
        ])->assertCreated();

    expect(CustomerDevice::query()->count())->toBe(0);
});

// ------------------------------------------------------ disqualification

it('disqualifies instead of paying when referrer and referred ever shared a device', function () {
    Queue::fake();

    $referrerDevice = referralDevice($this->referrer);
    $friend = referredFriend();
    sharedHandset($this->referrer, $friend);

    app(TransitionService::class)->confirm(pendingSpend($friend, 1_000_000), Actor::system());

    $friend->refresh();

    expect(walletBalance($this->referrer))->toBe(0)
        ->and(referralEntries($this->referrer))->toBe(0)
        ->and($friend->referral_disqualified_at)->not->toBeNull()
        ->and($friend->referral_disqualified_reason)->toBe('device_collision')
        ->and($friend->referral_rewarded_at)->toBeNull();

    // No bonus push either — nothing was earned.
    Queue::assertNotPushed(
        SendPushNotification::class,
        fn (SendPushNotification $job): bool => (fn () => $this->deviceTokenId)->call($job) === $referrerDevice->getKey(),
    );
});

it('disqualifies when both accounts registered push from the same app install', function () {
    $friend = referredFriend();

    // The same FCM token — one physical install — registered by BOTH
    // accounts, exactly as two sign-ins on one handset would. The second
    // registration destroys the first's device_tokens row (`token` is
    // unique), but the hashed trail in customer_devices keeps both
    // sightings — which is what convicts here.
    $referrerAuth = app(MobileTokenService::class)->issue($this->referrer, MobileAudience::Customer, 'Phone')->plainTextToken;
    $friendAuth = app(MobileTokenService::class)->issue($friend, MobileAudience::Customer, 'Phone')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$referrerAuth)
        ->putJson('/api/mobile/v1/customer/push-token', ['token' => 'fcm-shared-install', 'platform' => 'android'])
        ->assertNoContent();

    // Drop the first request's in-memory guard user, as the lifecycle
    // tests do — two requests in one test share the booted app.
    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$friendAuth)
        ->putJson('/api/mobile/v1/customer/push-token', ['token' => 'fcm-shared-install', 'platform' => 'android'])
        ->assertNoContent();

    // The live row moved to the friend; the trail holds both.
    expect(DeviceToken::query()->where('token', 'fcm-shared-install')->count())->toBe(1)
        ->and(CustomerDevice::query()->pluck('customer_id')->sort()->values()->all())
        ->toBe([$this->referrer->getKey(), $friend->getKey()]);

    app(TransitionService::class)->confirm(pendingSpend($friend, 1_000_000), Actor::system());

    $friend->refresh();

    expect(walletBalance($this->referrer))->toBe(0)
        ->and($friend->referral_disqualified_at)->not->toBeNull()
        ->and($friend->referral_disqualified_reason)->toBe('device_collision');
});

it('never pays a disqualified customer through the sweep, however much they spend', function () {
    $friend = referredFriend();
    sharedHandset($this->referrer, $friend);

    app(TransitionService::class)->confirm(pendingSpend($friend, 1_000_000), Actor::system());
    expect($friend->fresh()->referral_disqualified_at)->not->toBeNull();

    // More validated spend, and the daily safety net: still nothing, ever.
    app(TransitionService::class)->confirm(pendingSpend($friend, 5_000_000), Actor::system());
    $this->artisan('manfaa:award-referral-bonuses')->assertSuccessful();

    expect(walletBalance($this->referrer))->toBe(0)
        ->and(referralEntries($this->referrer))->toBe(0)
        ->and($friend->fresh()->referral_rewarded_at)->toBeNull();
});

it('still pays a clean referral whose customers hold distinct devices', function () {
    $friend = referredFriend();
    app(DeviceIdentity::class)->record($this->referrer, 'ssaid-referrer-own-phone', 'android');
    app(DeviceIdentity::class)->record($friend, 'ssaid-friend-own-phone', 'android');

    app(TransitionService::class)->confirm(pendingSpend($friend, 1_000_000), Actor::system());

    $friend->refresh();

    expect(walletBalance($this->referrer))->toBe(5000)
        ->and(referralEntries($this->referrer))->toBe(1)
        ->and($friend->referral_rewarded_at)->not->toBeNull()
        ->and($friend->referral_disqualified_at)->toBeNull();
});

// -------------------------------------------------------------- endpoint

it('shows the disqualified state on the friends list and leaks no spend', function () {
    $friend = referredFriend(name: 'Aisha Ahmed');
    sharedHandset($this->referrer, $friend);
    app(TransitionService::class)->confirm(pendingSpend($friend, 1_000_000), Actor::system());

    $clean = referredFriend(name: 'Mariyam Naseem');
    app(TransitionService::class)->confirm(pendingSpend($clean, 300_000), Actor::system());

    $data = $this->actingAs($this->referrer, 'customer')
        ->getJson('/api/customer/referrals')
        ->assertOk()
        ->json('data');

    // Invited counts everyone; rewarded never counts the disqualified.
    expect($data['stats'])->toBe([
        'invited' => 2,
        'rewarded' => 0,
        'earned_total_laari' => 0,
    ]);

    // Newest first: the clean friend mid-bar, then the disqualified one —
    // flagged, spend hidden (0), rewarded false.
    expect($data['friends'])->toHaveCount(2)
        ->and($data['friends'][0]['name'])->toBe('Mar*** Nas***')
        ->and($data['friends'][0]['disqualified'])->toBeFalse()
        ->and($data['friends'][0]['spent_laari'])->toBe(300_000)
        ->and($data['friends'][1]['name'])->toBe('Ais*** Ahm***')
        ->and($data['friends'][1]['disqualified'])->toBeTrue()
        ->and($data['friends'][1]['spent_laari'])->toBe(0)
        ->and($data['friends'][1]['rewarded'])->toBeFalse();
});
