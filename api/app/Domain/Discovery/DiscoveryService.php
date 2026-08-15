<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use App\Domain\Onboarding\MerchantLogo;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The public merchant discovery read model (§10 apps/web): featured /
 * increased / nearby / online sections over ACTIVE merchants with a live
 * standing rate, plus the Rakuten-style storefront reads — the paginated
 * directory and the per-slug store page.
 *
 * Privacy contract: entries expose merchant name, slug, category, logo URL,
 * the rate now, the "usually" standing rate, the promo end, and a branch
 * distance.
 * Nothing else — no internal ids, no customer data, no commercial terms
 * (fees, bank details) ever appear here. The store page adds featured,
 * the customer-facing eligibility text, branch addresses and a join label —
 * still nothing internal.
 *
 * The merchant/rate/promo/branch dataset is cached for 60 seconds; only the
 * per-request distance maths runs outside the cache (caching per-coordinate
 * would defeat the cache entirely). That per-request work is bounded: a
 * cheap bounding-box comparison rejects every branch that cannot possibly
 * fall inside the nearby radius BEFORE any trigonometry runs, and every
 * section is capped at SECTION_LIMIT entries — the endpoint is public and
 * only IP-throttled, so its worst-case cost must not grow with the size of
 * the merchant base.
 */
final class DiscoveryService
{
    public const int CACHE_SECONDS = 60;

    public const string CACHE_KEY = 'discovery:entries:v3';

    // v4: store detail gained category_rates (Task #25).
    public const string STORE_CACHE_PREFIX = 'discovery:store:v4:';

    public const int DIRECTORY_DEFAULT_PER_PAGE = 12;

    public const int DIRECTORY_MAX_PER_PAGE = 24;

    /**
     * Page ceiling for the directory. Far beyond any real catalogue, yet
     * small enough that (page-1)*per_page can never overflow the int offset
     * handed to array_slice (PHP_INT_MAX-sized pages used to 500).
     */
    public const int DIRECTORY_MAX_PAGE = 100000;

    /** Nearby cut-off: 10 km. */
    public const int NEARBY_RADIUS_M = 10000;

    /** Hard cap per section — the response never grows with the dataset. */
    public const int SECTION_LIMIT = 50;

    /**
     * Metres per degree of latitude (and of longitude at the equator),
     * deliberately a touch UNDER the true ~111,195 m so the box slightly
     * over-includes — the exact haversine radius check decides the border,
     * the box only spares the trigonometry.
     */
    private const float METERS_PER_DEGREE = 111000.0;

