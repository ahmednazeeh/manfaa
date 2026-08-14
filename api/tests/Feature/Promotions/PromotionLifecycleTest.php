<?php

declare(strict_types=1);

use App\Domain\Promotions\PromotionNotDraftException;
use App\Domain\Promotions\PromotionService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promotionPayload(array $overrides = []): array
{
    return [
        'rate_bp' => 500,
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(8)->toIso8601String(),
        'min_purchase_laari' => 100000,
        'max_cashback_per_customer_laari' => 30000,
        ...$overrides,
    ];
}

it('creates a draft with the §4 all-in cost preview (fee follows the promo tier)', function () {
    $this->postJson('/api/merchant/promotions', promotionPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.fee_bp', 100)
        ->assertJsonPath('data.all_in_bp', 600)
        ->assertJsonPath('data.min_purchase_laari', 100000)
        ->assertJsonPath('data.max_cashback_per_customer_laari', 30000)
        ->assertJsonPath('data.is_live', false)
        // §4 cliff data: promo terms vs the standing terms at window start.
        ->assertJsonPath('cost_preview.promo.rate_bp', 500)
        ->assertJsonPath('cost_preview.promo.fee_bp', 100)
        ->assertJsonPath('cost_preview.promo.all_in_bp', 600)
        ->assertJsonPath('cost_preview.standing.rate_bp', 200)
        ->assertJsonPath('cost_preview.standing.fee_bp', 75)
        ->assertJsonPath('cost_preview.standing.all_in_bp', 275)
        ->assertJsonPath('cost_preview.all_in_delta_bp', 325)
        ->assertJsonPath('cost_preview.tier_changed', true);

    expect(Promotion::query()->sole()->status)->toBe('draft');
});

it('rejects a rate outside the structural 50–2000 bp bounds', function () {
    $this->postJson('/api/merchant/promotions', promotionPayload(['rate_bp' => 49]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rate_bp');

    // 2001 breaches the structural cap; 1001–2000 are structurally legal
    // but refused as rate_not_priced while the seeded 50–1000 schedule is
    // active (tests/Feature/Platform/CapWideningTest.php).
    $this->postJson('/api/merchant/promotions', promotionPayload(['rate_bp' => 2001]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rate_bp');

    expect(Promotion::query()->count())->toBe(0);
});

it('rejects a window that ends before it starts', function () {
    $this->postJson('/api/merchant/promotions', promotionPayload([
        'starts_at' => now()->addDays(8)->toIso8601String(),
        'ends_at' => now()->addDay()->toIso8601String(),
    ]))->assertUnprocessable();

    expect(Promotion::query()->count())->toBe(0);
});

it('rejects a promo rate that does not EXCEED the standing rate — a boost, never a stealth decrease', function () {
    // Equal to standing: not a boost.
    $this->postJson('/api/merchant/promotions', promotionPayload(['rate_bp' => 200]))
        ->assertUnprocessable();

    // Below standing: a decrease in promo clothing — decreases have their
    // own §7 path (next business midnight), never this one.
    $this->postJson('/api/merchant/promotions', promotionPayload(['rate_bp' => 100]))
        ->assertUnprocessable();

    expect(Promotion::query()->count())->toBe(0);
});

it('rejects a branch that belongs to another merchant', function () {
    $foreignBranch = MerchantBranch::factory()->create();

    $this->postJson('/api/merchant/promotions', promotionPayload(['branch_id' => $foreignBranch->id]))
        ->assertUnprocessable();

    expect(Promotion::query()->count())->toBe(0);
});

it('forbids staff from creating promotions — owner only, like a rate change', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create(['role' => 'staff']);

    $this->actingAs($staff, 'merchant')
        ->postJson('/api/merchant/promotions', promotionPayload())
        ->assertForbidden();
});

it('publishes a draft, stamping published_at', function () {
    $id = $this->postJson('/api/merchant/promotions', promotionPayload())->json('data.id');

    $this->postJson("/api/merchant/promotions/{$id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $promotion = Promotion::query()->sole();

    expect($promotion->status)->toBe('published')
        ->and($promotion->published_at)->not->toBeNull();
});

it('refuses to publish a draft whose window has already started', function () {
    // Publishing freezes the window, so it must lie entirely in the future.
    $promotion = Promotion::query()->create([
        'merchant_id' => $this->merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->postJson("/api/merchant/promotions/{$promotion->id}/publish")->assertUnprocessable();

    expect($promotion->refresh()->status)->toBe('draft');
});

it('forbids every edit after publish: no update route, republish 409, domain refuses mutation', function () {
    $id = $this->postJson('/api/merchant/promotions', promotionPayload())->json('data.id');
    $this->postJson("/api/merchant/promotions/{$id}/publish")->assertOk();

    // No update surface exists at all — the URI is not even routed (404,
    // not 405: no verb whatsoever is registered on /promotions/{id}).
    $this->putJson("/api/merchant/promotions/{$id}", ['rate_bp' => 600])->assertNotFound();
    $this->patchJson("/api/merchant/promotions/{$id}", ['rate_bp' => 600])->assertNotFound();

    // Re-publishing a published promotion is refused.
    $this->postJson("/api/merchant/promotions/{$id}/publish")->assertConflict();

    // And the domain itself refuses any mutation of a non-draft.
    $promotion = Promotion::query()->findOrFail($id);
    expect(fn () => app(PromotionService::class)->cancelDraft($promotion))
        ->toThrow(PromotionNotDraftException::class)
        ->and(fn () => app(PromotionService::class)->publish($promotion))
        ->toThrow(PromotionNotDraftException::class);

    expect($promotion->refresh()->status)->toBe('published')
        ->and($promotion->rate_bp)->toBe(500);
});

it('forbids ending a published promotion early — cancel answers 409 for its whole duration', function () {
    $id = $this->postJson('/api/merchant/promotions', promotionPayload())->json('data.id');
    $this->postJson("/api/merchant/promotions/{$id}/publish")->assertOk();

    $this->postJson("/api/merchant/promotions/{$id}/cancel")->assertConflict();

    expect(Promotion::query()->findOrFail($id)->status)->toBe('published');
});

it('cancels a draft', function () {
    $id = $this->postJson('/api/merchant/promotions', promotionPayload())->json('data.id');

    $this->postJson("/api/merchant/promotions/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $promotion = Promotion::query()->sole();

    expect($promotion->status)->toBe('cancelled')
        ->and($promotion->cancelled_at)->not->toBeNull();
});

it('scopes the merchant list to the merchant\'s own promotions', function () {
    $this->postJson('/api/merchant/promotions', promotionPayload())->assertCreated();

    $other = Merchant::factory()->create();
    Promotion::query()->create([
        'merchant_id' => $other->id,
        'rate_bp' => 300,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(3),
        'status' => 'draft',
    ]);

    $this->getJson('/api/merchant/promotions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.merchant_id', $this->merchant->id);

    // Another merchant's promotion is invisible to the lifecycle routes too.
    $foreign = Promotion::query()->where('merchant_id', $other->id)->sole();
    $this->postJson("/api/merchant/promotions/{$foreign->id}/publish")->assertNotFound();
});

it('lists all promotions for admin, filterable by merchant, status and liveness', function () {
    $id = $this->postJson('/api/merchant/promotions', promotionPayload([
        'starts_at' => now()->addMinute()->toIso8601String(),
    ]))->json('data.id');
    $this->postJson("/api/merchant/promotions/{$id}/publish")->assertOk();

    $other = Merchant::factory()->create();
    Promotion::query()->create([
        'merchant_id' => $other->id,
        'rate_bp' => 300,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(3),
        'status' => 'draft',
    ]);

    $this->actingAs(AdminUser::factory()->create(), 'admin');

    $this->getJson('/api/admin/promotions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/admin/promotions?status=published')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);

    $this->getJson("/api/admin/promotions?merchant_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'draft');

    // Not live yet (starts in a minute); live once the window opens.
    $this->getJson('/api/admin/promotions?live=1')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->travelTo(now()->addMinutes(2));

    $this->getJson('/api/admin/promotions?live=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.is_live', true);
});

it('refuses the admin listing to merchant sessions', function () {
    $this->getJson('/api/admin/promotions')->assertUnauthorized();
});
