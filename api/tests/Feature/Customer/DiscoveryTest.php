<?php

declare(strict_types=1);

use App\Domain\Discovery\DiscoveryService;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Malé ≈ (4.1752, 73.5089); Hulhumalé ≈ (4.2105, 73.5401) — about 5.2 km
 * apart; Addu ≈ (-0.63, 73.16) — hundreds of km south.
 */
function discoveryMerchant(array $merchant, int $rateBp, ?array $coords = null): Merchant
{
    $m = Merchant::factory()->create($merchant);
    MerchantRate::factory()->for($m)->create([
        'rate_bp' => $rateBp,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    if ($coords !== null) {
        MerchantBranch::factory()->for($m)->create(['lat' => $coords[0], 'lng' => $coords[1]]);
    }

    return $m;
}

function discoveryFixture(): array
{
    $alpha = discoveryMerchant(
        ['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha', 'featured' => true, 'category' => 'cafe'],
        200,
        [4.1752, 73.5089], // Malé
    );

    $beta = discoveryMerchant(
        ['name' => 'Hulhu Beta', 'slug' => 'hulhu-beta', 'channel' => 'online', 'category' => 'grocery'],
        100,
        [4.2105, 73.5401], // Hulhumalé
    );
    Promotion::query()->create([
        'merchant_id' => $beta->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);

    // Active but far south — never "nearby" from Malé.
    $gamma = discoveryMerchant(
        ['name' => 'Far Gamma', 'slug' => 'far-gamma', 'featured' => true],
        300,
        [-0.63, 73.16], // Addu
    );

    // Suspended — invisible everywhere despite flags and a live rate.
    discoveryMerchant(
        ['name' => 'Sus Delta', 'slug' => 'sus-delta', 'status' => 'suspended', 'featured' => true, 'channel' => 'online'],
        400,
        [4.1752, 73.5089],
    );

    // Active but without a standing rate — nothing to offer, not listed.
    Merchant::factory()->create(['name' => 'NoRate Epsilon', 'slug' => 'norate-epsilon', 'featured' => true]);

    // Draft and expired promos never boost anyone.
    Promotion::query()->create([
        'merchant_id' => $alpha->id,
        'rate_bp' => 900,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'draft',
    ]);
    Promotion::query()->create([
        'merchant_id' => $alpha->id,
        'rate_bp' => 900,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'status' => 'published',
    ]);

    return [$alpha, $beta, $gamma];
}

it('builds the featured, increased and online sections without auth', function () {
    discoveryFixture();

    $data = $this->getJson('/api/discover')
        ->assertOk()
        ->json('data');

    expect(collect($data['featured'])->pluck('slug')->all())
        ->toBe(['cafe-alpha', 'far-gamma']);

    // Increased: only Beta's live PUBLISHED promo counts — boosted rate now,
    // "usually" the standing rate, with the promo end shown.
    expect($data['increased'])->toHaveCount(1);
    $beta = $data['increased'][0];
    expect($beta['slug'])->toBe('hulhu-beta');
    expect($beta['cashback_rate_percent'])->toBe('5.00');
    expect($beta['standing_cashback_rate_percent'])->toBe('1.00');
    expect($beta['promo_ends_at'])->not->toBeNull();

    // Alpha's draft/expired promos never boosted it.
    $alpha = collect($data['featured'])->firstWhere('slug', 'cafe-alpha');
    expect($alpha['cashback_rate_percent'])->toBe('2.00');
    expect($alpha['promo_ends_at'])->toBeNull();

    expect(collect($data['online'])->pluck('slug')->all())->toBe(['hulhu-beta']);

    // Without coordinates there is no nearby section content.
    expect($data['nearby'])->toBe([]);

    // Suspended and rate-less merchants appear nowhere.
    $everySlug = collect($data)->flatten(1)->pluck('slug');
    expect($everySlug)->not->toContain('sus-delta');
    expect($everySlug)->not->toContain('norate-epsilon');
});

it('keeps branch-scoped promotions off the increased shelf and off the cards', function () {
    // A branch-scoped promo earns only at its own branch at sale time
    // (PromotionResolver), so the sections must not advertise it
    // merchant-wide — the displayed rate may only under-promise.
    $merchant = discoveryMerchant(
        ['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha', 'featured' => true],
        200,
    );
    $branch = MerchantBranch::factory()->for($merchant)->create(['name' => 'Hulhumalé Branch']);
    Promotion::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);

    $data = $this->getJson('/api/discover')->assertOk()->json('data');

    expect($data['increased'])->toBe([]);

    $card = collect($data['featured'])->firstWhere('slug', 'cafe-alpha');
    expect($card['cashback_rate_percent'])->toBe('2.00');
    expect($card['promo_ends_at'])->toBeNull();
});

it('orders nearby by haversine distance within 10 km', function () {
    discoveryFixture();

    // Standing just north-east of Malé centre: Alpha is nearer than Beta.
    $nearby = $this->getJson('/api/discover?lat=4.1770&lng=73.5100')
        ->assertOk()
        ->json('data.nearby');

    expect(collect($nearby)->pluck('slug')->all())->toBe(['cafe-alpha', 'hulhu-beta']);

    // Distances are integer metres, ascending, and sane: Alpha a few hundred
    // metres, Beta a few kilometres.
    expect($nearby[0]['distance_m'])->toBeInt()->toBeLessThan(1000);
    expect($nearby[1]['distance_m'])->toBeGreaterThan($nearby[0]['distance_m']);
    expect($nearby[1]['distance_m'])->toBeLessThan(10000);

    // From Hulhumalé, Beta comes first.
    $fromHulhumale = $this->getJson('/api/discover?lat=4.2105&lng=73.5401')
        ->assertOk()
        ->json('data.nearby');
    expect(collect($fromHulhumale)->pluck('slug')->first())->toBe('hulhu-beta');
});

it('rejects out-of-range or half-supplied coordinates', function () {
    discoveryFixture();

    $this->getJson('/api/discover?lat=95&lng=73.5')->assertUnprocessable();
    $this->getJson('/api/discover?lat=4.17')->assertUnprocessable();
});

it('leaks nothing: no internal ids, no PII, no commercial terms', function () {
    discoveryFixture();

    $response = $this->getJson('/api/discover?lat=4.1770&lng=73.5100')->assertOk();
    $data = $response->json('data');

    // The full public contract of an entry — nothing else.
    foreach ($data as $section) {
        foreach ($section as $entry) {
            expect(array_keys($entry))->toBe([
                'name', 'slug', 'category', 'logo_url', 'channel', 'cashback_rate_percent', 'standing_cashback_rate_percent', 'promo_ends_at', 'distance_m',
            ]);
        }
    }

    $raw = $response->getContent();
    expect($raw)->not->toContain('merchant_id');
    expect($raw)->not->toContain('bank_account');
    expect($raw)->not->toContain('fee_bp');
    expect($raw)->not->toContain('tin');
});

it('serves from a 60-second cache', function () {
    [$alpha] = discoveryFixture();

    $this->getJson('/api/discover')->assertOk();

    // A rename inside the TTL is not yet visible — the dataset is cached.
    $alpha->update(['name' => 'Renamed Alpha']);

    $names = collect($this->getJson('/api/discover')->json('data.featured'))->pluck('name');
    expect($names)->toContain('Cafe Alpha');
    expect($names)->not->toContain('Renamed Alpha');
});

it('caps every section and computes distance only inside the nearby bounding box', function () {
    discoveryFixture();

    // Far Gamma (Addu, hundreds of km south) is still listed, but no trig
    // ever runs for branches outside the nearby bounding box — its distance
    // is simply null. The public endpoint's per-request maths is bounded.
    $data = $this->getJson('/api/discover?lat=4.1770&lng=73.5100')->assertOk()->json('data');
    $far = collect($data['featured'])->firstWhere('slug', 'far-gamma');
    expect($far)->not->toBeNull();
    expect($far['distance_m'])->toBeNull();

    // 55 more online merchants clustered around Malé can push a section
    // only to SECTION_LIMIT entries — the response never grows with the
    // dataset.
    foreach (range(1, 55) as $i) {
        discoveryMerchant(
            ['name' => sprintf('Bulk %02d', $i), 'slug' => "bulk-{$i}", 'channel' => 'both'],
            100,
            [4.1752 + $i * 0.0001, 73.5089],
        );
    }
    Cache::flush(); // the 60s dataset cache would otherwise hide the new rows

    $data = $this->getJson('/api/discover?lat=4.1770&lng=73.5100')->assertOk()->json('data');

    expect(count($data['online']))->toBe(DiscoveryService::SECTION_LIMIT);
    expect(count($data['nearby']))->toBe(DiscoveryService::SECTION_LIMIT);
});

it('does not list a promo at or below the standing rate as increased', function () {
    $zeta = discoveryMerchant(['name' => 'Zeta', 'slug' => 'zeta'], 500);
    Promotion::query()->create([
        'merchant_id' => $zeta->id,
        'rate_bp' => 300,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'status' => 'published',
    ]);

    $data = $this->getJson('/api/discover')->assertOk()->json('data');

    expect($data['increased'])->toBe([]);
});
