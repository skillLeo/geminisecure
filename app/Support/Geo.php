<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Distance on the ground between two coordinates, in metres (13 D2).
 *
 * Haversine on a spherical Earth. At the scale of a gatehouse its error is well
 * under a centimetre, which is far inside a handset's GPS accuracy — the figure
 * that actually bounds whether a guard is on post.
 */
final class Geo
{
    private const EARTH_RADIUS_M = 6_371_008.8;

    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
