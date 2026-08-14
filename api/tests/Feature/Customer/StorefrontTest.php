<?php

declare(strict_types=1);

use App\Domain\Discovery\DiscoveryService;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Public storefront: the paginated merchant directory and the per-slug store
 * page. No auth anywhere in this file — every request is anonymous.
 */
function storefrontMerchant(array $attributes, ?int $rateBp = 200): Merchant
{
    $merchant = Merchant::factory()->create($attributes);

    if ($rateBp !== null) {
        MerchantRate::factory()->for($merchant)->create([
            'rate_bp' => $rateBp,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
        ]);
    }

    return $merchant;
}

// ---------------------------------------------------------------- directory

it('paginates the directory alphabetically with default and capped page sizes', function () {
    foreach (range(1, 15) as $i) {
        storefrontMerchant(['name' => sprintf('Store %02d', $i), 'slug' => sprintf('store-%02d', $i)]);
    }

    $response = $this->getJson('/api/discover/merchants')->assertOk();
    $data = $response->json('data');
    $meta = $response->json('meta');

    expect($data)->toHaveCount(12); // default per_page
    expect($meta['total'])->toBe(15);
    expect($meta['page'])->toBe(1);
    expect($meta['per_page'])->toBe(12);
    expect(collect($data)->pluck('name')->all())
        ->toBe(collect(range(1, 12))->map(fn (int $i) => sprintf('Store %02d', $i))->all());

    // Every entry carries exactly the public directory contract.
    expect(array_keys($data[0]))->toBe([
        'name', 'slug', 'category', 'logo_url', 'is_online', 'rate_bp', 'standing_rate_bp', 'promo_ends_at',
    ]);

    // No logo uploaded — the slot is present and null, never absent.
    expect($data[0]['logo_url'])->toBeNull();

    // Second page holds the remaining three, still alphabetical.
    $page2 = $this->getJson('/api/discover/merchants?page=2')->assertOk()->json('data');
    expect(collect($page2)->pluck('name')->all())->toBe(['Store 13', 'Store 14', 'Store 15']);

    // Custom page size within the cap.
    $sliced = $this->getJson('/api/discover/merchants?per_page=5&page=2')->assertOk()->json('data');
    expect(collect($sliced)->pluck('name')->all())
        ->toBe(['Store 06', 'Store 07', 'Store 08', 'Store 09', 'Store 10']);

    // The cap is 24: 24 is accepted, 25 is rejected.
    $this->getJson('/api/discover/merchants?per_page=24')->assertOk();
    $this->getJson('/api/discover/merchants?per_page=25')->assertUnprocessable();
    $this->getJson('/api/discover/merchants?per_page=0')->assertUnprocessable();
});

it('rejects astronomical page numbers instead of overflowing the offset', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    // PHP_INT_MAX passes a bare integer|min:1 rule, but (page-1)*per_page
    // overflows int to float and array_slice() would 500 under strict_types.
    // A page cap turns it into an ordinary validation reply.
    $this->getJson('/api/discover/merchants?page=9223372036854775807')->assertUnprocessable();
    $this->getJson('/api/discover/merchants?page='.(DiscoveryService::DIRECTORY_MAX_PAGE + 1))
        ->assertUnprocessable();

    // The cap itself is a valid — merely empty — page, at every page size.
    $response = $this->getJson('/api/discover/merchants?page='.DiscoveryService::DIRECTORY_MAX_PAGE)->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(1);
    $this->getJson('/api/discover/merchants?per_page=24&page='.DiscoveryService::DIRECTORY_MAX_PAGE)->assertOk();
});

