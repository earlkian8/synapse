<?php

namespace App\Support;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
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

    /**
     * The holiday on each date of an inclusive range, keyed "Y-m-d" — every type,
     * `special_working` included, because Attendance records a working holiday as
     * a holiday even though nobody is excused from it. Where two holidays share a
     * date, a non-working one wins: it is the one that decides whether anybody is
     * expected at work. One query for the whole range, however long.
     *
     * @return array<string, Holiday>
     */
    public static function inRange(CarbonInterface $start, CarbonInterface $end): array
    {
        if ($end->lt($start)) {
            return [];
        }

        $holidays = Holiday::query()
            ->get(['id', 'name', 'date', 'type', 'is_recurring'])
            ->sortBy(fn (Holiday $holiday): int => in_array($holiday->type, Holiday::NON_WORKING_TYPES, true) ? 0 : 1)
            ->values();

        if ($holidays->isEmpty()) {
            return [];
        }

        $found = [];
        $cursor = CarbonImmutable::instance($start)->startOfDay();
        $last = CarbonImmutable::instance($end)->startOfDay();

        while ($cursor->lte($last)) {
            $holiday = $holidays->first(fn (Holiday $holiday): bool => $holiday->fallsOn($cursor));

            if ($holiday !== null) {
                $found[$cursor->toDateString()] = $holiday;
            }

            $cursor = $cursor->addDay();
        }

        return $found;
    }

    /**
     * The holiday on one calendar date ("Y-m-d"), of any type, or null.
     */
    public static function on(string $date): ?Holiday
    {
        $day = CarbonImmutable::parse($date);

        return self::inRange($day, $day)[$day->toDateString()] ?? null;
    }
}
