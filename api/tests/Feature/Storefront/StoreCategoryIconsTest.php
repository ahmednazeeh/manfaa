<?php

use App\Domain\Storefront\StoreCategoryIcon;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\StoreCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A category's iconography is a PAIR: uploaded artwork (icon_url) drawn in
 * preference to a curated glyph name (icon), which is itself drawn in
 * preference to the client's neutral fallback. These cover the glyph half —
 * a closed list, because the storefront maps each name to a statically
 * imported component, so a name outside it would reach the rail as a blank
 * tile. The upload half is covered at the bottom of this file.
 */
it('refuses an icon outside the curated list', function () {
    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson('/api/admin/store-categories', [
            'slug' => 'toys',
            'name_en' => 'Toys',
            'icon' => 'https://example.com/toys.png',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('icon');

    $category = StoreCategory::query()->where('slug', 'grocery')->firstOrFail();

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->patchJson("/api/admin/store-categories/{$category->id}", ['icon' => 'not-a-lucide-name'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('icon');

    expect($category->refresh()->icon)->toBe('shopping-cart');
});

it('stores a curated icon and clears it back to null', function () {
    $admin = AdminUser::factory()->create();
    $category = StoreCategory::query()->where('slug', 'grocery')->firstOrFail();

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/store-categories/{$category->id}", ['icon' => 'shopping-bag'])
        ->assertOk()
        ->assertJsonPath('data.icon', 'shopping-bag');

    // Explicit null is how an admin says "no icon" — the rail then draws its
    // own neutral glyph, which is not the same as leaving the field alone.
    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/store-categories/{$category->id}", ['icon' => null])
        ->assertOk()
        ->assertJsonPath('data.icon', null);

    expect($category->refresh()->icon)->toBeNull();
});

it('carries the icon onto the public category rail', function () {
    $merchant = Merchant::factory()->create([
        'status' => 'active',
        'category' => 'grocery',
        'approved_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);
    MerchantRate::factory()->create([
        'merchant_id' => $merchant->id,
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subDays(2),
    ]);

    $this->getJson('/api/discover')
        ->assertOk()
        ->assertJsonPath('data.categories.0.slug', 'grocery')
        ->assertJsonPath('data.categories.0.icon', 'shopping-cart');
});

/**
 * The seeded rows carry the icons the storefront used to hardcode, so the
 * rail looks identical the moment the column lands — the feature adds admin
 * CONTROL, it does not change what a visitor sees today.
 */
it('backfilled every seeded category with the icon the client hardcoded', function () {
    $icons = StoreCategory::query()->pluck('icon', 'slug');

    expect($icons['grocery'])->toBe('shopping-cart')
        ->and($icons['restaurant'])->toBe('utensils-crossed')
        ->and($icons['cafe'])->toBe('coffee')
        ->and($icons['fashion'])->toBe('shirt')
        ->and($icons['electronics'])->toBe('smartphone')
        ->and($icons['pharmacy'])->toBe('pill')
        ->and($icons['beauty'])->toBe('flower-2')
        ->and($icons['services'])->toBe('wrench')
        ->and($icons['other'])->toBe('package');

    // Every backfilled value must itself be selectable in admin, or an admin
    // editing an untouched category would be shown a value it cannot re-pick.
    foreach ($icons as $icon) {
        expect(StoreCategory::ICONS)->toContain($icon);
    }
});

it('keeps the icon list free of duplicates', function () {
    expect(array_unique(StoreCategory::ICONS))->toHaveCount(count(StoreCategory::ICONS));
});

it('uploads artwork, publishes it on the rail and falls back to the glyph when cleared', function () {
    Storage::fake(StoreCategoryIcon::DISK);

    $merchant = Merchant::factory()->create([
        'status' => 'active',
        'category' => 'grocery',
        'approved_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);
    MerchantRate::factory()->create([
        'merchant_id' => $merchant->id,
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subDays(2),
    ]);

    $admin = AdminUser::factory()->create();
    $category = StoreCategory::query()->where('slug', 'grocery')->firstOrFail();

    // Warm the rail so the upload has a stale read model to invalidate.
    $this->getJson('/api/discover')->assertOk()->assertJsonPath('data.categories.0.icon_url', null);

    $response = $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-categories/{$category->id}/icon", [
            'icon' => UploadedFile::fake()->image('grocery.png', 128, 128),
        ])
        ->assertOk();

    $iconUrl = $response->json('data.icon_url');
    expect($iconUrl)->toContain('/api/store-categories/grocery/icon?v=');

    Storage::disk(StoreCategoryIcon::DISK)->assertExists($category->refresh()->icon_path);

    // Live on the rail immediately — not after the 60-second TTL.
    $this->getJson('/api/discover')
        ->assertOk()
        ->assertJsonPath('data.categories.0.icon_url', $iconUrl)
        // The glyph name survives underneath as the fallback.
        ->assertJsonPath('data.categories.0.icon', 'shopping-cart');

    // Public and anonymous: the rail paints on the signed-out landing page.
    $this->get('/api/store-categories/grocery/icon')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $previousPath = $category->refresh()->icon_path;

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/admin/store-categories/{$category->id}/icon")
        ->assertOk()
        ->assertJsonPath('data.icon_url', null)
        ->assertJsonPath('data.icon', 'shopping-cart');

    Storage::disk(StoreCategoryIcon::DISK)->assertMissing($previousPath);
    $this->get('/api/store-categories/grocery/icon')->assertNotFound();
});

it('refuses an upload that is not a raster image the rail can draw', function () {
    Storage::fake(StoreCategoryIcon::DISK);

    $admin = AdminUser::factory()->create();
    $category = StoreCategory::query()->where('slug', 'grocery')->firstOrFail();

    // An SVG would be a document served from our own origin — stored XSS.
    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-categories/{$category->id}/icon", [
            'icon' => UploadedFile::fake()->create('grocery.svg', 4, 'image/svg+xml'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('icon');

    // Too small to render crisply in the rail tile.
    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-categories/{$category->id}/icon", [
            'icon' => UploadedFile::fake()->image('tiny.png', 16, 16),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('icon');

    expect($category->refresh()->icon_path)->toBeNull();
});
