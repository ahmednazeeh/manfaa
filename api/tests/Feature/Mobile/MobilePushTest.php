<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\MerchantSettings\StaffService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN-mobile-api.md M4 — push registration, the revocation binding that
 * keeps a sold phone quiet, and the moments themselves.
 */
function pushToken(Customer|MerchantUser $user, MobileAudience $audience): string
{
    return app(MobileTokenService::class)->issue($user, $audience, 'Device')->plainTextToken;
}

function pushAuth(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

// ------------------------------------------------------- registration

it('registers a device push token against the calling auth token', function () {
    $customer = Customer::factory()->create();
    $auth = pushToken($customer, MobileAudience::Customer);

    $this->withHeaders(pushAuth($auth))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'fcm-abc',
            'platform' => 'ios',
            'app_build' => 12,
            'locale' => 'dv',
        ])->assertNoContent();

    $device = DeviceToken::query()->firstOrFail();

    expect($device->token)->toBe('fcm-abc');
    expect($device->platform)->toBe('ios');
    expect($device->locale)->toBe('dv');
    expect($device->tokenable_id)->toBe($customer->getKey());
    expect($device->personal_access_token_id)
        ->toBe(PersonalAccessToken::findToken($auth)->getKey());
});

it('moves a registration rather than duplicating it when a device re-registers', function () {
    // Apps re-send their token on every launch, and providers rotate them.
    // A duplicate row would keep delivering to whoever held the device last.
    $first = Customer::factory()->create();
    $second = Customer::factory()->create();

    $this->withHeaders(pushAuth(pushToken($first, MobileAudience::Customer)))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'shared-handset', 'platform' => 'android',
        ])->assertNoContent();

    app('auth')->forgetGuards();

    $this->withHeaders(pushAuth(pushToken($second, MobileAudience::Customer)))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'shared-handset', 'platform' => 'android',
        ])->assertNoContent();

    expect(DeviceToken::query()->count())->toBe(1);
    expect(DeviceToken::query()->firstOrFail()->tokenable_id)->toBe($second->getKey());
});

it('refuses a merchant token on the customer push route', function () {
    $user = MerchantUser::factory()->create();

    $this->withHeaders(pushAuth(pushToken($user, MobileAudience::Merchant)))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'x', 'platform' => 'ios',
        ])->assertStatus(401);
});

// -------------------------------------------- revocation kills the push

it('stops pushing to a device the moment its auth token is revoked', function () {
    // THE point of binding the registration to the auth token. A sold,
    // lost or stolen phone must not keep announcing a balance on its lock
    // screen, and no revocation path should have to remember to say so.
    $customer = Customer::factory()->create();
    $auth = pushToken($customer, MobileAudience::Customer);

    $this->withHeaders(pushAuth($auth))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'fcm-abc', 'platform' => 'ios',
        ])->assertNoContent();

    expect(DeviceToken::query()->count())->toBe(1);

    PersonalAccessToken::findToken($auth)->delete();

    expect(DeviceToken::query()->count())->toBe(0);
});

it('takes the push with it when a device is cut off from the website', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $auth = pushToken($customer, MobileAudience::Customer);
    $authId = PersonalAccessToken::findToken($auth)->getKey();

    $this->withHeaders(pushAuth($auth))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'fcm-abc', 'platform' => 'ios',
        ])->assertNoContent();

    app('auth')->forgetGuards();

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/login', [
            'phone' => '+9607712345', 'password' => 'password',
        ])->assertOk();

    $this->withHeader('Referer', 'http://localhost')
        ->deleteJson('/api/customer/devices/'.$authId)
        ->assertNoContent();

    expect(DeviceToken::query()->count())->toBe(0);
});

it('takes every push with it when a staff member is deactivated', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $cashier = MerchantUser::factory()->for($merchant)->create();

    $this->withHeaders(pushAuth(pushToken($cashier, MobileAudience::Merchant)))
        ->putJson('/api/mobile/v1/merchant/push-token', [
            'token' => 'till-1', 'platform' => 'android',
        ])->assertNoContent();

    expect(DeviceToken::query()->count())->toBe(1);

    app(StaffService::class)->update($cashier, $owner, null, false);

    expect(DeviceToken::query()->count())->toBe(0);
});

it('lets a user silence a device without signing it out', function () {
    $customer = Customer::factory()->create();
    $auth = pushToken($customer, MobileAudience::Customer);

    $this->withHeaders(pushAuth($auth))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'fcm-abc', 'platform' => 'ios',
        ])->assertNoContent();

    app('auth')->forgetGuards();

    $this->withHeaders(pushAuth($auth))
        ->deleteJson('/api/mobile/v1/customer/push-token', ['token' => 'fcm-abc'])
        ->assertNoContent();

    expect(DeviceToken::query()->count())->toBe(0);
    // Still signed in — silencing is not signing out.
    expect($customer->tokens()->count())->toBe(1);
});

