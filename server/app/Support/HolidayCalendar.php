<?php

namespace App\Support;

use App\Models\Holiday;
use Carbon\CarbonInterface;

/**
 * Resolves the organisation's **non-working** holiday dates within a range — the
 * bridge between the {@see Holiday} calendar (Company Setup → Work Schedule &
 * Holidays) and the modules that must treat a holiday as a day off, starting with
 * {@see LeaveCalculator} (a holiday is not charged as a leave day).
 *
 * Yearly-recurring holidays are expanded onto whichever year(s) the range spans,
 * so a fixed-date holiday like New Year is honoured every year from one row.
 */
class HolidayCalendar
{
    /**
     * The distinct non-working holiday dates (as "Y-m-d") that fall within an
     * inclusive range. Tenant-scoped via the {@see Holiday} global scope.
     *
     * @return list<string>
     */
    public static function datesInRange(CarbonInterface $start, CarbonInterface $end): array
    {
        if ($end->lt($start)) {
            return [];
        }

        $holidays = Holiday::query()->nonWorking()->get(['date', 'is_recurring']);

        if ($holidays->isEmpty()) {
            return [];
        }

        $dates = [];
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        while ($cursor->lte($last)) {
            foreach ($holidays as $holiday) {
                if ($holiday->fallsOn($cursor)) {
                    $dates[$cursor->toDateString()] = true;
                    break;
                }
            }

            // Reassign so this works whether the instance is mutable or immutable.
            $cursor = $cursor->addDay();
        }

        return array_keys($dates);
    }
}
