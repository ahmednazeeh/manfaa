<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Standing\Reconciler;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\CustomerWalletEntry;
use App\Models\DeviceToken;
use App\Models\LedgerJournal;
use App\Models\OtpCode;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The customer referral programme (owner, 2026-08-23): the referrer's
 * 6-digit customer_code typed at signup, the bonus released the moment the
 * friend's cumulative VALIDATED spend crosses the threshold — instantly,
 * into the referrer's wallet, once per referred customer, ever.
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    // Simulate a first-party frontend so the web register's session login
    // runs the same stateful pipeline the real page uses.
    $this->withHeader('Referer', 'http://localhost');

    $this->referrer = Customer::factory()->create([
        'customer_code' => '482917',
        'name' => 'Hassan Rasheed',
    ]);
});

/** Mints a live signup token the register endpoints will redeem. */
function signupTokenFor(string $phone): string
{
    $token = 'tok-'.fake()->unique()->lexify('????????????');

    OtpCode::query()->create([
        'phone' => $phone,
        'code_hash' => Hash::make('000000'),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
        'attempts' => 0,
        'consumed_at' => CarbonImmutable::now(),
        'signup_token_hash' => hash('sha256', $token),
        'signup_token_expires_at' => CarbonImmutable::now()->addMinutes(15),
    ]);

    return $token;
}

/** A friend attributed to the referrer, as signup would have written it. */
function referredFriend(?Customer $referrer = null, string $name = 'Aisha Ahmed'): Customer
{
    $friend = Customer::factory()->create(['name' => $name]);

    $friend->forceFill([
        'referred_by_customer_id' => ($referrer ?? test()->referrer)->getKey(),
        'referred_at' => CarbonImmutable::now(),
    ])->save();

    return $friend;
}

/** Validated (or not) spend: a transaction born awaiting_validation. */
function pendingSpend(Customer $customer, int $eligibleLaari): Transaction
{
    return Transaction::factory()->create([
        'customer_id' => $customer->getKey(),
        'state' => 'awaiting_validation',
        'eligible_laari' => $eligibleLaari,
        'sale_laari' => $eligibleLaari,
    ]);
}

function walletBalance(Customer $customer): int
{
    return (int) (CustomerWallet::query()
        ->where('customer_id', $customer->getKey())
        ->first()?->balance_laari ?? 0);
}

function referralEntries(Customer $customer): int
{
    $wallet = CustomerWallet::query()->where('customer_id', $customer->getKey())->first();

    return $wallet === null ? 0 : CustomerWalletEntry::query()
        ->where('wallet_id', $wallet->id)
        ->where('type', 'referral')
        ->count();
}

// ---------------------------------------------------- signup attribution

it('attributes a web signup that typed a valid referral code', function () {
    $this->postJson('/api/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607711111'),
        'name' => 'Aisha Ahmed',
        'referral_code' => '482917',
    ])->assertCreated();

    $customer = Customer::query()->where('phone', '+9607711111')->sole();

    expect($customer->referred_by_customer_id)->toBe($this->referrer->getKey())
        ->and($customer->referred_at)->not->toBeNull()
        ->and($customer->referral_rewarded_at)->toBeNull();
});

it('attributes a mobile signup that typed a valid referral code', function () {
    $this->postJson('/api/mobile/v1/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607722222'),
        'name' => 'Mariyam Naseem',
        'device_name' => 'Phone',
        'referral_code' => '482917',
    ])->assertCreated();

    $customer = Customer::query()->where('phone', '+9607722222')->sole();

    expect($customer->referred_by_customer_id)->toBe($this->referrer->getKey())
        ->and($customer->referred_at)->not->toBeNull();
});

it('signs up cleanly while silently ignoring a code nobody holds', function () {
    $this->postJson('/api/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607733333'),
        'name' => 'Ali Waheed',
        'referral_code' => '999999',
    ])->assertCreated();

    expect(Customer::query()->where('phone', '+9607733333')->sole()->referred_by_customer_id)
        ->toBeNull();
});

it('ignores a code whose holder is not active', function () {
    $this->referrer->forceFill(['status' => 'suspended'])->save();

    $this->postJson('/api/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607744444'),
        'name' => 'Ibrahim Manik',
        'referral_code' => '482917',
    ])->assertCreated();

    expect(Customer::query()->where('phone', '+9607744444')->sole()->referred_by_customer_id)
        ->toBeNull();
});

it('refuses a malformed referral code at validation', function () {
    // The one referral refusal that IS allowed: shape. A wrong-shaped code
    // is a form error the typist can see and fix on the spot.
    $this->postJson('/api/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607755555'),
        'name' => 'Ahmed Didi',
        'referral_code' => '48291',
    ])->assertUnprocessable();
});