it('filters by q case-insensitively, trimmed, with LIKE wildcards escaped', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);
    storefrontMerchant(['name' => 'Grocer Bravo', 'slug' => 'grocer-bravo']);

    // ILIKE substring match, case-insensitive.
    $response = $this->getJson('/api/discover/merchants?q=ALPHA')->assertOk();
    expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['cafe-alpha']);
    expect($response->json('meta.total'))->toBe(1);

    // Surrounding whitespace is trimmed before matching.
    $trimmed = $this->getJson('/api/discover/merchants?q='.urlencode('  alpha  '))->assertOk();
    expect(collect($trimmed->json('data'))->pluck('slug')->all())->toBe(['cafe-alpha']);

    // Too short (after trim) and overlong are rejected, never silently run.
    $this->getJson('/api/discover/merchants?q=a')->assertUnprocessable();
    $this->getJson('/api/discover/merchants?q='.urlencode('  a  '))->assertUnprocessable();
    $this->getJson('/api/discover/merchants?q='.str_repeat('x', 41))->assertUnprocessable();
    $this->getJson('/api/discover/merchants?q='.str_repeat('x', 40))->assertOk();

    // LIKE wildcards in q are literals: '%%' matches nothing, not everything.
    expect($this->getJson('/api/discover/merchants?q='.urlencode('%%'))->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/discover/merchants?q='.urlencode('__'))->assertOk()->json('meta.total'))->toBe(0);
});

it('rejects invalid UTF-8 in q and category with a 422, never a 500', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    // 0xC3 0x28 is an invalid UTF-8 sequence. It passes Laravel's `string`
    // rule, so without an explicit encoding check it reaches the ILIKE
    // binding and Postgres aborts the request (SQLSTATE 22021 → HTTP 500)
    // for any anonymous caller. Both text filters must reply 422 instead.
    $this->getJson('/api/discover/merchants?q=abc%C3%28')->assertUnprocessable();
    $this->getJson('/api/discover/merchants?category=caf%C3%28')->assertUnprocessable();

    // Valid multibyte input (Thaana) still passes validation and searches.
    $this->getJson('/api/discover/merchants?q='.urlencode('ކެފޭ'))->assertOk();
});

it('never serves a q-filtered result from cache', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    expect($this->getJson('/api/discover/merchants?q=cafe')->assertOk()->json('meta.total'))->toBe(1);

    // A new match appears immediately — filtered lookups are straight SQL,
    // never cached under a caller-supplied key.
    storefrontMerchant(['name' => 'Cafe Bravo', 'slug' => 'cafe-bravo']);
    expect($this->getJson('/api/discover/merchants?q=cafe')->assertOk()->json('meta.total'))->toBe(2);

    // The UNFILTERED dataset, by contrast, is the shared 60s cache.
    $names = collect($this->getJson('/api/discover/merchants')->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain('Cafe Alpha');
    Merchant::query()->where('slug', 'cafe-alpha')->update(['name' => 'Renamed Alpha']);
    $names = collect($this->getJson('/api/discover/merchants')->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain('Cafe Alpha');
    expect($names)->not->toContain('Renamed Alpha');
});

it('filters by exact category and lists distinct categories for the filter UI', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha', 'category' => 'cafe']);
    storefrontMerchant(['name' => 'Cafe Bravo', 'slug' => 'cafe-bravo', 'category' => 'cafe']);
    storefrontMerchant(['name' => 'Grocer Charlie', 'slug' => 'grocer-charlie', 'category' => 'grocery']);
    storefrontMerchant(['name' => 'Uncategorised Delta', 'slug' => 'uncat-delta', 'category' => null]);

    $response = $this->getJson('/api/discover/merchants?category=cafe')->assertOk();
    expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['cafe-alpha', 'cafe-bravo']);
    expect($response->json('meta.total'))->toBe(2);

    // Exact match only — no substring widening.
    expect($this->getJson('/api/discover/merchants?category=caf')->assertOk()->json('meta.total'))->toBe(0);

    // Distinct, sorted, null-free — and present on filtered responses too.
    expect($response->json('meta.categories'))->toBe(['cafe', 'grocery']);
});

