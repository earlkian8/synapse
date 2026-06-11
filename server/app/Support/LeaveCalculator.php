<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Works out how many **working days** a leave request consumes.
 *
 * Weekends (Sat/Sun) are excluded; a single-day request may be a half day (0.5).
 * Public holidays are not modelled yet — there is no holiday calendar in the
 * system — so they currently count as working days. When a holiday calendar lands
 * (Company Setup → Work Schedule & Holidays), this is the one place to teach.
 *
 * Accepts any {@see CarbonInterface}, so it is agnostic to the app's
 * mutable/immutable date setting.
 */
class LeaveCalculator
{
    /**
     * Count the working (Mon–Fri) days in an inclusive date range.
     */
    public static function workingDays(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->lt($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        while ($cursor->lte($last)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }

            // Reassign so this works whether the instance is mutable or immutable.
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * The chargeable days for a request: half a day when it is a single half-day,
     * otherwise the working days in the range.
     */
    public static function chargeableDays(CarbonInterface $start, CarbonInterface $end, bool $isHalfDay): float
    {
        if ($isHalfDay && $start->isSameDay($end)) {
            return $start->isWeekend() ? 0.0 : 0.5;
        }

        return (float) self::workingDays($start, $end);
    }
}
