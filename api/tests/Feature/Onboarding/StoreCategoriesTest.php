<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
});

/** Authenticates a fresh admin for the current test. */
function categoriesAdmin(): void
{
    test()->actingAs(AdminUser::factory()->create(), 'admin');
}

it('lists the seeded curated categories in sort order with usage counts', function () {
    categoriesAdmin();
    Merchant::factory()->create(['category' => 'grocery']);
    Merchant::factory()->create(['category' => 'grocery']);
    Merchant::factory()->draft()->create(['category' => 'grocery']); // draft does not count

    $data = $this->getJson('/api/admin/store-categories')->assertOk()->json('data');

    expect(collect($data)->pluck('slug')->all())->toBe([
        'grocery', 'restaurant', 'cafe', 'fashion', 'electronics',
        'pharmacy', 'beauty', 'services', 'other',
    ]);

    $grocery = collect($data)->firstWhere('slug', 'grocery');
    expect($grocery['name_en'])->toBe('Grocery')
        ->and($grocery['name_dv'])->not->toBeNull()
        ->and($grocery['active'])->toBeTrue()
        ->and($grocery['active_merchant_count'])->toBe(2);
});

it('creates a category with a well-formed slug only', function () {
    categoriesAdmin();

    $this->postJson('/api/admin/store-categories', [
        'slug' => 'bookshops',
        'name_en' => 'Bookshops',
        'name_dv' => 'ފޮތް ފިހާރަ',
        'sort' => 95,
    ])->assertCreated()
        ->assertJsonPath('data.slug', 'bookshops')
        ->assertJsonPath('data.active', true);

    // Malformed or duplicate slugs are refused.
    $this->postJson('/api/admin/store-categories', ['slug' => 'Bad Slug!', 'name_en' => 'X'])->assertUnprocessable();
    $this->postJson('/api/admin/store-categories', ['slug' => 'grocery', 'name_en' => 'Grocery Again'])->assertUnprocessable();
});

it('updates names, sort and active state — but never the slug', function () {
    categoriesAdmin();

    $category = StoreCategory::query()->where('slug', 'services')->firstOrFail();

    $this->patchJson("/api/admin/store-categories/{$category->id}", [
        'name_en' => 'Professional Services',
        'sort' => 5,
        'active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name_en', 'Professional Services')
        ->assertJsonPath('data.sort', 5)
        ->assertJsonPath('data.active', false);

    // A PATCHed slug is simply not validated in — dropped on the floor.
    $this->patchJson("/api/admin/store-categories/{$category->id}", ['slug' => 'renamed'])->assertOk();
    expect($category->refresh()->slug)->toBe('services');
});

it('blocks deactivating a category in use by ACTIVE merchants with 409', function () {
    categoriesAdmin();

    Merchant::factory()->create(['category' => 'pharmacy']);
    $category = StoreCategory::query()->where('slug', 'pharmacy')->firstOrFail();

    $this->patchJson("/api/admin/store-categories/{$category->id}", ['active' => false])
        ->assertStatus(409)
        ->assertJsonPath('code', 'category_in_use');

    expect($category->refresh()->active)->toBeTrue();

    // Only draft/pending users of the slug: deactivation goes through (those
    // stores re-pick at submit — completeness re-checks the category).
    $draftUsed = StoreCategory::query()->where('slug', 'fashion')->firstOrFail();
    Merchant::factory()->draft()->create(['category' => 'fashion']);

    $this->patchJson("/api/admin/store-categories/{$draftUsed->id}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);
});

it('keeps the category admin surface behind the admin guard', function () {
    $this->getJson('/api/admin/store-categories')->assertUnauthorized();
    $this->postJson('/api/admin/store-categories', ['slug' => 'x', 'name_en' => 'X'])->assertUnauthorized();
});
