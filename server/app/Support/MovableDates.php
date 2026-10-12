<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Where a holiday that moves falls in a given year (ADR 0078): a day counted
 * from Easter Sunday — Holy Week — or the n-th weekday of a month, such as
 * National Heroes Day, the last Monday of August.
 *
 * Pure date arithmetic: no calendar extension and no table of dates, so any
 * year can be asked.
 */
final class MovableDates
{
    /**
     * Easter Sunday in the given year — the anonymous Gregorian computation
     * (Meeus/Jones/Butcher).
     */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    /**
     * The day `$offset` days from Easter Sunday — Maundy Thursday is −3.
     */
    public static function fromEaster(int $year, int $offset): CarbonImmutable
    {
        return self::easter($year)->addDays($offset);
    }

    /**
     * The `$week`-th `$weekday` of a month: week 1–4, or −1 for the last.
     * `$weekday` is ISO (1 = Monday … 7 = Sunday). Null when the month has no
     * such day (there is never a fifth week here, so in practice never).
     */
    public static function nthWeekday(int $year, int $month, int $weekday, int $week): ?CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);

        if ($week === -1) {
            $last = $first->endOfMonth()->startOfDay();
            $back = ($last->isoWeekday() - $weekday + 7) % 7;

            return $last->subDays($back);
        }

        $forward = ($weekday - $first->isoWeekday() + 7) % 7;
        $date = $first->addDays($forward + 7 * ($week - 1));

        return $date->month === $month ? $date : null;
    }
}
