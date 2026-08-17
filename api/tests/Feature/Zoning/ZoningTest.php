<?php

use App\Domain\Zoning\PointInPolygon;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A tight square around Malé's core: lat 4.16..4.18, lng 73.49..73.52.
function maleSquare(): array
{
    return [
        ['lat' => 4.16, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.52],
        ['lat' => 4.16, 'lng' => 73.52],
    ];
}

// A square around Hulhumalé: lat 4.20..4.24, lng 73.53..73.56.
function hulhumaleSquare(): array
{
    return [
        ['lat' => 4.20, 'lng' => 73.53],
        ['lat' => 4.24, 'lng' => 73.53],
        ['lat' => 4.24, 'lng' => 73.56],
        ['lat' => 4.20, 'lng' => 73.56],
    ];
}

function listedMerchantWithBranch(string $name, float $lat, float $lng): Merchant
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00+05:00'),
        'effective_to' => null,
    ]);
    MerchantBranch::query()->create([
        'merchant_id' => $merchant->id,
        'name' => "$name main",
        'lat' => $lat,
        'lng' => $lng,
    ]);

    return $merchant;
}

it('ray-casting answers inside, outside and far away', function () {
    $poly = maleSquare();

    expect(PointInPolygon::contains(4.17, 73.50, $poly))->toBeTrue()
        ->and(PointInPolygon::contains(4.19, 73.50, $poly))->toBeFalse()
        ->and(PointInPolygon::contains(-4.17, 73.50, $poly))->toBeFalse()
        ->and(PointInPolygon::contains(4.17, 73.60, $poly))->toBeFalse();
});

it('assigns a branch to the containing zone on save, and clears it when unpinned', function () {
    $zone = Zone::create(['name' => 'Malé', 'polygon' => maleSquare()]);

    $merchant = Merchant::factory()->create();
    $branch = MerchantBranch::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Main',
        'lat' => 4.17,
        'lng' => 73.50,
    ]);

    expect($branch->zone_id)->toBe($zone->id);

    // Move the pin off the island → out of the zone.
    $branch->update(['lat' => 4.30, 'lng' => 73.50]);
    expect($branch->refresh()->zone_id)->toBeNull();

    // Remove the pin entirely → zoneless by definition.
    $branch->update(['lat' => null, 'lng' => null]);
    expect($branch->refresh()->zone_id)->toBeNull();
});

it('admin zone CRUD reassigns existing branches and requires auth', function () {
    $merchant = Merchant::factory()->create();
    $branch = MerchantBranch::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Main',
        'lat' => 4.17,
        'lng' => 73.50,
    ]);
    expect($branch->zone_id)->toBeNull();

    // Unauthenticated writes are refused outright.
    $this->postJson('/api/admin/zones', ['name' => 'Malé', 'polygon' => maleSquare()])
        ->assertUnauthorized();

    $this->actingAs(AdminUser::factory()->create(), 'admin');

    // Creating the zone sweeps the pre-existing branch into it.
    $zoneId = $this->postJson('/api/admin/zones', [
        'name' => 'Malé',
        'polygon' => maleSquare(),
    ])->assertCreated()->json('data.id');

    expect($branch->refresh()->zone_id)->toBe($zoneId);

    // A polygon with fewer than 3 points is not a shape.
    $this->postJson('/api/admin/zones', [
        'name' => 'Broken',
        'polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2]],
    ])->assertUnprocessable();

    // Redrawing the zone somewhere else releases the branch.
    $this->putJson("/api/admin/zones/{$zoneId}", [
        'name' => 'Hulhumalé',
        'polygon' => hulhumaleSquare(),
    ])->assertOk();
    expect($branch->refresh()->zone_id)->toBeNull();

    // The list shows the honest branch count.
    $list = $this->getJson('/api/admin/zones')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0]['branch_count'])->toBe(0);

    // Deleting nulls without dropping branches.
    $this->deleteJson("/api/admin/zones/{$zoneId}")->assertNoContent();
    expect(MerchantBranch::query()->count())->toBe(1)
        ->and(Zone::query()->count())->toBe(0);
});