it('boosts directory entries with a live published promotion only', function () {
    $alpha = storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha'], 200);
    Promotion::query()->create([
        'merchant_id' => $alpha->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);
    storefrontMerchant(['name' => 'Grocer Bravo', 'slug' => 'grocer-bravo'], 100);

    $data = $this->getJson('/api/discover/merchants')->assertOk()->json('data');

    $alphaEntry = collect($data)->firstWhere('slug', 'cafe-alpha');
    expect($alphaEntry['rate_bp'])->toBe(500);
    expect($alphaEntry['standing_rate_bp'])->toBe(200);
    expect($alphaEntry['promo_ends_at'])->not->toBeNull();

    $bravoEntry = collect($data)->firstWhere('slug', 'grocer-bravo');
    expect($bravoEntry['rate_bp'])->toBe(100);
    expect($bravoEntry['promo_ends_at'])->toBeNull();
});

it('never advertises a branch-scoped promotion on the directory or store page', function () {
    // Sale-time resolution (PromotionResolver) applies a branch-scoped promo
    // only at its own branch, so advertising it merchant-wide would
    // over-promise every other branch. Discovery must skip it entirely —
    // customers at the scoped branch are pleasantly surprised instead.
    $merchant = storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha'], 200);
    $hulhumale = MerchantBranch::factory()->for($merchant)->create(['name' => 'Hulhumalé Branch']);
    Promotion::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $hulhumale->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);

    // Directory card quotes the standing rate with no promo end.
    $entry = collect($this->getJson('/api/discover/merchants')->assertOk()->json('data'))
        ->firstWhere('slug', 'cafe-alpha');
    expect($entry['rate_bp'])->toBe(200);
    expect($entry['promo_ends_at'])->toBeNull();

    // Store page hero shows no boost and no promotion block.
    $data = $this->getJson('/api/discover/merchants/cafe-alpha')->assertOk()->json('data');
    expect($data['rate_bp'])->toBe(200);
    expect($data['standing_rate_bp'])->toBe(200);
    expect($data['promotion'])->toBeNull();
});

it('hides suspended, closed and offer-less merchants from the directory, filtered or not', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);
    storefrontMerchant(['name' => 'Suspended Sierra', 'slug' => 'suspended-sierra', 'status' => 'suspended'], 400);
    storefrontMerchant(['name' => 'Closed Charlie', 'slug' => 'closed-charlie', 'status' => 'closed'], 400);
    storefrontMerchant(['name' => 'NoRate November', 'slug' => 'norate-november'], null);

    $slugs = collect($this->getJson('/api/discover/merchants')->assertOk()->json('data'))->pluck('slug');
    expect($slugs->all())->toBe(['cafe-alpha']);

    // A name search cannot resurrect them either.
    expect($this->getJson('/api/discover/merchants?q=sierra')->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/discover/merchants?q=charlie')->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/discover/merchants?q=november')->assertOk()->json('meta.total'))->toBe(0);
});

// -------------------------------------------------------------- store detail

it('serves the full public store page for an active merchant', function () {
    $merchant = storefrontMerchant([
        'name' => 'Cafe Alpha',
        'slug' => 'cafe-alpha',
        'category' => 'cafe',
        'featured' => true,
        'is_online' => true,
        'eligibility_basis' => 'Invoice total excluding GST and service charge.',
    ], 200);
    Promotion::query()->create([
        'merchant_id' => $merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'min_purchase_laari' => 10000,
        'status' => 'published',
    ]);
    MerchantBranch::factory()->for($merchant)->create([
        'name' => 'Male Branch',
        'address' => 'Orchid Magu',
        'lat' => 4.1752000,
        'lng' => 73.5089000,
    ]);

    $data = $this->getJson('/api/discover/merchants/cafe-alpha')->assertOk()->json('data');

    expect(array_keys($data))->toBe([
        'name', 'slug', 'category', 'logo_url', 'is_online', 'featured',
        'rate_bp', 'standing_rate_bp', 'promotion', 'cashback_basis', 'branches', 'joined',
    ]);

    expect($data['name'])->toBe('Cafe Alpha');
    expect($data['slug'])->toBe('cafe-alpha');
    expect($data['category'])->toBe('cafe');
    expect($data['logo_url'])->toBeNull(); // no logo uploaded — null, never absent
    expect($data['is_online'])->toBeTrue();
    expect($data['featured'])->toBeTrue();

    // Boosted now, usually 2% — all integer basis points.
    expect($data['rate_bp'])->toBe(500);
    expect($data['standing_rate_bp'])->toBe(200);
    expect(array_keys($data['promotion']))->toBe(['rate_bp', 'ends_at', 'min_purchase_laari']);
    expect($data['promotion']['rate_bp'])->toBe(500);
    expect($data['promotion']['min_purchase_laari'])->toBe(10000);
    expect($data['promotion']['ends_at'])->not->toBeNull();

    expect($data['cashback_basis'])->toBe('Invoice total excluding GST and service charge.');

    expect($data['branches'])->toHaveCount(1);
    expect(array_keys($data['branches'][0]))->toBe(['name', 'address', 'lat', 'lng']);
    expect($data['branches'][0]['name'])->toBe('Male Branch');
    expect($data['branches'][0]['address'])->toBe('Orchid Magu');
    expect($data['branches'][0]['lat'])->toEqualWithDelta(4.1752, 0.0001);
    expect($data['branches'][0]['lng'])->toEqualWithDelta(73.5089, 0.0001);

    // Month-granularity machine value ("YYYY-MM"), evaluated in UTC+5 — the
    // web client composes the localised "Joined …" label itself, so the API
    // must never ship pre-composed English prose here.
    expect($data['joined'])->toBe(now('Indian/Maldives')->format('Y-m'));
});

