<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Great-circle distance between two coordinates. Floats are fine here —
 * the integer-only rule is a MONEY rule (§4); geography is not money.
 * Distances are returned as integer metres.
 */
final class Haversine
{
    private const float EARTH_RADIUS_M = 6371000.0;

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $latRad1 = deg2rad($lat1);
        $latRad2 = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($latRad1) * cos($latRad2) * sin($deltaLng / 2) ** 2;

        return (int) round(self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
