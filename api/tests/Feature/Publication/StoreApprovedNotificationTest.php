<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\AdminUser;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Approval reaches the merchant by BOTH channels (owner decision
 * 2026-08-18).
 *
 * It is the one merchant moment that earns an SMS. The merchant has been
 * waiting on a human decision they cannot chase, and it may land before
 * anyone at the store has installed the app — which is precisely why a push
 * alone is not enough here.
 */
function approvableStore(): Merchant
{
    $merchant = Merchant::factory()->create([
        'name' => 'Reef Mart',
        'status' => 'pending_review',
        'category' => 'grocery',
        'channel' => 'in_store',
        'contact_phone' => '+9607771234',
        'eligibility_basis' => 'Everything except tobacco.',
        'description' => 'A neighbourhood grocery in Malé.',
    ]);

    MerchantRate::factory()->for($merchant)->create();

    return $merchant;
}

it('texts the store and pushes to its staff when an admin approves it', function () {
    Queue::fake();

    $merchant = approvableStore();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    // A till device for the owner — the push half needs somewhere to land.
    $token = app(MobileTokenService::class)
        ->issue($owner, MobileAudience::Merchant, 'Till')->plainTextToken;
    DeviceToken::query()->create([
        'tokenable_type' => $owner->getMorphClass(),
        'tokenable_id' => $owner->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($token)->getKey(),
        'token' => 'till-'.Str::random(8),
        'platform' => 'android',
        'locale' => 'en',
    ]);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/store-reviews/{$merchant->id}/approve")
        ->assertOk();

    expect($merchant->fresh()->status)->toBe('active');

    // SMS — to the STORE's own verified number, not a staff member's (they
    // have none on file).
    Queue::assertPushed(SendCustomerSms::class);

    // Push — to the staff who can see the store profile.
    Queue::assertPushed(SendPushNotification::class);
});

it('still texts a store whose staff have never installed the app', function () {
    // The case the SMS exists for: no device, so a push-only notice would
    // reach nobody and the merchant would go on waiting.
    Queue::fake();

    $merchant = approvableStore();
    MerchantUser::factory()->for($merchant)->owner()->create();

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/store-reviews/{$merchant->id}/approve")
        ->assertOk();

    Queue::assertPushed(SendCustomerSms::class);
    Queue::assertNotPushed(SendPushNotification::class);
});

it('sends nothing when the approval is refused', function () {
    Queue::fake();

    // Already active — approve() throws not_pending_review, and a store that
    // did not just go live must not be told that it did.
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/store-reviews/{$merchant->id}/approve")
        ->assertStatus(409);

    Queue::assertNotPushed(SendCustomerSms::class);
    Queue::assertNotPushed(SendPushNotification::class);
});

it('keeps SMS off every other merchant moment', function () {
    // The default is the important half: a settlement reminder that texted
    // the store every month would be a bill and a nuisance.
    expect(NotificationTemplateKey::StoreApproved->smsToMerchantContact())
        ->toBeTrue();

    foreach (NotificationTemplateKey::cases() as $key) {
        if ($key === NotificationTemplateKey::StoreApproved) {
            continue;
        }

        expect($key->smsToMerchantContact())
            ->toBeFalse("{$key->value} must not text the store");
    }
});