it('serves a null cashback basis verbatim and no promotion when none boosts', function () {
    $merchant = storefrontMerchant([
        'name' => 'Grocer Bravo',
        'slug' => 'grocer-bravo',
        'eligibility_basis' => null,
    ], 300);

    // Live but at/below the standing rate — never surfaced.
    Promotion::query()->create([
        'merchant_id' => $merchant->id,
        'rate_bp' => 200,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'status' => 'published',
    ]);

    $data = $this->getJson('/api/discover/merchants/grocer-bravo')->assertOk()->json('data');

    expect($data)->toHaveKey('cashback_basis');
    expect($data['cashback_basis'])->toBeNull();
    expect($data['promotion'])->toBeNull();
    expect($data['rate_bp'])->toBe(300);
    expect($data['standing_rate_bp'])->toBe(300);
    expect($data['branches'])->toBe([]);
});

it('returns an identical 404 for missing, suspended, closed and offer-less slugs', function () {
    // Compare the production-shaped body — with debug on, the testing 404
    // carries a stack trace whose line numbers differ per call site.
    config(['app.debug' => false]);

    storefrontMerchant(['name' => 'Suspended Sierra', 'slug' => 'suspended-sierra', 'status' => 'suspended'], 400);
    storefrontMerchant(['name' => 'Closed Charlie', 'slug' => 'closed-charlie', 'status' => 'closed'], 400);
    storefrontMerchant(['name' => 'NoRate November', 'slug' => 'norate-november'], null);

    $missing = $this->getJson('/api/discover/merchants/does-not-exist')->assertNotFound();
    $suspended = $this->getJson('/api/discover/merchants/suspended-sierra')->assertNotFound();
    $closed = $this->getJson('/api/discover/merchants/closed-charlie')->assertNotFound();
    $offerless = $this->getJson('/api/discover/merchants/norate-november')->assertNotFound();

    // Byte-identical bodies — the 404 must not leak that the slug exists.
    expect($suspended->getContent())->toBe($missing->getContent());
    expect($closed->getContent())->toBe($missing->getContent());
    expect($offerless->getContent())->toBe($missing->getContent());
});

it('serves an absolute public-disk logo url when a logo is set', function () {
    storefrontMerchant([
        'name' => 'Cafe Alpha',
        'slug' => 'cafe-alpha',
        'logo_path' => 'merchants/cafe-alpha/logo.png',
    ]);
    storefrontMerchant(['name' => 'Grocer Bravo', 'slug' => 'grocer-bravo', 'logo_path' => null]);

    // Directory entry: absolute /storage/ URL for the set logo, null for the rest.
    $data = $this->getJson('/api/discover/merchants')->assertOk()->json('data');
    $alpha = collect($data)->firstWhere('slug', 'cafe-alpha');
    expect($alpha['logo_url'])->toStartWith('http');
    expect($alpha['logo_url'])->toContain('/storage/merchants/cafe-alpha/logo.png');
    expect(collect($data)->firstWhere('slug', 'grocer-bravo')['logo_url'])->toBeNull();

    // Store page carries the same URL; the raw storage path never leaks.
    $detail = $this->getJson('/api/discover/merchants/cafe-alpha')->assertOk();
    expect($detail->json('data.logo_url'))->toBe($alpha['logo_url']);
    expect($detail->getContent())->not->toContain('logo_path');
});

