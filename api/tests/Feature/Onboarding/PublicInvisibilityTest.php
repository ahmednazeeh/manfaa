<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The public-safety matrix: every non-active status must be invisible on
 * EVERY public surface — directory, name search, category filter, store
 * detail, and all four discovery sections — even with a live rate, a
 * branch, the featured flag and an online channel begging for attention.
 */
function invisibleFixture(string $status): Merchant
{
    $merchant = Merchant::factory()->create([
        'name' => 'Shadow Store',
        'slug' => 'shadow-store',
        'status' => $status,
        'featured' => true,
        'channel' => 'both',
        'category' => 'grocery',
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 500,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    MerchantBranch::factory()->for($merchant)->create(['lat' => 4.1752, 'lng' => 73.5089]);

    return $merchant;
}

it('hides every non-active status from every public surface', function (string $status) {
    invisibleFixture($status);
    Cache::flush();

    // Directory, search, category filter.
    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/discover/merchants?q=Shadow')->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/discover/merchants?category=grocery')->assertOk()->json('meta.total'))->toBe(0);

    // The category derived from a hidden store never leaks into the filter list.
    expect($this->getJson('/api/discover/merchants')->json('meta.categories'))->toBe([]);

    // Every shelf, coordinates supplied so nearby would fire — and the
    // category rail, which must not raise a chip for a hidden store's
    // category either.
    $sections = $this->getJson('/api/discover?lat=4.1752&lng=73.5089')->assertOk()->json('data');
    expect($sections)->toBe([
        'featured' => [],
        'increased' => [],
        'nearby' => [],
        'in_store' => [],
        'online' => [],
        'recently_added' => [],
        'top_cashback' => [],
        'categories' => [],
    ]);

    // Store page: 404, byte-identical to a slug that never existed. Debug
    // off for the comparison — the debug body embeds the test call-site's
    // stack trace; production serves the plain body compared here.
    config(['app.debug' => false]);
    $hidden = $this->getJson('/api/discover/merchants/shadow-store')->assertNotFound();
    $missing = $this->getJson('/api/discover/merchants/never-was-a-store')->assertNotFound();

    expect($hidden->getContent())->toBe($missing->getContent());
    expect($hidden->getStatusCode())->toBe($missing->getStatusCode());
})->with(['draft', 'pending_review', 'rejected', 'suspended', 'closed']);

it('lists the same fixture the moment it is active — the matrix is about status alone', function () {
    invisibleFixture('active');
    Cache::flush();

    $directory = $this->getJson('/api/discover/merchants')->assertOk();
    expect($directory->json('meta.total'))->toBe(1);
    expect($directory->json('data.0.slug'))->toBe('shadow-store');
    expect($directory->json('data.0.channel'))->toBe('both');
    expect($directory->json('meta.categories'))->toBe(['grocery']);

    $sections = $this->getJson('/api/discover?lat=4.1752&lng=73.5089')->assertOk()->json('data');
    expect(collect($sections['featured'])->pluck('slug'))->toContain('shadow-store');
    expect(collect($sections['online'])->pluck('slug'))->toContain('shadow-store');
    // Channel `both` earns either way, so it sits on both channel shelves.
    expect(collect($sections['in_store'])->pluck('slug'))->toContain('shadow-store');
    expect(collect($sections['nearby'])->pluck('slug'))->toContain('shadow-store');
    expect(collect($sections['recently_added'])->pluck('slug'))->toContain('shadow-store');
    expect($sections['featured'][0]['channel'])->toBe('both');

    // The rail raises the chip the same moment — one live store behind it.
    expect($sections['categories'])->toBe([
        ['slug' => 'grocery', 'name_en' => 'Grocery', 'name_dv' => 'ގުރޮސަރީ', 'icon' => 'shopping-cart', 'icon_url' => null, 'merchant_count' => 1],
    ]);

    $this->getJson('/api/discover/merchants/shadow-store')->assertOk()
        ->assertJsonPath('data.channel', 'both');
});
