<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Approvals;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MR9 through the merchant app's own mount. The gate lives in the shared
 * controllers, so what is proved here is the CONTRACT the app meets: the same
 * 202, the same queued-change body, and refusals in the one mobile envelope.
 *
 * No session actingAs anywhere in this file — the mobile tree is bearer-only
 * (EnsureMobileToken), and a session would be refused 401 by design.
 */
function mobileChangeHeaders(MerchantUser $user): array
{
    return ['Authorization' => 'Bearer '.app(MobileTokenService::class)
        ->issue($user, MobileAudience::Merchant, 'Phone')->plainTextToken];
}

it('answers the app the same 202, the same pending state and the same refusals', function () {
    $merchant = Merchant::factory()->create(['name' => 'Original Name', 'contact_phone' => '+9607000001']);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = mobileChangeHeaders($owner);

    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['name' => 'Chai House'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending_review')
        ->assertJsonPath('data.change_request.kind', 'profile')
        ->assertJsonPath('data.change_request.proposed.name', 'Chai House')
        ->assertJsonPath('data.profile.name', 'Original Name');

    // The app's More screen renders the wait from the ordinary read.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('data.name', 'Original Name')
        ->assertJsonPath('data.pending_change.proposed.name', 'Chai House');

    // The instant half needs no review and answers 200, so the app can tell
    // "saved" from "submitted" on the status line alone.
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['contact_phone' => '+9607779999'])
        ->assertOk()
        ->assertJsonPath('data.contact_phone', '+9607779999');

    $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/branches', ['name' => 'Addu', 'address' => 'Majeedhee Magu, Malé'])
        ->assertStatus(202)
        ->assertJsonPath('data.change_request.kind', 'branch_create');

    expect(MerchantBranch::query()->count())->toBe(0);

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/branches')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.pending_changes.0.proposed.name', 'Addu');

    Approvals::approveAll($merchant);

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/branches')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Addu')
        ->assertJsonCount(0, 'meta.pending_changes');
});

it('keeps every refusal in the one mobile envelope', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = mobileChangeHeaders($owner);

    // Validation runs BEFORE the gate — a malformed change never queues.
    $refusal = $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['channel' => 'metaverse'])
        ->assertStatus(422);

    expect($refusal->json('error.code'))->toBe('validation_failed');

    // Permission is still the FIRST answer: a cashier cannot even submit.
    // (forgetGuards between identities — the house idiom: a guard set by the
    // previous request in this test would otherwise answer for the next.)
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();
    app('auth')->forgetGuards();

    $denied = $this->withHeaders(mobileChangeHeaders($staff))
        ->patchJson('/api/mobile/v1/merchant/profile', ['name' => 'Not Mine'])
        ->assertStatus(403);

    expect($denied->json('error.code'))->toBe('permission_required');

    // And a store still awaiting its own review meets the wizard conflict,
    // not the change queue — the two conversations stay distinct.
    $waiting = Merchant::factory()->pendingReview()->create();
    $waitingOwner = MerchantUser::factory()->for($waiting)->owner()->create();
    app('auth')->forgetGuards();

    $conflict = $this->withHeaders(mobileChangeHeaders($waitingOwner))
        ->patchJson('/api/mobile/v1/merchant/profile', ['name' => 'Rewritten Mid-Review'])
        ->assertStatus(409);

    expect($conflict->json('error.code'))->toBe('store_not_approved');
});