it('caches the store page per slug for 60 seconds', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    expect($this->getJson('/api/discover/merchants/cafe-alpha')->assertOk()->json('data.name'))
        ->toBe('Cafe Alpha');

    // A rename inside the TTL is not yet visible.
    Merchant::query()->where('slug', 'cafe-alpha')->update(['name' => 'Renamed Alpha']);
    expect($this->getJson('/api/discover/merchants/cafe-alpha')->assertOk()->json('data.name'))
        ->toBe('Cafe Alpha');
});

// ------------------------------------------------------------------- privacy

it('exposes no id fields or commercial terms in either response', function () {
    $merchant = storefrontMerchant([
        'name' => 'Cafe Alpha',
        'slug' => 'cafe-alpha',
        'category' => 'cafe',
    ], 200);
    Promotion::query()->create([
        'merchant_id' => $merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'min_purchase_laari' => 10000,
        'status' => 'published',
    ]);
    MerchantBranch::factory()->for($merchant)->create([
        'name' => 'Male Branch',
        'address' => 'Orchid Magu',
    ]);

    $directory = $this->getJson('/api/discover/merchants?q=alpha')->assertOk()->getContent();
    $detail = $this->getJson('/api/discover/merchants/cafe-alpha')->assertOk()->getContent();

    foreach ([$directory, $detail] as $raw) {
        expect($raw)->not->toContain('"id"');
        expect($raw)->not->toContain('merchant_id');
        expect($raw)->not->toContain('branch_id');
        expect($raw)->not->toContain('bank_account');
        expect($raw)->not->toContain('bank_name');
        expect($raw)->not->toContain('"tin"');
        expect($raw)->not->toContain('business_reg_no');
        expect($raw)->not->toContain('fee_bp');
        expect($raw)->not->toContain('min_eligible_laari');
        expect($raw)->not->toContain('settlement');
        expect($raw)->not->toContain('logo_path');
    }
});

// ---------------------------------------------------------------- throttling

it('throttles the directory at 60 requests per minute per ip', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/discover/merchants')->assertOk();
    }

    $this->getJson('/api/discover/merchants')->assertStatus(429);
});

it('throttles the store page at 60 requests per minute per ip', function () {
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/discover/merchants/cafe-alpha')->assertOk();
    }

    $this->getJson('/api/discover/merchants/cafe-alpha')->assertStatus(429);
});

it('exempts the trusted SSR origin from the per-ip throttle via the internal token', function () {
    // All SSR renders leave one Next server IP; without an exemption, 60+
    // distinct-slug renders a minute would 429 every store page's SSR fetch.
    config(['services.discovery.internal_token' => 'test-internal-token']);
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/discover/merchants/cafe-alpha')->assertOk();
    }

    // Bucket exhausted for the plain caller, and a wrong token buys nothing —
    // but the shared secret is exempt even now.
    $this->getJson('/api/discover/merchants/cafe-alpha')->assertStatus(429);
    $this->getJson('/api/discover/merchants/cafe-alpha', ['X-Discovery-Internal' => 'wrong'])
        ->assertStatus(429);
    $this->getJson('/api/discover/merchants/cafe-alpha', ['X-Discovery-Internal' => 'test-internal-token'])
        ->assertOk();
});

it('ignores the internal header entirely when no token is configured', function () {
    // Fail closed: an empty configured token must never hash_equals-match.
    storefrontMerchant(['name' => 'Cafe Alpha', 'slug' => 'cafe-alpha']);

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/discover/merchants', ['X-Discovery-Internal' => ''])->assertOk();
    }

    $this->getJson('/api/discover/merchants', ['X-Discovery-Internal' => ''])->assertStatus(429);
    $this->getJson('/api/discover/merchants', ['X-Discovery-Internal' => 'anything'])->assertStatus(429);
});