it('the public zone list carries listed-store counts and the feed filters by zone', function () {
    listedMerchantWithBranch('Malé Mart', 4.17, 73.50);
    listedMerchantWithBranch('Hulhumalé Café', 4.22, 73.54);

    $this->actingAs(AdminUser::factory()->create(), 'admin');
    $male = $this->postJson('/api/admin/zones', [
        'name' => 'Malé', 'polygon' => maleSquare(),
    ])->assertCreated()->json('data.id');
    $hulhumale = $this->postJson('/api/admin/zones', [
        'name' => 'Hulhumalé', 'name_dv' => 'ހުޅުމާލެ', 'polygon' => hulhumaleSquare(),
    ])->assertCreated()->json('data.id');

    // Public list: both islands, one store each, dv name where supplied.
    $zones = $this->getJson('/api/discover/zones')->assertOk()->json('data');
    expect($zones)->toHaveCount(2);
    $bySlugName = collect($zones)->keyBy('name');
    expect($bySlugName['Malé']['store_count'])->toBe(1)
        ->and($bySlugName['Hulhumalé']['store_count'])->toBe(1)
        ->and($bySlugName['Hulhumalé']['name_dv'])->toBe('ހުޅުމާލެ');

    // The zone filter narrows every shelf to the island's stores.
    $feed = $this->getJson("/api/discover?zone={$male}")->assertOk()->json('data');
    $names = collect($feed['recently_added'])->pluck('name');
    expect($names)->toContain('Malé Mart')->not->toContain('Hulhumalé Café');

    $feed = $this->getJson("/api/discover?zone={$hulhumale}")->assertOk()->json('data');
    $names = collect($feed['recently_added'])->pluck('name');
    expect($names)->toContain('Hulhumalé Café')->not->toContain('Malé Mart');

    // No filter → everyone.
    $feed = $this->getJson('/api/discover')->assertOk()->json('data');
    expect(collect($feed['recently_added'])->pluck('name'))
        ->toContain('Malé Mart')
        ->toContain('Hulhumalé Café');
});

it('orders zones by the admin-arranged order, added order by default', function () {
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $male = Zone::create(['name' => 'Malé', 'polygon' => maleSquare(), 'sort_order' => 1]);
    $hulhumale = Zone::create(['name' => 'Hulhumalé', 'polygon' => hulhumaleSquare(), 'sort_order' => 2]);

    // Added order by default — alphabetical would put Hulhumalé first.
    $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/zones')
        ->assertOk()
        ->assertJsonPath('data.0.id', $male->id)
        ->assertJsonPath('data.1.id', $hulhumale->id);

    // The admin rearranges; the list answers in the new order.
    $this->actingAs($admin, 'admin')
        ->putJson('/api/admin/zones/order', ['ids' => [$hulhumale->id, $male->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $hulhumale->id)
        ->assertJsonPath('data.1.id', $male->id);

    // The public picker list mirrors the arrangement verbatim.
    $public = $this->getJson('/api/discover/zones')->assertOk();
    expect($public->json('data.0.id'))->toBe($hulhumale->id)
        ->and($public->json('data.1.id'))->toBe($male->id);

    // A partial order is refused whole — never two admins' halves merged.
    $this->actingAs($admin, 'admin')
        ->putJson('/api/admin/zones/order', ['ids' => [$male->id]])
        ->assertStatus(422);

    // A NEW zone joins at the end of the arranged order, not the middle.
    $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/zones', ['name' => 'Villimalé', 'polygon' => maleSquare()])
        ->assertCreated();

    $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/zones')
        ->assertOk()
        ->assertJsonPath('data.2.name', 'Villimalé');
});
