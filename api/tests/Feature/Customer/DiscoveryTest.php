<?php

declare(strict_types=1);

use App\Domain\Discovery\DiscoveryService;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\Promotion;
use App\Models\StoreCategory;
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

    // The full public contract of an entry — nothing else. Every shelf
    // carries the identical shape, the newest one included.
    foreach (['featured', 'increased', 'nearby', 'online', 'recently_added'] as $shelf) {
        foreach ($data[$shelf] as $entry) {
            expect(array_keys($entry))->toBe([
                'name', 'slug', 'category', 'logo_url', 'channel', 'cashback_rate_percent', 'standing_cashback_rate_percent', 'promo_ends_at', 'distance_m',
            ]);
        }
    }

    // The rail is display data only: slug, both names, a count. No curated
    // row id, no sort weight, nothing internal.
    expect($data['categories'])->not->toBe([]);
    foreach ($data['categories'] as $category) {
        expect(array_keys($category))->toBe(['slug', 'name_en', 'name_dv', 'merchant_count']);
    }

    $raw = $response->getContent();
    expect($raw)->not->toContain('"id"');
    expect($raw)->not->toContain('"sort"');
    expect($raw)->not->toContain('listed_at');
    expect($raw)->not->toContain('approved_at');
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

// ------------------------------------------------------- recently added

it('orders the recently added shelf by approval, falling back to creation', function () {
    // A self-signed-up store becomes public when the superadmin approves it
    // (§1 2026-08-15), so approved_at — not created_at — is when it was
    // "added". Alpha was created a month before Gamma and approved a week
    // after it: newest-first must follow the approvals.
    discoveryMerchant([
        'name' => 'Alpha Approved Newest',
        'slug' => 'alpha-approved-newest',
        'created_at' => now()->subDays(40),
        'approved_at' => now()->subDay(),
    ], 200);

    // Admin-created merchants never pass through the approval queue and
    // carry no approved_at at all — their row creation stands in.
    discoveryMerchant([
        'name' => 'Beta Admin Created',
        'slug' => 'beta-admin-created',
        'created_at' => now()->subDays(3),
        'approved_at' => null,
    ], 100);

    discoveryMerchant([
        'name' => 'Gamma Approved Oldest',
        'slug' => 'gamma-approved-oldest',
        'created_at' => now()->subDays(10),
        'approved_at' => now()->subDays(8),
    ], 300);

    $shelf = $this->getJson('/api/discover')->assertOk()->json('data.recently_added');

    expect(collect($shelf)->pluck('slug')->all())
        ->toBe(['alpha-approved-newest', 'beta-admin-created', 'gamma-approved-oldest']);

    // Same entry shape and the same percent-string wire format as every
    // other shelf — the shelf differs only in its ordering.
    expect(array_keys($shelf[0]))->toBe([
        'name', 'slug', 'category', 'logo_url', 'channel', 'cashback_rate_percent', 'standing_cashback_rate_percent', 'promo_ends_at', 'distance_m',
    ]);
    expect($shelf[0]['cashback_rate_percent'])->toBe('2.00');
    expect($shelf[1]['cashback_rate_percent'])->toBe('1.00');
    expect($shelf[2]['standing_cashback_rate_percent'])->toBe('3.00');
    expect($shelf[0]['distance_m'])->toBeNull(); // no coordinates supplied
});

it('keeps unapproved and suspended stores off the recently added shelf', function () {
    discoveryMerchant([
        'name' => 'Live Store',
        'slug' => 'live-store',
        'approved_at' => now()->subDays(2),
    ], 200);

    // Each of these is newer than the live store, so any of them leaking
    // would take the top of the shelf rather than hide at the bottom.
    discoveryMerchant([
        'name' => 'Suspended Store',
        'slug' => 'suspended-store',
        'status' => 'suspended',
        'approved_at' => now(),
    ], 400);
    discoveryMerchant([
        'name' => 'Pending Store',
        'slug' => 'pending-store',
        'status' => 'pending_review',
        'submitted_at' => now(),
    ], 300);
    discoveryMerchant([
        'name' => 'Draft Store',
        'slug' => 'draft-store',
        'status' => 'draft',
    ], 300);
    discoveryMerchant([
        'name' => 'Rejected Store',
        'slug' => 'rejected-store',
        'status' => 'rejected',
    ], 300);

    expect(collect($this->getJson('/api/discover')->assertOk()->json('data.recently_added'))->pluck('slug')->all())
        ->toBe(['live-store']);
});

