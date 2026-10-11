<?php

namespace App\Support;

/**
 * Distance between two points on the earth.
 *
 * Kept in PHP rather than pushed into SQL. A haversine expression needs
 * `radians()`, `sin()` and `cos()`, which PostgreSQL has and SQLite — the
 * database the test suite runs on — does not, so a SQL implementation would
 * work in production and fail under test. The candidate sets here are a town
 * list and a page of results, so the arithmetic is not the bottleneck.
 */
final class Geo
{
    /** Mean earth radius in kilometres. */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Great-circle distance in kilometres, or null if either point is unknown.
     *
     * Null rather than 0 for a missing coordinate: "we do not know how far away
     * this is" must not render as "this is next door".
     */
    public static function distanceKm(
        float|string|null $fromLatitude,
        float|string|null $fromLongitude,
        float|string|null $toLatitude,
        float|string|null $toLongitude,
    ): ?float {
        if ($fromLatitude === null || $fromLongitude === null) {
            return null;
        }

        if ($toLatitude === null || $toLongitude === null) {
            return null;
        }

        $lat1 = deg2rad((float) $fromLatitude);
        $lat2 = deg2rad((float) $toLatitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float) $toLongitude) - deg2rad((float) $fromLongitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        // min(1, …) guards the domain of asin against floating-point drift,
        // which would otherwise produce NAN for two identical points.
        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * A latitude/longitude box that fully contains a radius around a point.
     *
     * Exists so a radius filter can be pushed into SQL as four plain comparisons
     * on indexed decimal columns — no trigonometry, and therefore identical
     * behaviour on PostgreSQL and SQLite. The box over-covers (it is a square
     * around a circle), so callers refine the survivors with `distanceKm()`
     * before showing them; the box only removes the rows that cannot possibly
     * qualify.
     *
     * @return array{min_latitude: float, max_latitude: float, min_longitude: float, max_longitude: float}
     */
    public static function boundingBox(float $latitude, float $longitude, float $radiusKm): array
    {
        $latitudeDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);

        /*
         * A degree of longitude shrinks towards the poles, so the box has to
         * widen as latitude grows. Near the pole cos() approaches zero and the
         * box would span the globe — clamped at 180, which is the widest a
         * longitude range can meaningfully be.
         */
        $cosine = cos(deg2rad($latitude));
        $longitudeDelta = $cosine <= 0.000001
            ? 180.0
            : min(180.0, rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cosine)));

        return [
            'min_latitude' => max(-90.0, $latitude - $latitudeDelta),
            'max_latitude' => min(90.0, $latitude + $latitudeDelta),
            'min_longitude' => max(-180.0, $longitude - $longitudeDelta),
            'max_longitude' => min(180.0, $longitude + $longitudeDelta),
        ];
    }
}
