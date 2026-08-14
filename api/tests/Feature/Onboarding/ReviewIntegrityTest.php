<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
});

/** A wizard-complete pending_review merchant (200bp live rate), owner attached. */
function reviewFrozenOwner(): MerchantUser
{
    $merchant = Merchant::factory()->pendingReview()->create([
        'category' => 'grocery',
        'channel' => 'in_store',
        'eligibility_basis' => 'Invoice total excluding delivery.',
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subMinute(),
        'effective_to' => null,
    ]);

    return MerchantUser::factory()->for($merchant)->owner()->create();
}

it('reserves approve and reject for superadmins; a plain admin keeps the queue read-only', function () {
    $merchant = reviewFrozenOwner()->merchant;

    // A plain admin reads the queue but cannot move a store through it —
    // approval is the single gate that makes a store publicly live.
    $this->actingAs(AdminUser::factory()->create(), 'admin');

    $this->getJson('/api/admin/store-reviews')->assertOk();
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")->assertForbidden();
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/reject", ['reason' => 'not yours to call'])
        ->assertForbidden();

    expect($merchant->refresh()->status)->toBe('pending_review');

    // A superadmin approves the same store.
    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('freezes profile, rate and promotion writes while the store is under review', function () {
    $owner = reviewFrozenOwner();
    $this->actingAs($owner, 'merchant');

    // Reads stay open — the panel renders the waiting screen from them.
    $this->getJson('/api/merchant/profile')->assertOk();
    $this->getJson('/api/merchant/rate')->assertOk();

    // The settings profile PATCH must not rewrite what the queue reviews.
    $this->patchJson('/api/merchant/profile', [
        'category' => null,
        'eligibility_basis' => null,
        'channel' => 'both',
    ])->assertStatus(409)->assertJsonPath('code', 'store_not_approved');

    // Nor may the §7 rate endpoint reprice the reviewed 200bp.
    $this->postJson('/api/merchant/rate', ['rate_bp' => 1000])
        ->assertStatus(409)->assertJsonPath('code', 'store_not_approved');

    // Nor can promotions be staged to spring live at activation.
    $this->postJson('/api/merchant/promotions', [])
        ->assertStatus(409)->assertJsonPath('code', 'store_not_approved');
    $this->postJson('/api/merchant/promotions/1/publish')
        ->assertStatus(409)->assertJsonPath('code', 'store_not_approved');

    // Everything the admin reviews is exactly as submitted.
    $merchant = $owner->merchant->refresh();
    expect($merchant->category)->toBe('grocery')
        ->and($merchant->channel)->toBe('in_store')
        ->and($merchant->eligibility_basis)->toBe('Invoice total excluding delivery.');

    $rates = MerchantRate::query()->where('merchant_id', $merchant->id)->get();
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->rate_bp)->toBe(200)
        ->and($rates[0]->effective_to)->toBeNull();
});

it('keeps the wizard as the only write path before approval', function (string $status) {
    $owner = reviewFrozenOwner();
    $owner->merchant->update(['status' => $status]);

    $this->actingAs($owner, 'merchant');

    // Draft and rejected stores edit via /merchant/setup/* — whose rate
    // step REPLACES the untraded initial row; this endpoint would append.
    $this->patchJson('/api/merchant/profile', ['channel' => 'both'])
        ->assertStatus(409)->assertJsonPath('code', 'store_not_approved');
    $this->postJson('/api/merchant/rate', ['rate_bp' => 300])
        ->assertStatus(409)->assertJsonPath('code', 'store_not_approved');

    expect(MerchantRate::query()->where('merchant_id', $owner->merchant->id)->count())->toBe(1);
})->with(['draft', 'rejected']);

it('re-validates completeness at approval instead of activating a gutted store', function () {
    $merchant = reviewFrozenOwner()->merchant;

    // Simulate reviewed-state drift the HTTP write-freeze can no longer
    // produce: the reviewed fields vanish while the store sits in the queue.
    $merchant->forceFill(['category' => null, 'eligibility_basis' => null])->save();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'setup_incomplete');

    // Still pending — and still invisible publicly.
    expect($merchant->refresh()->status)->toBe('pending_review');
    $this->getJson('/api/discover/merchants/'.$merchant->slug)->assertNotFound();
});

it('refuses to activate a store whose category was retired while it waited', function () {
    $merchant = reviewFrozenOwner()->merchant;

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    // No ACTIVE merchant holds 'grocery', so the category guard allows the
    // deactivation while this store is only pending...
    $categoryId = StoreCategory::query()->where('slug', 'grocery')->value('id');
    $this->patchJson("/api/admin/store-categories/{$categoryId}", ['active' => false])->assertOk();

    // ...so approval itself must be the backstop.
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'setup_incomplete');

    expect($merchant->refresh()->status)->toBe('pending_review');
});