it('caps the recently added shelf like every other shelf', function () {
    foreach (range(1, DiscoveryService::SECTION_LIMIT + 5) as $i) {
        discoveryMerchant(
            ['name' => sprintf('Bulk %02d', $i), 'slug' => "bulk-{$i}", 'approved_at' => now()->subMinutes($i)],
            200,
        );
    }

    $shelf = $this->getJson('/api/discover')->assertOk()->json('data.recently_added');

    expect(count($shelf))->toBe(DiscoveryService::SECTION_LIMIT);
    expect($shelf[0]['slug'])->toBe('bulk-1'); // approved most recently
});

// ---------------------------------------------------------- category rail

it('rails the curated categories that have at least one live store, in curated order', function () {
    // The curated rows are seeded by migration: grocery sort 10, cafe 30.
    discoveryMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha', 'category' => 'cafe'], 200);
    discoveryMerchant(['name' => 'Cafe Bravo', 'slug' => 'cafe-bravo', 'category' => 'cafe'], 200);
    discoveryMerchant(['name' => 'Grocer Charlie', 'slug' => 'grocer-charlie', 'category' => 'grocery'], 100);

    // None of these counts: suspended, rate-less (no live offer) and
    // uncategorised stores are not part of the listed population.
    discoveryMerchant(['name' => 'Sus Cafe', 'slug' => 'sus-cafe', 'category' => 'cafe', 'status' => 'suspended'], 400);
    Merchant::factory()->create(['name' => 'NoRate Cafe', 'slug' => 'norate-cafe', 'category' => 'cafe']);
    discoveryMerchant(['name' => 'Uncat Delta', 'slug' => 'uncat-delta', 'category' => null], 200);

    $rail = $this->getJson('/api/discover')->assertOk()->json('data.categories');

    // Curated sort order, never alphabetical: grocery (10) before cafe (30).
    expect(collect($rail)->pluck('slug')->all())->toBe(['grocery', 'cafe']);

    // Both names travel — the rail localises without a second lookup.
    expect($rail[0])->toBe([
        'slug' => 'grocery',
        'name_en' => 'Grocery',
        'name_dv' => 'ގުރޮސަރީ',
        'merchant_count' => 1,
    ]);

    // Two live cafes only: the suspended and the rate-less one are absent
    // from the count exactly as they are absent from the shelves.
    expect($rail[1]['slug'])->toBe('cafe');
    expect($rail[1]['merchant_count'])->toBe(2);

    // Every other curated category has no live store and is not railed at
    // all — an empty chip is a dead end.
    expect(collect($rail)->pluck('slug'))->not->toContain('restaurant');
    expect(collect($rail)->pluck('slug'))->not->toContain('other');
});

it('rails nothing when no live store carries a curated category', function () {
    discoveryMerchant(['name' => 'Uncat Delta', 'slug' => 'uncat-delta', 'category' => null], 200);
    discoveryMerchant(['name' => 'Sus Cafe', 'slug' => 'sus-cafe', 'category' => 'cafe', 'status' => 'suspended'], 400);

    $data = $this->getJson('/api/discover')->assertOk()->json('data');

    expect($data['categories'])->toBe([]);
    expect(collect($data['recently_added'])->pluck('slug')->all())->toBe(['uncat-delta']);
});

it('keeps a deactivated curated category off the rail while its stores stay listed', function () {
    // Admin CRUD refuses to deactivate a category active stores still carry,
    // so this state is only reachable by drift — but the curated list is the
    // authority for what the storefront navigates by, and the store itself
    // stays listed and reachable.
    discoveryMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha', 'category' => 'cafe'], 200);
    StoreCategory::query()->where('slug', 'cafe')->update(['active' => false]);

    $data = $this->getJson('/api/discover')->assertOk()->json('data');

    expect($data['categories'])->toBe([]);
    expect(collect($data['recently_added'])->pluck('slug')->all())->toBe(['cafe-alpha']);
});

it('serves the rail from the same 60-second cache as the shelves', function () {
    discoveryMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha', 'category' => 'cafe'], 200);

    expect($this->getJson('/api/discover')->assertOk()->json('data.categories'))
        ->toBe([['slug' => 'cafe', 'name_en' => 'Café', 'name_dv' => 'ކެފޭ', 'merchant_count' => 1]]);

    // A second cafe inside the TTL does not move the count: the rail is
    // derived from the cached entries, under the same key, so the chip can
    // never disagree with the shelf it filters.
    discoveryMerchant(['name' => 'Cafe Bravo', 'slug' => 'cafe-bravo', 'category' => 'cafe'], 200);

    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect($data['categories'][0]['merchant_count'])->toBe(1);
    expect($data['recently_added'])->toHaveCount(1);

    Cache::flush();

    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect($data['categories'][0]['merchant_count'])->toBe(2);
    expect($data['recently_added'])->toHaveCount(2);
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
