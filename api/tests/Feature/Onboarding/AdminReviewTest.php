<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    // Approve/reject is superadmin-only (§1: "superadmin approval queue");
    // ReviewIntegrityTest covers the plain-admin 403.
    $this->admin = AdminUser::factory()->create(['role' => 'superadmin']);
});

/** A wizard-complete merchant sitting in pending_review, owner attached. */
function reviewableMerchant(array $attributes = []): Merchant
{
    $merchant = Merchant::factory()->pendingReview()->create([
        'category' => 'grocery',
        'channel' => 'both',
        'eligibility_basis' => 'Invoice total excluding delivery.',
        'setup_state' => ['profile' => true, 'rate' => true],
        ...$attributes,
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subMinute(),
        'effective_to' => null,
    ]);
    MerchantUser::factory()->for($merchant)->owner()->create();

    return $merchant;
}

it('lists the review queue with per-state counts and full wizard data', function () {
    $pending = reviewableMerchant(['name' => 'Pending Mart', 'slug' => 'pending-mart']);
    Merchant::factory()->draft()->create(['name' => 'Draft Mart']);
    Merchant::factory()->rejected()->create(['name' => 'Rejected Mart']);
    Merchant::factory()->create(['name' => 'Live Mart']); // active — not in the queue

    $this->actingAs($this->admin, 'admin');

    $response = $this->getJson('/api/admin/store-reviews')->assertOk();

    $response->assertJsonPath('meta.state', 'pending_review')
        ->assertJsonPath('meta.counts.pending_review', 1)
        ->assertJsonPath('meta.counts.draft', 1)
        ->assertJsonPath('meta.counts.rejected', 1);

    $row = $response->json('data.0');
    expect($row['id'])->toBe($pending->id)
        ->and($row)->toHaveKeys([
            'name', 'slug', 'status', 'category', 'channel', 'eligibility_basis',
            'contact_email', 'contact_phone', 'logo_url', 'primary_branch', 'cashback_rate_percent', 'setup_state',
            'submitted_at', 'rejected_at', 'rejected_reason', 'created_at',
        ])
        ->and($row['cashback_rate_percent'])->toBe('2.00')
        ->and($row['channel'])->toBe('both')
        ->and($row['logo_url'])->toBeNull();

    // Other states on demand.
    $drafts = $this->getJson('/api/admin/store-reviews?state=draft')->assertOk()->json('data');
    expect(collect($drafts)->pluck('name'))->toContain('Draft Mart');

    $this->getJson('/api/admin/store-reviews?state=active')->assertUnprocessable();
});

it('approves a pending store: active, stamped, and publicly visible at once', function () {
    $merchant = reviewableMerchant(['name' => 'Fresh Mart', 'slug' => 'fresh-mart']);

    // Warm the public cache with the pre-approval (empty) dataset — approval
    // must bust it, not wait out the TTL.
    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.approved_by', $this->admin->id);

    $merchant->refresh();
    expect($merchant->status)->toBe('active')
        ->and($merchant->approved_at)->not->toBeNull()
        ->and($merchant->approved_by)->toBe($this->admin->id);

    // Public surfaces list it immediately, carrying the channel enum.
    $directory = $this->getJson('/api/discover/merchants')->assertOk();
    expect($directory->json('meta.total'))->toBe(1);
    expect($directory->json('data.0.slug'))->toBe('fresh-mart');
    expect($directory->json('data.0.channel'))->toBe('both');

    $this->getJson('/api/discover/merchants/fresh-mart')->assertOk()
        ->assertJsonPath('data.channel', 'both')
        ->assertJsonPath('data.category', 'grocery');
});

it('rejects a pending store with a required reason; the owner edits and resubmits', function () {
    $merchant = reviewableMerchant();
    $owner = MerchantUser::query()->where('merchant_id', $merchant->id)->firstOrFail();

    $this->actingAs($this->admin, 'admin');

    // Reason is mandatory.
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/reject")->assertUnprocessable();

    $this->postJson("/api/admin/store-reviews/{$merchant->id}/reject", [
        'reason' => 'Terms unclear — spell out exclusions.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejected_reason', 'Terms unclear — spell out exclusions.');

    // The owner sees the reason in the wizard and may edit again.
    $this->actingAs($owner, 'merchant');

    $this->getJson('/api/merchant/setup')->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejected_reason', 'Terms unclear — spell out exclusions.');

    $this->patchJson('/api/merchant/setup/profile', [
        'eligibility_basis' => 'Invoice total excluding delivery and service charge.',
    ])->assertOk();

    // Resubmit clears the reason and goes back to the queue.
    $this->postJson('/api/merchant/setup/submit')->assertOk()
        ->assertJsonPath('data.status', 'pending_review')
        ->assertJsonPath('data.rejected_reason', null);

    $merchant->refresh();
    expect($merchant->status)->toBe('pending_review')
        ->and($merchant->rejected_reason)->toBeNull();
});

it('re-picking the rate after rejection still replaces the untraded initial row', function () {
    $merchant = reviewableMerchant();
    $owner = MerchantUser::query()->where('merchant_id', $merchant->id)->firstOrFail();

    $this->actingAs($this->admin, 'admin');
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/reject", ['reason' => 'Rate too low for the category.'])->assertOk();

    $this->actingAs($owner, 'merchant');
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '5.00'])->assertOk();

    $rates = MerchantRate::query()->where('merchant_id', $merchant->id)->get();
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->rate_bp)->toBe(500);
});

it('answers 409 when approving or rejecting anything not pending review', function () {
    $draft = Merchant::factory()->draft()->create();
    $active = Merchant::factory()->create();

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/store-reviews/{$draft->id}/approve")
        ->assertStatus(409)->assertJsonPath('code', 'not_pending_review');
    $this->postJson("/api/admin/store-reviews/{$active->id}/reject", ['reason' => 'n/a'])
        ->assertStatus(409)->assertJsonPath('code', 'not_pending_review');

    expect($draft->refresh()->status)->toBe('draft')
        ->and($active->refresh()->status)->toBe('active');
});

it('locks the whole review surface behind the admin guard', function () {
    $merchant = reviewableMerchant();
    $owner = MerchantUser::query()->where('merchant_id', $merchant->id)->firstOrFail();

    $this->getJson('/api/admin/store-reviews')->assertUnauthorized();
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")->assertUnauthorized();

    // A merchant session is not an admin.
    $this->actingAs($owner, 'merchant');
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")->assertUnauthorized();
});
