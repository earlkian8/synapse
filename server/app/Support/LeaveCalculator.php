<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Works out how many **working days** a leave request consumes.
 *
 * Weekends (Sat/Sun) are excluded, as are any **non-working holiday** dates passed
 * in (resolved by {@see HolidayCalendar} from Company Setup → Work Schedule &
 * Holidays). A single-day request may be a half day (0.5). This stays a pure date
 * utility — callers supply the holiday set so it is trivially testable.
 *
 * Accepts any {@see CarbonInterface}, so it is agnostic to the app's
 * mutable/immutable date setting.
 */
class LeaveCalculator
{
    /**
     * Count the working (Mon–Fri, excluding the given holiday dates) days in an
     * inclusive range.
     *
     * @param  list<string>  $holidays  Non-working dates as "Y-m-d".
     */
    public static function workingDays(CarbonInterface $start, CarbonInterface $end, array $holidays = []): int
    {
        if ($end->lt($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        while ($cursor->lte($last)) {
            if (! $cursor->isWeekend() && ! in_array($cursor->toDateString(), $holidays, true)) {
                $days++;
            }

            // Reassign so this works whether the instance is mutable or immutable.
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * The chargeable days for a request: half a day when it is a single half-day
     * (and that day is a working day), otherwise the working days in the range.
     *
     * @param  list<string>  $holidays  Non-working dates as "Y-m-d".
     */
    public static function chargeableDays(CarbonInterface $start, CarbonInterface $end, bool $isHalfDay, array $holidays = []): float
    {
        if ($isHalfDay && $start->isSameDay($end)) {
            $offDay = $start->isWeekend() || in_array($start->toDateString(), $holidays, true);

            return $offDay ? 0.0 : 0.5;
        }

        return (float) self::workingDays($start, $end, $holidays);
    }
}
