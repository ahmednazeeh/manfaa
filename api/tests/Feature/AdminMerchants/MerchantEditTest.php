<?php

use App\Domain\Discovery\DiscoveryService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects unauthenticated and non-superadmin edits', function () {
    $merchant = Merchant::factory()->create(['name' => 'Before']);

    $this->patchJson("/api/admin/merchants/{$merchant->id}", ['name' => 'After'])
        ->assertUnauthorized();

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", ['name' => 'After'])
        ->assertForbidden();

    expect($merchant->refresh()->name)->toBe('Before');
});

it('lets a superadmin edit the profile, persists every field and echoes the fresh record', function () {
    // 'cafe' is a seeded curated category (store_categories migration).
    $merchant = Merchant::factory()->create([
        'name' => 'Old Name',
        'category' => 'grocery',
        'channel' => 'in_store',
    ]);
    $slug = $merchant->slug;

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", [
            'name' => 'Chai House',
            'name_dv' => 'ޗައި ހައުސް',
            'category' => 'cafe',
            'channel' => 'both',
            'eligibility_basis' => 'Invoice total excluding GST.',
            'contact_email' => 'owner@chaihouse.mv',
            'contact_phone' => '+9607779999',
            'support_phone' => '+9603339999',
            'website_url' => 'https://chaihouse.mv',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Chai House')
        ->assertJsonPath('data.name_dv', 'ޗައި ހައުސް')
        ->assertJsonPath('data.category', 'cafe')
        ->assertJsonPath('data.channel', 'both')
        ->assertJsonPath('data.contact_email', 'owner@chaihouse.mv')
        // The slug never moves with a rename — it is the address on every
        // printed QR card in circulation.
        ->assertJsonPath('data.slug', $slug);

    $merchant->refresh();
    expect($merchant->name)->toBe('Chai House')
        ->and($merchant->category)->toBe('cafe')
        ->and($merchant->channel)->toBe('both')
        ->and($merchant->slug)->toBe($slug);
});

it('drops the public discovery read model on save', function () {
    $merchant = Merchant::factory()->create();

    Cache::put(DiscoveryService::CACHE_KEY, ['entries' => [], 'categories' => []], 60);
    Cache::put(DiscoveryService::STORE_CACHE_PREFIX.$merchant->slug, ['name' => 'stale'], 60);

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", ['name' => 'Fresh Name'])
        ->assertOk();

    expect(Cache::has(DiscoveryService::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(DiscoveryService::STORE_CACHE_PREFIX.$merchant->slug))->toBeFalse();
});

it('validates like the merchant profile save: curated categories only, known channels only', function () {
    StoreCategory::query()->create(['slug' => 'retired-cat', 'name_en' => 'Retired', 'active' => false]);

    // A category that was never (or is no longer) a live curated slug.
    $merchant = Merchant::factory()->create(['category' => 'legacy-unlisted']);
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", ['category' => 'no-such-category'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", ['category' => 'retired-cat'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", ['channel' => 'metaverse'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('channel');

    // The value the store ALREADY holds is always accepted, even when the
    // category is not (or no longer) a live curated slug — a retirement must
    // stay advisory, never a trap that blocks saving a phone number.
    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$merchant->id}", [
            'category' => 'legacy-unlisted',
            'contact_phone' => '+9607770000',
        ])
        ->assertOk()
        ->assertJsonPath('data.category', 'legacy-unlisted')
        ->assertJsonPath('data.contact_phone', '+9607770000');
});