it('records no attribution when no code was typed, and keeps the columns unfillable', function () {
    $this->postJson('/api/customer/auth/register', [
        'signup_token' => signupTokenFor('+9607766666'),
        'name' => 'Fathimath Shiuna',
    ])->assertCreated();

    expect(Customer::query()->where('phone', '+9607766666')->sole()->referred_by_customer_id)
        ->toBeNull();

    // Immutability's mechanical half: creation is the only write path, and
    // no mass assignment — today's or a future endpoint's — can reach the
    // attribution columns.
    $customer = new Customer;
    expect($customer->isFillable('referred_by_customer_id'))->toBeFalse()
        ->and($customer->isFillable('referred_at'))->toBeFalse()
        ->and($customer->isFillable('referral_rewarded_at'))->toBeFalse();
});

// ------------------------------------------------------------- the award

it('pays the referrer the moment validated spend crosses the threshold via payable_unfunded', function () {
    $friend = referredFriend();
    $tx = pendingSpend($friend, 1_000_000); // exactly MVR 10,000

    app(TransitionService::class)->makePayable($tx, Actor::system());

    expect(walletBalance($this->referrer))->toBe(5000)
        ->and(referralEntries($this->referrer))->toBe(1)
        ->and($friend->fresh()->referral_rewarded_at)->not->toBeNull();

    // OFF-LEDGER, like every other wallet credit: no journal, so the
    // Reconciler's transaction-derived liability stays honest.
    expect(LedgerJournal::query()
        ->where('reference_type', 'customer')
        ->where('reference_id', $friend->getKey())
        ->exists())->toBeFalse();
});

it('leaves the daily reconciliation green after a bonus is paid', function () {
    $friend = referredFriend();

    // Zero cashback/fee: factory rows post no accrual journals, so the
    // derivation must see zero movement too — what is under test is the
    // AWARD's ledger footprint, which must be none.
    $tx = Transaction::factory()->create([
        'customer_id' => $friend->getKey(),
        'state' => 'awaiting_validation',
        'eligible_laari' => 1_000_000,
        'sale_laari' => 1_000_000,
        'cashback_laari' => 0,
        'fee_laari' => 0,
        'fee_gst_laari' => 0,
    ]);
    app(TransitionService::class)->makePayable($tx, Actor::system());

    expect(walletBalance($this->referrer))->toBe(5000);

    // The award moved real wallet money; the §8 books and their derivation
    // must still agree — a referral bonus is deliberately off-ledger.
    $run = app(Reconciler::class)->run();

    expect($run->status)->toBe('ok');
});

it('pays when the crossing state is confirmed', function () {
    $friend = referredFriend();
    $tx = pendingSpend($friend, 1_200_000);

    // The marketplace path: awaiting_validation → confirmed directly.
    app(TransitionService::class)->confirm($tx, Actor::system());

    expect(walletBalance($this->referrer))->toBe(5000)
        ->and($friend->fresh()->referral_rewarded_at)->not->toBeNull();
});

it('accumulates across sales — no single purchase needs to cross alone', function () {
    $friend = referredFriend();
    $service = app(TransitionService::class);

    $service->makePayable(pendingSpend($friend, 600_000), Actor::system());
    expect(walletBalance($this->referrer))->toBe(0);

    $service->makePayable(pendingSpend($friend, 400_000), Actor::system());
    expect(walletBalance($this->referrer))->toBe(5000);
});

it('pays once per referred customer, ever', function () {
    $friend = referredFriend();
    $service = app(TransitionService::class);

    $service->makePayable(pendingSpend($friend, 1_000_000), Actor::system());
    $service->makePayable(pendingSpend($friend, 2_000_000), Actor::system());
    $this->artisan('manfaa:award-referral-bonuses')->assertSuccessful();

    expect(walletBalance($this->referrer))->toBe(5000)
        ->and(referralEntries($this->referrer))->toBe(1);
});

it('counts only spend that survived validation — reversed rows buy nothing', function () {
    $friend = referredFriend();
    $service = app(TransitionService::class);

    // A large sale that was reversed, and a small one that validated.
    $reversed = pendingSpend($friend, 2_000_000);
    $service->reverse($reversed, Actor::system(), 'refund');
    $service->makePayable(pendingSpend($friend, 500_000), Actor::system());

    expect(walletBalance($this->referrer))->toBe(0)
        ->and($friend->fresh()->referral_rewarded_at)->toBeNull();
});

