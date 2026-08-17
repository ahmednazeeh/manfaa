<?php

namespace App\Domain\Zoning;

use App\Models\MerchantBranch;
use App\Models\Zone;

/**
 * Keeps `merchant_branches.zone_id` true to the drawn polygons.
 *
 * Geometry runs at WRITE time only: when a branch's pin moves (model saving
 * hook) and when a zone is created/edited/deleted (full recompute). Reads —
 * the discovery zone filter, the picker counts — are plain integer columns.
 *
 * Scale check: dozens of zones × a few hundred branches × dozens of polygon
 * points is thousands of float comparisons — not worth a queue.
 */
final class ZoneAssigner
{
    /**
     * The zone containing this point, or null. First match wins — islands
     * are drawn around coastline and do not overlap; if two polygons ever
     * do, the older zone keeps the branch (stable, not arbitrary).
     */
    public static function zoneIdFor(?float $lat, ?float $lng): ?int
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        foreach (Zone::query()->orderBy('id')->get(['id', 'polygon']) as $zone) {
            if (PointInPolygon::contains($lat, $lng, $zone->polygon)) {
                return $zone->id;
            }
        }

        return null;
    }

    /**
     * Recompute every pinned branch — the zone set changed underneath them.
     * Unpinned branches stay zoneless by definition.
     */
    public static function reassignAll(): void
    {
        $zones = Zone::query()->orderBy('id')->get(['id', 'polygon']);

        MerchantBranch::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'lat', 'lng', 'zone_id'])
            ->each(function (MerchantBranch $branch) use ($zones): void {
                $zoneId = null;

                foreach ($zones as $zone) {
                    if (PointInPolygon::contains((float) $branch->lat, (float) $branch->lng, $zone->polygon)) {
                        $zoneId = $zone->id;
                        break;
                    }
                }

                if ($branch->zone_id !== $zoneId) {
                    // Quiet update: the saving hook recomputes from
                    // coordinates anyway, so this cannot loop.
                    $branch->forceFill(['zone_id' => $zoneId])->saveQuietly();
                }
            });
    }
}
