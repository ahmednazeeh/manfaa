<?php

namespace App\Domain\Zoning;

/**
 * Ray-casting point-in-polygon. Floats are fine here — this decides which
 * island a shop sits on, not money.
 *
 * The polygon is an ordered ring of ['lat' => float, 'lng' => float]; the
 * ring closes itself (last point connects back to first). A point exactly on
 * an edge may land either side — island polygons are drawn around coastline,
 * so nothing real sits on the border.
 */
final class PointInPolygon
{
    /**
     * @param  list<array{lat: float, lng: float}>  $polygon
     */
    public static function contains(float $lat, float $lng, array $polygon): bool
    {
        $count = count($polygon);

        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = (float) $polygon[$i]['lat'];
            $lngI = (float) $polygon[$i]['lng'];
            $latJ = (float) $polygon[$j]['lat'];
            $lngJ = (float) $polygon[$j]['lng'];

            // Does the horizontal ray east of the point cross edge i-j?
            $crosses = ($latI > $lat) !== ($latJ > $lat)
                && $lng < ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI;

            if ($crosses) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
