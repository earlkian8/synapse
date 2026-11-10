<?php

namespace App\Support\CheckIns;

use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;

/**
 * Check-ins come once a month (ADR 0102), and a month is the organisation's: on
 * its clock, so a check-in answered at 11 pm on the 31st in Manila counts for that
 * month, whatever the server's clock says. A period is named by its first day.
 */
final class CheckInPeriod
{
    /** The current month's first day, on the organisation's clock. */
    public static function current(): CarbonImmutable
    {
        return CarbonImmutable::parse(OrganizationClock::today())->startOfMonth();
    }

    /**
     * The last `$count` periods, newest first, ending with the current one.
     *
     * @return list<CarbonImmutable>
     */
    public static function recent(int $count): array
    {
        $current = self::current();

        return array_map(fn (int $i): CarbonImmutable => $current->subMonthsNoOverflow($i), range(0, $count - 1));
    }

    /** A `YYYY-MM` or `YYYY-MM-DD` value as its period, or null when it is neither. */
    public static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $value)) {
            return null;
        }

        return CarbonImmutable::parse(strlen($value) === 7 ? "{$value}-01" : $value)->startOfMonth();
    }

    /** "November 2026". */
    public static function label(CarbonImmutable $period): string
    {
        return $period->format('F Y');
    }

    /** The days left to answer the current month's, counting today. */
    public static function daysLeft(): int
    {
        $today = CarbonImmutable::parse(OrganizationClock::today());

        return (int) $today->diffInDays($today->endOfMonth()->startOfDay()) + 1;
    }
}
