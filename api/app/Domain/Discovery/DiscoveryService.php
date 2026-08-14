<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The public merchant discovery read model (§10 apps/web): featured /
 * increased / nearby / online sections over ACTIVE merchants with a live
 * standing rate.
 *
 * Privacy contract: entries expose merchant name, slug, category, the rate
 * now, the "usually" standing rate, the promo end, and a branch distance.
 * Nothing else — no internal ids, no customer data, no commercial terms
 * (fees, bank details) ever appear here.
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

    public const string CACHE_KEY = 'discovery:entries:v1';

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
     * @return array{featured: list<array<string, mixed>>, increased: list<array<string, mixed>>, nearby: list<array<string, mixed>>, online: list<array<string, mixed>>}
     */
    public function sections(?float $lat, ?float $lng): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->buildEntries());

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
            'online' => $present(array_values(array_filter($entries, fn (array $e): bool => $e['is_online']))),
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
            'rate_bp' => $entry['rate_bp'],
            'standing_rate_bp' => $entry['standing_rate_bp'],
            'promo_ends_at' => $entry['promo_ends_at'],
            'distance_m' => $entry['distance_m'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEntries(): array
    {
        $now = CarbonImmutable::now('UTC');

        $merchants = Merchant::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'category', 'featured', 'is_online']);

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

        // Best live PUBLISHED promotion per merchant. Promotions are
        // immutable once published (§5), so rate and end are safe to show.
        $promotions = Promotion::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
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
                // The rate the customer gets NOW; "usually" is the standing rate.
                'rate_bp' => $boosted ? $promo->rate_bp : $standing->rate_bp,
                'standing_rate_bp' => $standing->rate_bp,
                'promo_ends_at' => $boosted ? $promo->ends_at->toIso8601String() : null,
                'featured' => (bool) $merchant->featured,
                'is_online' => (bool) $merchant->is_online,
                'branches' => $branches->get($merchant->id, collect())
                    ->map(fn (MerchantBranch $b): array => [(float) $b->lat, (float) $b->lng])
                    ->all(),
            ];
        }

        return $entries;
    }
}
