<?php

namespace App\Support\Ml\Graduation;

/**
 * A plain-language projection of when a shortfall closes, from how fast this
 * organisation has actually been recording what is counted over the last two years.
 * Straight-line and rounded up — an order of magnitude, not a promise.
 */
final class Pace
{
    /** Beyond this many years a projection says nothing useful, so it is not given. */
    public const HORIZON_YEARS = 20;

    /**
     * @param  int  $remaining  how many more are needed
     * @param  int  $lastTwoYears  how many were recorded in the last two years
     * @param  string  $what  what is counted, plural ("promotions")
     * @param  string  $one  what is counted, singular ("promotion")
     */
    public static function outlook(int $remaining, int $lastTwoYears, string $what, string $one): ?string
    {
        if ($remaining <= 0) {
            return null;
        }

        if ($lastTwoYears <= 0) {
            return "None were recorded in the last two years, so at the current pace this won’t be reached. It moves only as {$what} are recorded.";
        }

        $perYear = $lastTwoYears / 2.0;
        $rate = match (true) {
            $perYear < 1 => "one {$one} every two years",
            $perYear === 1.0 => "one {$one} a year",
            default => 'about '.(int) round($perYear)." {$what} a year",
        };
        $years = (int) ceil($remaining / $perYear);

        if ($years > self::HORIZON_YEARS) {
            return "At your recent pace — {$rate} — the {$remaining} still needed is well over ".self::HORIZON_YEARS.' years away.';
        }

        $span = $years === 1 ? 'about a year' : "roughly {$years} years";

        return "At your recent pace — {$rate} — the {$remaining} still needed is {$span} away, around ".(now()->year + $years).'.';
    }
}
