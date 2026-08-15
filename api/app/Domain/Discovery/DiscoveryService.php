<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use App\Domain\Money\Percent;
use App\Domain\Onboarding\MerchantLogo;
use App\Domain\Storefront\StoreCategoryIcon;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\Promotion;
use App\Models\StoreCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The public merchant discovery read model (§10 apps/web): featured /
 * increased / nearby / in-store / online / recently-added sections over
 * ACTIVE merchants with a live standing rate, the curated category rail that
 * sits above them, plus the Rakuten-style storefront reads — the paginated
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

    // v4: the sections payload gained the recently-added shelf and the
    // curated category rail, and the cached value is now the whole dataset
    // (entries + rail) rather than a bare entry list.
    //
    // The version tracks the shape of the CACHED VALUE, not of the response:
    // the later in-store shelf needed no bump because it is filtered at the
    // presentation boundary from `channel`, which every v4 entry already
    // carries — a value written by the previous build renders the new
    // payload correctly, so a bump would only force a pointless cold
    // rebuild. Bump the moment an entry gains, loses or renames a key.
    //
    // v5: the cached rail rows gained `icon` (admin-chosen lucide name).
    public const string CACHE_KEY = 'discovery:entries:v5';

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
     * the standing rate, the promotion lifecycle, and the STATUS itself
     * (approval, the §7 day-16 suspension, both reinstatement paths), since
     * status decides whether the store is rendered at all. Without it the
     * card and the store page keep serving the previous values for up to
     * CACHE_SECONDS, which for a rate change means quoting a cashback
     * percentage the merchant has already moved off, and for a suspension
     * means advertising a store the till now refuses — while the uncached
     * directory on the same screen already answers that it is gone. One
     * entry point so the two keys can never drift apart between call sites.
     */
    public static function forgetMerchant(Merchant $merchant): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STORE_CACHE_PREFIX.$merchant->slug);
    }

    /**
     * @return array{featured: list<array<string, mixed>>, increased: list<array<string, mixed>>, nearby: list<array<string, mixed>>, in_store: list<array<string, mixed>>, online: list<array<string, mixed>>, recently_added: list<array<string, mixed>>, categories: list<array{slug: string, name_en: string, name_dv: string|null, icon: string|null, icon_url: string|null, merchant_count: int}>}
     */
    public function sections(?float $lat, ?float $lng): array
    {
        $dataset = $this->cachedDataset();
        $entries = $dataset['entries'];

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

        // Newest listing first. `listed_at` is approved_at — the moment the
        // store actually went live — falling back to created_at for the
        // admin-created merchants that never passed through the approval
        // queue and so carry no approved_at. usort is stable in PHP 8, so
        // merchants listed in the same microsecond keep the dataset's
        // alphabetical order rather than an arbitrary one.
        $recentlyAdded = $entries;
        usort($recentlyAdded, fn (array $a, array $b): int => $b['listed_at'] <=> $a['listed_at']);

        $present = fn (array $list): array => array_values(array_map(
            $this->presentEntry(...),
            array_slice($list, 0, self::SECTION_LIMIT),
        ));

        return [
            'featured' => $present(array_values(array_filter($entries, fn (array $e): bool => $e['featured']))),
            'increased' => $present(array_values(array_filter($entries, fn (array $e): bool => $e['rate_bp'] > $e['standing_rate_bp']))),
            'nearby' => $present($nearby),
            // The channel pair. Both filter the FULL entry set and only then
            // cap, so neither can lose a store to another shelf's ceiling —
            // a client deriving "in store" by filtering `recently_added`
            // instead saw only whatever survived that shelf's own cap, and
            // went empty the moment the newest SECTION_LIMIT listings were
            // all online. `both` belongs to both shelves: such a store really
            // does earn on either channel.
            'in_store' => $present(array_values(array_filter($entries, fn (array $e): bool => in_array($e['channel'], ['in_store', 'both'], true)))),
            'online' => $present(array_values(array_filter($entries, fn (array $e): bool => in_array($e['channel'], ['online', 'both'], true)))),
            // Same entry shape and the same SECTION_LIMIT cap as the shelves
            // above; only the ordering differs.
            'recently_added' => $present($recentlyAdded),
            // NOT a shelf — the category rail that sits above them. See
            // buildCuratedCategories() for how it differs from the
            // directory's flat meta.categories.
            'categories' => $dataset['categories'],
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
     * Distinct category SLUGS across the (cached, unfiltered) listed
     * merchants — the directory filter's vocabulary, sorted alphabetically.
     *
     * Deliberately a DIFFERENT thing from the sections payload's
     * `categories` rail (buildCuratedCategories()): this one is a flat list
     * of slugs, and it is what the `category` query parameter accepts, so a
     * client can round-trip a value it read here without knowing anything
     * about the curated table. The rail is display data — curated en+dv
     * names, counts, and the admin's own sort order. Both are derived from
     * the same listed-merchant dataset, so they can never disagree about
     * WHICH categories exist; they differ only in how much they say.
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
     * The whole cached read model: the listed-merchant entries and the
     * curated category rail derived from them, under ONE key so the rail can
     * never disagree with the entries it counts — and so the single
     * forgetMerchant() call site still drops everything a merchant write
     * changes.
     *
     * @return array{entries: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    private function cachedDataset(): array
    {
        /** @var array{entries: list<array<string, mixed>>, categories: list<array<string, mixed>>} */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $entries = $this->buildEntries();

            return [
                'entries' => $entries,
                'categories' => $this->buildCuratedCategories($entries),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cachedEntries(): array
    {
        return $this->cachedDataset()['entries'];
    }

    /**
     * The curated category rail: every ACTIVE store category (§1 decision
     * 2026-08-15 — the superadmin-curated list stores pick from) that at
     * least one LISTED merchant carries, in the curated `sort` order, with
     * the number of merchants behind each chip.
     *
     * "Listed" is the same population the rest of this read model serves —
     * ACTIVE merchants with a live standing rate — so the count on a chip is
     * exactly what tapping it returns from the directory, never larger. A
     * category no listed merchant carries is absent entirely: an empty chip
     * is a dead end, and the rail is navigation.
     *
     * A DEACTIVATED curated row never appears even if a listed merchant
     * still carries its slug (only reachable by drift — the admin refuses to
     * deactivate a category active merchants still use): the curated list is
     * the authority for what the storefront navigates by. Such a merchant is
     * still listed and still reachable through search and the directory's
     * own `category` filter.
     *
     * Renames and re-sorts ride the 60-second dataset cache rather than an
     * invalidation hook — admin category CRUD is rare, and a chip label one
     * minute stale is harmless where a stale RATE would not be.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{slug: string, name_en: string, name_dv: string|null, icon: string|null, icon_url: string|null, merchant_count: int}>
     */
    private function buildCuratedCategories(array $entries): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($entries as $entry) {
            $slug = $entry['category'];

            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $counts[$slug] = ($counts[$slug] ?? 0) + 1;
        }

        if ($counts === []) {
            return [];
        }

        return StoreCategory::query()
            ->where('active', true)
            ->whereIn('slug', array_keys($counts))
            ->orderBy('sort')
            ->orderBy('slug')
            ->get(['slug', 'name_en', 'name_dv', 'icon', 'icon_path'])
            ->map(fn (StoreCategory $category): array => [
                'slug' => $category->slug,
                'name_en' => $category->name_en,
                'name_dv' => $category->name_dv,
                // Two-step iconography, resolved by the client in this
                // order: uploaded artwork, then the curated glyph name,
                // then its own neutral fallback. The rail therefore always
                // draws something, whether or not anyone has uploaded yet.
                'icon' => $category->icon,
                'icon_url' => StoreCategoryIcon::url($category->slug, $category->icon_path),
                'merchant_count' => $counts[$category->slug],
            ])
            ->all();
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
            // Null wherever the store has not supplied one; every client
            // falls back to `name`, so a Dhivehi visitor reads the Latin
            // name rather than a blank card.
            'name_dv' => $entry['name_dv'],
            'slug' => $entry['slug'],
            'category' => $entry['category'],
            'logo_url' => $entry['logo_url'],
            'channel' => $entry['channel'],
            // PLAN §1 wire format: 2-decimal percent strings. The cached
            // entry keeps integer basis points; the conversion happens
            // here, at the response boundary, by exact integer math.
            'cashback_rate_percent' => Percent::format($entry['rate_bp']),
            'standing_cashback_rate_percent' => Percent::format($entry['standing_rate_bp']),
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
            // Null wherever the store has not supplied one; every client
            // falls back to `name`, so a Dhivehi visitor reads the Latin
            // name rather than a blank card.
            'name_dv' => $entry['name_dv'],
            'slug' => $entry['slug'],
            'category' => $entry['category'],
            'logo_url' => $entry['logo_url'],
            'channel' => $entry['channel'],
            'cashback_rate_percent' => Percent::format($entry['rate_bp']),
            'standing_cashback_rate_percent' => Percent::format($entry['standing_rate_bp']),
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
            ->get(['id', 'name', 'name_dv', 'slug', 'category', 'logo_path', 'featured', 'channel', 'approved_at', 'created_at']);

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
                'name_dv' => $merchant->name_dv,
                'slug' => $merchant->slug,
                'category' => $merchant->category,
                'logo_url' => $this->logoUrl($merchant->slug, $merchant->logo_path),
                // The rate the customer gets NOW; "usually" is the standing rate.
                'rate_bp' => $boosted ? $promo->rate_bp : $standing->rate_bp,
                'standing_rate_bp' => $standing->rate_bp,
                'promo_ends_at' => $boosted ? $promo->ends_at->toIso8601String() : null,
                'featured' => (bool) $merchant->featured,
                'channel' => $merchant->channel,
                // Internal sort key for the recently-added shelf, never
                // presented: when this store became publicly listed.
                // approved_at is the truth for a self-signed-up store (§1
                // 2026-08-15); an admin-created merchant never went through
                // the approval queue and has none, so its row creation is
                // the closest honest answer. Microsecond resolution
                // ("Uu"), because a seeded batch shares a second.
                'listed_at' => (int) ($merchant->approved_at ?? $merchant->created_at)?->format('Uu'),
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
            ->first(['id', 'name', 'name_dv', 'slug', 'category', 'logo_path', 'featured', 'channel', 'eligibility_basis', 'created_at']);

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
        // standing_cashback_rate_percent". Names + mode + rate only: no ids, no slugs —
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
                'cashback_rate_percent' => $category->mode === 'rate'
                    ? Percent::format((int) $category->rate_bp)
                    : null,
            ])
            ->all();

        return [
            'name' => $merchant->name,
            'name_dv' => $merchant->name_dv,
            'slug' => $merchant->slug,
            'category' => $merchant->category,
            'logo_url' => $this->logoUrl($merchant->slug, $merchant->logo_path),
            'channel' => $merchant->channel,
            'featured' => (bool) $merchant->featured,
            'cashback_rate_percent' => Percent::format($promo?->rate_bp ?? $standing->rate_bp),
            'standing_cashback_rate_percent' => Percent::format($standing->rate_bp),
            'promotion' => $promo === null ? null : [
                'cashback_rate_percent' => Percent::format($promo->rate_bp),
                'ends_at' => $promo->ends_at->toIso8601String(),
                'min_purchase_laari' => $promo->min_purchase_laari,
            ],
            // The merchant's own eligibility wording, verbatim (§11: shown to
            // customers, never used in computation). Null when unset.
            'cashback_basis' => $merchant->eligibility_basis,
            // "Everything else" earns the standing rate above; excluded
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
