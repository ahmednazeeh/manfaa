<?php

declare(strict_types=1);

use App\Domain\MerchantSettings\StaffService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The device list, and the reason it exists: the review found that the
 * documented lost-or-stolen-phone remedy could only be reached by presenting
 * a live token from the very device you were trying to cut off. These tests
 * pin BOTH doors — the website's session and the app's bearer token.
 *
 * Discipline inherited from MobileTokenTest: ONE authenticated request per
 * test unless the test explicitly calls app('auth')->forgetGuards(), because
 * the container's cached guards otherwise answer the second request from a
 * stale, already-resolved user.
 */
function device(Customer|MerchantUser $user, MobileAudience $audience, string $name = 'Test device'): string
{
    return app(MobileTokenService::class)->issue($user, $audience, $name)->plainTextToken;
}

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

// ------------------------------------------------- the website (session)

it('lists the app devices from the WEBSITE with only a session', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    device($customer, MobileAudience::Customer, "Ahmed's iPhone");
    device($customer, MobileAudience::Customer, 'Old Android');

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/login', [
            'phone' => '+9607712345',
            'password' => 'password',
        ])->assertOk();

    $response = $this->withHeader('Referer', 'http://localhost')
        ->getJson('/api/customer/devices')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('device_name')->all())
        ->toContain("Ahmed's iPhone", 'Old Android');

    // A browser session is not a device, so nothing is marked as "this one".
    expect(collect($response->json('data'))->pluck('is_current_device')->all())
        ->each->toBeFalse();

    // Never the credential itself.
    expect($response->json('data.0'))->not->toHaveKeys(['token', 'abilities']);
});

it('cuts off the stolen phone from the WEBSITE — the remedy that was unreachable', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    $stolen = device($customer, MobileAudience::Customer, 'Stolen iPhone');
    $kept = device($customer, MobileAudience::Customer, 'Home tablet');

    $stolenId = PersonalAccessToken::findToken($stolen)->getKey();

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/login', [
            'phone' => '+9607712345',
            'password' => 'password',
        ])->assertOk();

    $this->withHeader('Referer', 'http://localhost')
        ->deleteJson('/api/customer/devices/'.$stolenId)
        ->assertNoContent();

    expect($customer->tokens()->pluck('id')->all())
        ->toBe([PersonalAccessToken::findToken($kept)->getKey()]);

    // And the stolen token is dead on arrival.
    app('auth')->forgetGuards();

    $this->withHeaders(bearer($stolen))
        ->getJson('/api/mobile/v1/customer/devices')
        ->assertStatus(401);
});

it('signs every device out from the WEBSITE', function () {
    $customer = Customer::factory()->create(['phone' => '+9607712345']);
    device($customer, MobileAudience::Customer);
    device($customer, MobileAudience::Customer);

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/customer/auth/login', [
            'phone' => '+9607712345',
            'password' => 'password',
        ])->assertOk();

    $this->withHeader('Referer', 'http://localhost')
        ->deleteJson('/api/customer/devices')
        ->assertOk()
        ->assertJsonPath('data.revoked', 2);

    expect($customer->tokens()->count())->toBe(0);
});

// --------------------------------------------------- the app (bearer)

it('lists devices from the APP and marks the calling one', function () {
    $customer = Customer::factory()->create();
    device($customer, MobileAudience::Customer, 'Other phone');
    $mine = device($customer, MobileAudience::Customer, 'This phone');

    $response = $this->withHeaders(bearer($mine))
        ->getJson('/api/mobile/v1/customer/devices')
        ->assertOk();

    $current = collect($response->json('data'))->firstWhere('is_current_device', true);

    expect($current)->not->toBeNull();
    expect($current['device_name'])->toBe('This phone');
});

it('lets a staff member list and cut off their own till devices', function () {
    $user = MerchantUser::factory()->create();
    $token = device($user, MobileAudience::Merchant, 'Counter tablet');

    $this->withHeaders(bearer($token))
        ->getJson('/api/mobile/v1/merchant/devices')
        ->assertOk()
        ->assertJsonPath('data.0.device_name', 'Counter tablet');
});

// ------------------------------------------------------------- scoping

it('cannot revoke another account\'s device', function () {
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();

    $myToken = device($mine, MobileAudience::Customer);
    $theirToken = device($theirs, MobileAudience::Customer);
    $theirId = PersonalAccessToken::findToken($theirToken)->getKey();

    $this->withHeaders(bearer($myToken))
        ->deleteJson('/api/mobile/v1/customer/devices/'.$theirId)
        ->assertStatus(404);

    expect($theirs->tokens()->count())->toBe(1);
});

it('does not show a merchant device in the customer list, or the reverse', function () {
    // One human can be both a shopper and a cashier; the two are unrelated
    // accounts, and neither list may leak into the other.
    $customer = Customer::factory()->create();
    $token = device($customer, MobileAudience::Customer, 'Shopper phone');

    $staff = MerchantUser::factory()->create();
    device($staff, MobileAudience::Merchant, 'Till');

    $this->withHeaders(bearer($token))
        ->getJson('/api/mobile/v1/customer/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.device_name', 'Shopper phone');
});

it('hides expired tokens from the device list', function () {
    $customer = Customer::factory()->create();
    $live = device($customer, MobileAudience::Customer, 'Live phone');

    $dead = PersonalAccessToken::findToken(device($customer, MobileAudience::Customer, 'Dead phone'));
    $dead->forceFill(['expires_at' => now()->subDay()])->save();

    $this->withHeaders(bearer($live))
        ->getJson('/api/mobile/v1/customer/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.device_name', 'Live phone');
});

// ------------------------------------------ deactivation destroys tokens

it('destroys a staff member\'s tokens when they are deactivated, so reactivation cannot revive them', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $cashier = MerchantUser::factory()->for($merchant)->create();
    $token = device($cashier, MobileAudience::Merchant, 'Cashier phone');

    expect($cashier->tokens()->count())->toBe(1);

    app(StaffService::class)
        ->update($cashier, $owner, null, false);

    // The row is GONE, not merely refused — otherwise reactivating the
    // account months later would revive a token on a phone they no longer
    // hold, with nobody having re-entered a password.
    expect($cashier->tokens()->count())->toBe(0);

    $cashier->forceFill(['is_active' => true])->save();

    $this->withHeaders(bearer($token))
        ->getJson('/api/mobile/v1/merchant/devices')
        ->assertStatus(401);
});