it('awards nothing while the programme is off, but keeps attribution and pays once it returns', function () {
    app(PlatformConfig::class)->set('referral_enabled', 0);

    $friend = referredFriend();
    app(TransitionService::class)->makePayable(pendingSpend($friend, 1_500_000), Actor::system());

    expect(walletBalance($this->referrer))->toBe(0)
        ->and($friend->fresh()->referred_by_customer_id)->toBe($this->referrer->getKey())
        ->and($friend->fresh()->referral_rewarded_at)->toBeNull();

    // No time limit: switching back on, the daily net pays what is owed.
    app(PlatformConfig::class)->set('referral_enabled', 1);
    $this->artisan('manfaa:award-referral-bonuses')->assertSuccessful();

    expect(walletBalance($this->referrer))->toBe(5000);
});

it('awards through the safety net after an admin lowers the threshold', function () {
    $friend = referredFriend();
    app(TransitionService::class)->makePayable(pendingSpend($friend, 200_000), Actor::system());

    expect(walletBalance($this->referrer))->toBe(0);

    // MVR 10,000 → MVR 1,000: already-crossed customers have no further
    // transition to fire on — exactly what the daily sweep exists for.
    app(PlatformConfig::class)->set('referral_spend_threshold_laari', 100_000);
    $this->artisan('manfaa:award-referral-bonuses')->assertSuccessful();

    expect(walletBalance($this->referrer))->toBe(5000)
        ->and($friend->fresh()->referral_rewarded_at)->not->toBeNull();
});

// ---------------------------------------------------------- notification

it('queues the push to the referrer only, and spends no SMS', function () {
    Queue::fake();

    // Both hold devices; only the referrer's may ring.
    $referrerDevice = referralDevice($this->referrer);
    $friend = referredFriend();
    referralDevice($friend);

    app(TransitionService::class)->makePayable(pendingSpend($friend, 1_000_000), Actor::system());

    Queue::assertPushed(SendPushNotification::class, 1);
    Queue::assertPushed(
        SendPushNotification::class,
        fn (SendPushNotification $job): bool => (fn () => $this->deviceTokenId)->call($job) === $referrerDevice->getKey(),
    );
    Queue::assertNotPushed(SendCustomerSms::class);
});

// -------------------------------------------------------------- endpoint

it('answers the referral summary on the web mount', function () {
    $friendPaid = referredFriend(name: 'Aisha Ahmed');
    $service = app(TransitionService::class);
    $service->makePayable(pendingSpend($friendPaid, 1_500_000), Actor::system());

    $friendMidway = referredFriend(name: 'Mariyam Naseem');
    $service->makePayable(pendingSpend($friendMidway, 300_000), Actor::system());

    $data = $this->actingAs($this->referrer, 'customer')
        ->getJson('/api/customer/referrals')
        ->assertOk()
        ->json('data');

    expect($data['enabled'])->toBeTrue()
        ->and($data['reward_laari'])->toBe(5000)
        ->and($data['threshold_laari'])->toBe(1_000_000)
        ->and($data['code'])->toBe('482917')
        ->and($data['share_url'])->toBe('https://manfaa.app/signup?ref=482917')
        ->and($data['stats'])->toBe([
            'invited' => 2,
            'rewarded' => 1,
            'earned_total_laari' => 5000,
        ]);

    // Newest first; names MASKED; spend CAPPED at the threshold — progress
    // toward the bar, never a window onto real spending.
    expect($data['friends'])->toHaveCount(2)
        ->and($data['friends'][0]['name'])->toBe('Mar*** Nas***')
        ->and($data['friends'][0]['spent_laari'])->toBe(300_000)
        ->and($data['friends'][0]['rewarded'])->toBeFalse()
        ->and($data['friends'][1]['name'])->toBe('Ais*** Ahm***')
        ->and($data['friends'][1]['spent_laari'])->toBe(1_000_000)
        ->and($data['friends'][1]['rewarded'])->toBeTrue()
        ->and($data['friends'][1]['joined_at'])->not->toBeNull();
});

it('answers the same summary on the mobile mount', function () {
    referredFriend();

    $auth = app(MobileTokenService::class)
        ->issue($this->referrer, MobileAudience::Customer, 'Phone')
        ->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$auth])
        ->getJson('/api/mobile/v1/customer/referrals')
        ->assertOk()
        ->assertJsonPath('data.code', '482917')
        ->assertJsonPath('data.stats.invited', 1);
});

it('refuses both mounts unauthenticated', function () {
    $this->getJson('/api/customer/referrals')->assertUnauthorized();
    $this->getJson('/api/mobile/v1/customer/referrals')->assertUnauthorized();
});

/** A registered push device, wired the way the app registers one. */
function referralDevice(Customer $customer): DeviceToken
{
    $auth = app(MobileTokenService::class)->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    return DeviceToken::query()->create([
        'tokenable_type' => $customer->getMorphClass(),
        'tokenable_id' => $customer->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
        'token' => 'fcm-'.$customer->getKey(),
        'platform' => 'android',
    ]);
}
