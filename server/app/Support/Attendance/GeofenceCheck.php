<?php

namespace App\Support\Attendance;

use App\Models\WorkLocation;
use Illuminate\Support\Collection;

/**
 * Whether a reported position is on a work location's site (ADR 0040).
 *
 * Distance is the haversine great-circle distance, which is exact enough at the
 * scale of a building. A phone reports how sure it is (`accuracy`, the radius in
 * metres it is 68% confident of), and that doubt is given to the person: a punch
 * is **inside** a fence when `distance − accuracy ≤ radius` — when the circle the
 * phone might be anywhere in touches the site. A fix that says "somewhere within
 * two kilometres" therefore reaches most fences; the distance and the accuracy
 * are both kept on the punch, so a reviewer can see how much benefit of the doubt
 * it took.
 *
 * Pure: no database, so the edges are table-testable.
 */
final class GeofenceCheck
{
    /** The Earth's mean radius, in metres. */
    private const EARTH_RADIUS_METERS = 6371008.8;

    /**
     * The great-circle distance between two points, in metres.
     */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }

    /**
     * Whether a position, give or take its accuracy, touches a fence.
     */
    public static function inside(float $distance, ?float $accuracy, int $radius): bool
    {
        return $distance - max(0.0, (float) $accuracy) <= $radius;
    }

    /**
     * Check a position against a set of sites. The verdict names the site that
     * matters: the nearest one the position is inside, or — when it is inside
     * none — the nearest one overall, so "320 m from Main Office" says how far off
     * it was. Null when there is no site to check against.
     *
     * @param  Collection<int, WorkLocation>  $locations
     */
    public static function check(float $latitude, float $longitude, ?float $accuracy, Collection $locations): ?GeofenceVerdict
    {
        $measured = $locations
            ->map(fn (WorkLocation $location): array => [
                $location,
                self::distanceMeters($latitude, $longitude, (float) $location->latitude, (float) $location->longitude),
            ])
            ->sortBy(fn (array $pair): float => $pair[1])
            ->values();

        if ($measured->isEmpty()) {
            return null;
        }

        $inside = $measured->first(fn (array $pair): bool => self::inside($pair[1], $accuracy, (int) $pair[0]->radius_meters));
        [$location, $distance] = $inside ?? $measured->first();

        return new GeofenceVerdict($location, (int) round($distance), $inside !== null);
    }
}
