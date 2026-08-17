<?php

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The admin featured toggle behind the public "featured" shelf. The shelf's
 * dataset is cached for 60 seconds, so the tests below deliberately READ
 * FIRST to prime the cache — a green run proves the toggle busts it
 * (DiscoveryService::forgetMerchant), not merely that a cold build sees the
 * new flag.
 */
function featuredShelfStore(string $name): Merchant
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00+05:00'),
        'effective_to' => null,
    ]);

    return $merchant;
}

it('refuses the featured toggle to guests', function () {
    $merchant = featuredShelfStore('Kaanu Mart');

    $this->putJson("/api/admin/merchants/{$merchant->id}/featured", ['featured' => true])
        ->assertUnauthorized();

    // And the refusal changed nothing.
    expect($merchant->refresh()->featured)->toBeFalse();
});

it('featuring a store puts it on the public shelf immediately, and unfeaturing removes it', function () {
    $merchant = featuredShelfStore('Kaanu Mart');

    // Prime the discovery cache with the store un-featured.
    $shelf = $this->getJson('/api/discover')->assertOk()->json('data.featured');
    expect(collect($shelf)->pluck('name'))->not->toContain('Kaanu Mart');

    $admin = AdminUser::factory()->create();

    // Flip on: the very next public read must carry the store — within the
    // cache TTL, so only an explicit bust can make this pass.
    $this->actingAs($admin, 'admin')
        ->putJson("/api/admin/merchants/{$merchant->id}/featured", ['featured' => true])
        ->assertOk()
        ->assertJsonPath('data.id', $merchant->id)
        ->assertJsonPath('data.featured', true);

    $shelf = $this->getJson('/api/discover')->assertOk()->json('data.featured');
    expect(collect($shelf)->pluck('name'))->toContain('Kaanu Mart');

    // Flip off: gone again, just as immediately.
    $this->actingAs($admin, 'admin')
        ->putJson("/api/admin/merchants/{$merchant->id}/featured", ['featured' => false])
        ->assertOk()
        ->assertJsonPath('data.featured', false);

    $shelf = $this->getJson('/api/discover')->assertOk()->json('data.featured');
    expect(collect($shelf)->pluck('name'))->not->toContain('Kaanu Mart');
});

it('validates the flag and exposes the current value on the admin index', function () {
    $merchant = featuredShelfStore('Kaanu Mart');
    $admin = AdminUser::factory()->create();

    // The body is a boolean or the request is refused whole.
    $this->actingAs($admin, 'admin')
        ->putJson("/api/admin/merchants/{$merchant->id}/featured", [])
        ->assertUnprocessable();
    $this->actingAs($admin, 'admin')
        ->putJson("/api/admin/merchants/{$merchant->id}/featured", ['featured' => 'maybe'])
        ->assertUnprocessable();

    // The index tells the admin panel what the toggle currently holds.
    $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/merchants')
        ->assertOk()
        ->assertJsonPath('data.0.featured', false);

    $this->actingAs($admin, 'admin')
        ->putJson("/api/admin/merchants/{$merchant->id}/featured", ['featured' => true])
        ->assertOk();

    $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/merchants')
        ->assertOk()
        ->assertJsonPath('data.0.featured', true);
});