it('cannot silence another account\'s device', function () {
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();

    $this->withHeaders(pushAuth(pushToken($theirs, MobileAudience::Customer)))
        ->putJson('/api/mobile/v1/customer/push-token', [
            'token' => 'their-handset', 'platform' => 'ios',
        ])->assertNoContent();

    app('auth')->forgetGuards();

    $this->withHeaders(pushAuth(pushToken($mine, MobileAudience::Customer)))
        ->deleteJson('/api/mobile/v1/customer/push-token', ['token' => 'their-handset'])
        ->assertNoContent();

    expect(DeviceToken::query()->count())->toBe(1);
});

// ---------------------------------------------------------- the moments

it('queues a push to every registered device of a customer', function () {
    Queue::fake();

    $customer = Customer::factory()->create();

    foreach (['a', 'b'] as $handset) {
        $auth = pushToken($customer, MobileAudience::Customer);
        DeviceToken::query()->create([
            'tokenable_type' => $customer->getMorphClass(),
            'tokenable_id' => $customer->getKey(),
            'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
            'token' => 'fcm-'.$handset,
            'platform' => 'ios',
        ]);
    }

    NotificationTemplate::query()->where('key', NotificationTemplateKey::PayoutPaid->value)
        ->update(['active' => true]);

    app(NotificationService::class)->send(NotificationTemplateKey::PayoutPaid, $customer, [
        'amount' => 'MVR 100.00',
        'reference' => 'PB-1',
    ]);

    Queue::assertPushed(SendPushNotification::class, 2);
});

it('still pushes to a customer who has no phone number on file', function () {
    // SMS and push are independent channels. The old shape returned before
    // either was considered when the number was blank.
    Queue::fake();

    $customer = Customer::factory()->create(['phone' => '']);
    $auth = pushToken($customer, MobileAudience::Customer);

    DeviceToken::query()->create([
        'tokenable_type' => $customer->getMorphClass(),
        'tokenable_id' => $customer->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
        'token' => 'fcm-only', 'platform' => 'ios',
    ]);

    NotificationTemplate::query()->where('key', NotificationTemplateKey::PayoutPaid->value)
        ->update(['active' => true]);

    app(NotificationService::class)->send(NotificationTemplateKey::PayoutPaid, $customer, [
        'amount' => 'MVR 100.00', 'reference' => 'PB-1',
    ]);

    Queue::assertPushed(SendPushNotification::class, 1);
});

it('sends a merchant moment only to staff who may act on it', function () {
    Queue::fake();

    $merchant = Merchant::factory()->create();

    $canSee = MerchantUser::factory()->for($merchant)->owner()->create();
    $cannotSee = MerchantUser::factory()->for($merchant)->withRole(
        MerchantRole::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Cashier',
            'slug' => 'cashier-'.$merchant->id,
            'permissions' => [Permission::CreditsCreate->value],
            'is_owner' => false,
            'is_system' => false,
        ])
    )->create();

    foreach ([$canSee, $cannotSee] as $index => $user) {
        $auth = pushToken($user, MobileAudience::Merchant);
        DeviceToken::query()->create([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
            'token' => 'till-'.$index, 'platform' => 'android',
        ]);
    }

    app(NotificationService::class)->sendToMerchantStaff(
        NotificationTemplateKey::SettlementAccepted,
        $merchant,
        ['amount' => 'MVR 500.00', 'reference' => 'ST-1'],
        Permission::SettlementsView,
    );

    // A settlement message reaching a cashier who cannot open the settlement
    // screen is noise they cannot act on.
    Queue::assertPushed(SendPushNotification::class, 1);
});

it('keeps the app name out of the push body', function () {
    // Both platforms print the app's name above the body, and a push gets
    // about two lines — repeating "— Manfaa" inside it wastes one.
    $body = (new ReflectionClass(NotificationService::class))
        ->getMethod('stripSignature');
    $body->setAccessible(true);

    expect($body->invoke(null, 'You earned MVR 5.00 cashback at Ocean Café. — Manfaa'))
        ->toBe('You earned MVR 5.00 cashback at Ocean Café.');
});

it('has a seeded template and a push title for every moment', function () {
    foreach (NotificationTemplateKey::cases() as $key) {
        expect(NotificationTemplate::query()->where('key', $key->value)->exists())
            ->toBeTrue("no seeded row for {$key->value}");

        expect($key->pushTitle())->toHaveKeys(['en', 'dv']);
        expect(trim($key->pushTitle()['dv']))->not->toBe('');
    }
});
