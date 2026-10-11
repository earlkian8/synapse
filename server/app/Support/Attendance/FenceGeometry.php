<?php

namespace App\Support\Attendance;

/**
 * The geometry of a fence drawn as a shape (ADR 0079): whether a point is in
 * it, how far outside it is, its centre, and whether its edges cross.
 *
 * A fence is the size of a building or a campus, so the shape is laid flat on a
 * local plane around the point asked about (an equirectangular projection) and
 * measured in metres there — off by far less than a phone's own doubt.
 *
 * Pure: no database, so the edges are table-testable. A shape is a list of
 * `[latitude, longitude]` corners, not closed (the last joins the first).
 */
final class FenceGeometry
{
    /** The Earth's mean radius, in metres. */
    private const EARTH_RADIUS_METERS = 6371008.8;

    /**
     * Whether a point lies inside the shape (ray casting). A point on an edge
     * counts as inside.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function contains(float $latitude, float $longitude, array $polygon): bool
    {
        if (self::distanceToEdge($latitude, $longitude, $polygon) < 0.01) {
            return true;
        }

        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$yi, $xi] = $polygon[$i];
            [$yj, $xj] = $polygon[$j];

            if ((($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < ($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * How far a point is from the shape, in metres: 0 inside it, otherwise the
     * distance to its nearest edge.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function distanceMeters(float $latitude, float $longitude, array $polygon): float
    {
        return self::contains($latitude, $longitude, $polygon)
            ? 0.0
            : self::distanceToEdge($latitude, $longitude, $polygon);
    }

    /**
     * The distance from a point to the nearest edge of the shape, in metres,
     * whichever side of it the point is on.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function distanceToEdge(float $latitude, float $longitude, array $polygon): float
    {
        $points = array_map(fn (array $corner): array => self::project($corner[0], $corner[1], $latitude, $longitude), $polygon);
        $count = count($points);
        $best = INF;

        for ($i = 0; $i < $count; $i++) {
            $best = min($best, self::pointToSegment([0.0, 0.0], $points[$i], $points[($i + 1) % $count]));
        }

        return $best;
    }

    /**
     * The shape's centre — the centroid of its area, or the average of its
     * corners for a shape too thin to have one.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     * @return array{0: float, 1: float} [latitude, longitude]
     */
    public static function centroid(array $polygon): array
    {
        $count = count($polygon);
        $originLat = array_sum(array_column($polygon, 0)) / $count;
        $originLng = array_sum(array_column($polygon, 1)) / $count;
        $points = array_map(fn (array $corner): array => self::project($corner[0], $corner[1], $originLat, $originLng), $polygon);

        $area = 0.0;
        $cx = 0.0;
        $cy = 0.0;

        for ($i = 0; $i < $count; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $count];
            $cross = $x1 * $y2 - $x2 * $y1;
            $area += $cross;
            $cx += ($x1 + $x2) * $cross;
            $cy += ($y1 + $y2) * $cross;
        }

        if (abs($area) < 1e-6) {
            return [$originLat, $originLng];
        }

        $cx /= 3 * $area;
        $cy /= 3 * $area;

        return self::unproject($cx, $cy, $originLat, $originLng);
    }

    /**
     * The shape's area, in square metres.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function areaSquareMeters(array $polygon): float
    {
        [$lat, $lng] = [$polygon[0][0], $polygon[0][1]];
        $points = array_map(fn (array $corner): array => self::project($corner[0], $corner[1], $lat, $lng), $polygon);
        $count = count($points);
        $area = 0.0;

        for ($i = 0; $i < $count; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $count];
            $area += $x1 * $y2 - $x2 * $y1;
        }

        return abs($area) / 2;
    }

    /**
     * How far the furthest corner is from a centre, in metres — the circle that
     * holds the shape.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function reachMeters(float $latitude, float $longitude, array $polygon): float
    {
        return max(array_map(
            fn (array $corner): float => GeofenceCheck::distanceMeters($latitude, $longitude, $corner[0], $corner[1]),
            $polygon,
        ));
    }

    /**
     * Whether any two edges that do not share a corner cross — a figure-of-eight
     * has no inside to speak of.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function selfIntersects(array $polygon): bool
    {
        $count = count($polygon);

        if ($count < 4) {
            return false;
        }

        [$lat, $lng] = [$polygon[0][0], $polygon[0][1]];
        $points = array_map(fn (array $corner): array => self::project($corner[0], $corner[1], $lat, $lng), $polygon);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // Neighbouring edges share a corner; that is not a crossing.
                if ($j === $i + 1 || ($i === 0 && $j === $count - 1)) {
                    continue;
                }

                if (self::segmentsCross($points[$i], $points[($i + 1) % $count], $points[$j], $points[($j + 1) % $count])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A point on the flat plane around an origin, in metres: x east, y north.
     *
     * @return array{0: float, 1: float}
     */
    private static function project(float $latitude, float $longitude, float $originLat, float $originLng): array
    {
        $x = deg2rad($longitude - $originLng) * self::EARTH_RADIUS_METERS * cos(deg2rad($originLat));
        $y = deg2rad($latitude - $originLat) * self::EARTH_RADIUS_METERS;

        return [$x, $y];
    }

    /**
     * @return array{0: float, 1: float} [latitude, longitude]
     */
    private static function unproject(float $x, float $y, float $originLat, float $originLng): array
    {
        $latitude = $originLat + rad2deg($y / self::EARTH_RADIUS_METERS);
        $longitude = $originLng + rad2deg($x / (self::EARTH_RADIUS_METERS * cos(deg2rad($originLat))));

        return [$latitude, $longitude];
    }

    /**
     * @param  array{0: float, 1: float}  $p
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function pointToSegment(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $length = $dx * $dx + $dy * $dy;
        $t = $length > 0 ? max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $length)) : 0.0;

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     * @param  array{0: float, 1: float}  $c
     * @param  array{0: float, 1: float}  $d
     */
    private static function segmentsCross(array $a, array $b, array $c, array $d): bool
    {
        $orient = fn (array $p, array $q, array $r): float => ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);

        $d1 = $orient($c, $d, $a);
        $d2 = $orient($c, $d, $b);
        $d3 = $orient($a, $b, $c);
        $d4 = $orient($a, $b, $d);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }
}