    /**
     * Drop the read model for one merchant: the shared sections/directory
     * dataset and that store's own page.
     *
     * EVERY write that changes what the storefront renders for a live store
     * must call this — the logo, the profile (category / channel / terms),
     * the standing rate, and the promotion lifecycle. Without it the card and
     * the store page keep serving the previous values for up to
     * CACHE_SECONDS, which for a rate change means quoting a cashback
     * percentage the merchant has already moved off. One entry point so the
     * two keys can never drift apart between call sites.
     */
    public static function forgetMerchant(Merchant $merchant): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STORE_CACHE_PREFIX.$merchant->slug);
    }

    /**
     * @return array{featured: list<array<string, mixed>>, increased: list<array<string, mixed>>, nearby: list<array<string, mixed>>, online: list<array<string, mixed>>}
     */
    public function sections(?float $lat, ?float $lng): array
    {
        $entries = $this->cachedEntries();

        $hasCoords = $lat !== null && $lng !== null;

        // Bounding box around the caller sized to the nearby radius: only
        // branches inside it get real haversine maths; anything farther can
        // never be "nearby", so its distance stays null and no trig runs.
        $maxDeltaLat = self::NEARBY_RADIUS_M / self::METERS_PER_DEGREE;
        $maxDeltaLng = $hasCoords
            ? self::NEARBY_RADIUS_M / (self::METERS_PER_DEGREE * max(cos(deg2rad($lat)), 0.01))
            : 0.0;

        foreach ($entries as $i => $entry) {
            $distance = null;

            if ($hasCoords) {
                foreach ($entry['branches'] as [$branchLat, $branchLng]) {
                    $deltaLng = abs($branchLng - $lng);

                    if ($deltaLng > 180.0) {
                        $deltaLng = 360.0 - $deltaLng; // antimeridian wrap
                    }

                    if (abs($branchLat - $lat) > $maxDeltaLat || $deltaLng > $maxDeltaLng) {
                        continue;
                    }

                    $metres = Haversine::meters($lat, $lng, $branchLat, $branchLng);
                    $distance = $distance === null ? $metres : min($distance, $metres);
                }
            }

            $entries[$i]['distance_m'] = $distance;
        }

        $nearby = [];

        if ($hasCoords) {
            $nearby = array_values(array_filter(
                $entries,
                fn (array $e): bool => $e['distance_m'] !== null && $e['distance_m'] <= self::NEARBY_RADIUS_M,
            ));
            usort($nearby, fn (array $a, array $b): int => $a['distance_m'] <=> $b['distance_m']);
        }

        $present = fn (array $list): array => array_values(array_map(
            $this->presentEntry(...),
            array_slice($list, 0, self::SECTION_LIMIT),
        ));

        return [
            'featured' => $present(array_values(array_filter($entries, fn (array $e): bool => $e['featured']))),
            'increased' => $present(array_values(array_filter($entries, fn (array $e): bool => $e['rate_bp'] > $e['standing_rate_bp']))),
            'nearby' => $present($nearby),
            'online' => $present(array_values(array_filter($entries, fn (array $e): bool => in_array($e['channel'], ['online', 'both'], true)))),
        ];
    }

    /**
     * The public merchant directory (storefront index): alphabetical, filterable
     * by name substring and exact category, paginated in memory over the full
     * (small, bounded) matching set so `total` is exact.
     *
     * Cache discipline: the UNFILTERED dataset is the same 60-second cached
     * read model the sections use. Filtered lookups run straight SQL every
     * time instead — a cache keyed on caller-supplied `q` would let any
     * anonymous IP flood the store with junk keys and poison what other
     * callers read, and merchants is one small table.
     *
     * @return array{merchants: list<array<string, mixed>>, total: int, page: int, per_page: int, categories: list<string>}
     */
    public function directory(?string $q, ?string $category, int $perPage, int $page): array
    {
        $entries = $q === null && $category === null
            ? $this->cachedEntries()
            : $this->buildEntries($q, $category);

        return [
            'merchants' => array_values(array_map(
                $this->presentDirectoryEntry(...),
                array_slice($entries, ($page - 1) * $perPage, $perPage),
            )),
            'total' => count($entries),
            'page' => $page,
            'per_page' => $perPage,
            'categories' => $this->categories(),
        ];
    }

    /**
     * The public store page for one slug, cached 60s per slug. Returns null —
     * indistinguishably — for a slug that does not exist, is not active, or
     * has no live standing rate: the 404 must never reveal that a merchant
     * exists but is suspended. Misses are deliberately never written to the
     * cache, so junk slugs cannot flood the store with keys; the miss cost is
     * one indexed lookup behind the IP throttle.
     *
     * @return array<string, mixed>|null
     */
    public function store(string $slug): ?array
    {
        $key = self::STORE_CACHE_PREFIX.$slug;

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $store = $this->buildStore($slug);

        if ($store !== null) {
            Cache::put($key, $store, self::CACHE_SECONDS);
        }

        return $store;
    }

    /**
     * Distinct categories across the (cached, unfiltered) listed merchants,
     * for the directory filter UI.
     *
     * @return list<string>
     */
    private function categories(): array
    {
        $categories = array_values(array_unique(array_filter(
            array_column($this->cachedEntries(), 'category'),
            fn (?string $category): bool => $category !== null && $category !== '',
        )));

        sort($categories);

        return $categories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cachedEntries(): array
    {
        /** @var list<array<string, mixed>> */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->buildEntries());
    }

    /**
     * The public contract of a DIRECTORY entry — the discovery entry shape
     * minus distance (the directory is not geographic).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function presentDirectoryEntry(array $entry): array
    {
        return [
            'name' => $entry['name'],
            'slug' => $entry['slug'],
            'category' => $entry['category'],
            'logo_url' => $entry['logo_url'],
            'channel' => $entry['channel'],
            'rate_bp' => $entry['rate_bp'],
            'standing_rate_bp' => $entry['standing_rate_bp'],
            'promo_ends_at' => $entry['promo_ends_at'],
        ];
    }

    /**
     * Strips the internal working keys — what remains is the full public
     * contract of a discovery entry.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function presentEntry(array $entry): array
    {
        return [
            'name' => $entry['name'],
            'slug' => $entry['slug'],
            'category' => $entry['category'],
            'logo_url' => $entry['logo_url'],
            'channel' => $entry['channel'],
            'rate_bp' => $entry['rate_bp'],
            'standing_rate_bp' => $entry['standing_rate_bp'],
            'promo_ends_at' => $entry['promo_ends_at'],
            'distance_m' => $entry['distance_m'],
        ];
    }

    /**
     * Filters apply in SQL (name ILIKE / exact category); LIKE wildcards in
     * the caller-supplied needle are escaped so `%` or `_` never widen it.
     *
     * @return list<array<string, mixed>>
     */
    private function buildEntries(?string $q = null, ?string $category = null): array
    {
        $now = CarbonImmutable::now('UTC');

        $merchants = Merchant::query()
            ->where('status', 'active')
            ->when($q !== null, fn ($query) => $query->where(
                'name',
                'ilike',
                '%'.addcslashes((string) $q, '\\%_').'%',
            ))
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'category', 'logo_path', 'featured', 'channel']);

        if ($merchants->isEmpty()) {
            return [];
        }

        // Standing rate effective now, per merchant (§5: read from the
        // append-only history). Merchants without one have no live offer and
        // are not listed.
        $standingRates = MerchantRate::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderBy('effective_from')
            ->get(['merchant_id', 'rate_bp'])
            ->keyBy('merchant_id');

        // Best live PUBLISHED merchant-wide promotion per merchant.
        // Promotions are immutable once published (§5), so rate and end are
        // safe to show. Branch-scoped promos are excluded: sale-time
        // resolution honours them only at their own branch, so advertising
        // them here would over-promise every other branch — the displayed
        // rate may only under-promise (§9.2).
        $promotions = Promotion::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->whereNull('branch_id')
            ->where('status', 'published')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('rate_bp')
            ->get(['merchant_id', 'rate_bp', 'ends_at'])
            ->keyBy('merchant_id'); // keyBy keeps the LAST row per key — the highest rate

        $branches = MerchantBranch::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['merchant_id', 'lat', 'lng'])
            ->groupBy('merchant_id');

        $entries = [];

        foreach ($merchants as $merchant) {
            $standing = $standingRates->get($merchant->id);

            if ($standing === null) {
                continue;
            }

            $promo = $promotions->get($merchant->id);
            $boosted = $promo !== null && $promo->rate_bp > $standing->rate_bp;

            $entries[] = [
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'category' => $merchant->category,
                'logo_url' => $this->logoUrl($merchant->slug, $merchant->logo_path),
                // The rate the customer gets NOW; "usually" is the standing rate.
                'rate_bp' => $boosted ? $promo->rate_bp : $standing->rate_bp,
                'standing_rate_bp' => $standing->rate_bp,
                'promo_ends_at' => $boosted ? $promo->ends_at->toIso8601String() : null,
                'featured' => (bool) $merchant->featured,
                'channel' => $merchant->channel,
                'branches' => $branches->get($merchant->id, collect())
                    ->map(fn (MerchantBranch $b): array => [(float) $b->lat, (float) $b->lng])
                    ->all(),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildStore(string $slug): ?array
    {
        $now = CarbonImmutable::now('UTC');

        $merchant = Merchant::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->first(['id', 'name', 'slug', 'category', 'logo_path', 'featured', 'channel', 'eligibility_basis', 'created_at']);

        if ($merchant === null) {
            return null;
        }

        // Same listing rule as the sections: no live standing rate means no
        // live offer, and the store page has nothing truthful to quote.
        $standing = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderByDesc('effective_from')
            ->first(['rate_bp']);

        if ($standing === null) {
            return null;
        }

        // Only a live published MERCHANT-WIDE promotion that BEATS the
        // standing rate is surfaced — consistent with the sections'
        // "increased" rule. Branch-scoped promos never show: they earn only
        // at their own branch, and the hero must not over-promise the rest.
        $promo = Promotion::query()
            ->where('merchant_id', $merchant->id)
            ->whereNull('branch_id')
            ->where('status', 'published')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->where('rate_bp', '>', $standing->rate_bp)
            ->orderByDesc('rate_bp')
            ->first(['rate_bp', 'ends_at', 'min_purchase_laari']);

        $branches = MerchantBranch::query()
            ->where('merchant_id', $merchant->id)
            ->orderBy('name')
            ->get(['name', 'address', 'lat', 'lng']);

        // The store's ACTIVE product categories (Task #25) for the rates
        // table — "Fruits — excluded, Veggies — 2%, everything else —
        // standing_rate_bp". Names + mode + rate only: no ids, no slugs —
        // the public page displays terms, it does not integrate.
        $categoryRates = MerchantProductCategory::query()
            ->where('merchant_id', $merchant->id)
            ->where('active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['name_en', 'name_dv', 'mode', 'rate_bp'])
            ->map(fn (MerchantProductCategory $category): array => [
                'name_en' => $category->name_en,
                'name_dv' => $category->name_dv,
                'mode' => $category->mode,
                'rate_bp' => $category->mode === 'rate' ? $category->rate_bp : null,
            ])
            ->all();

        return [
            'name' => $merchant->name,
            'slug' => $merchant->slug,
            'category' => $merchant->category,
            'logo_url' => $this->logoUrl($merchant->slug, $merchant->logo_path),
            'channel' => $merchant->channel,
            'featured' => (bool) $merchant->featured,
            'rate_bp' => $promo?->rate_bp ?? $standing->rate_bp,
            'standing_rate_bp' => $standing->rate_bp,
            'promotion' => $promo === null ? null : [
                'rate_bp' => $promo->rate_bp,
                'ends_at' => $promo->ends_at->toIso8601String(),
                'min_purchase_laari' => $promo->min_purchase_laari,
            ],
            // The merchant's own eligibility wording, verbatim (§11: shown to
            // customers, never used in computation). Null when unset.
            'cashback_basis' => $merchant->eligibility_basis,
            // "Everything else" earns the standing_rate_bp above; excluded
            // categories earn nothing, even during promotions.
            'category_rates' => $categoryRates,
            'branches' => $branches
                ->map(fn (MerchantBranch $b): array => [
                    'name' => $b->name,
                    'address' => $b->address,
                    'lat' => $b->lat === null ? null : (float) $b->lat,
                    'lng' => $b->lng === null ? null : (float) $b->lng,
                ])
                ->all(),
            // Month granularity only — business-facing, UTC+5 (§13). A
            // machine "YYYY-MM", never pre-composed prose: the client owns
            // the wording so the label localises (en+dv) like every other
            // storefront string.
            'joined' => $merchant->created_at === null
                ? null
                : $merchant->created_at->copy()->timezone('Indian/Maldives')->format('Y-m'),
        ];
    }

    /**
     * Absolute URL for a merchant logo. Logos live on the PRIVATE `logos`
     * disk and are answered by MerchantLogoController — public while the
     * store is active, owner/admin-only otherwise (MerchantLogo). Null when
     * no logo is set; the raw storage path never leaves the API.
     */
    private function logoUrl(string $slug, ?string $logoPath): ?string
    {
        return MerchantLogo::url($slug, $logoPath);
    }
}
