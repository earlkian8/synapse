<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resolves the organisation's holidays within a range — the bridge between the
 * {@see Holiday} calendar (Company Setup → Work Schedule & Holidays) and the
 * modules that must treat a holiday as a day off: {@see LeaveCalculator} (a
 * holiday is not charged as a leave day) and Attendance.
 *
 * Every repeating holiday is placed on whichever year(s) the range spans: a
 * fixed date like New Year on its month and day, Holy Week from that year's
 * Easter, National Heroes Day on that year's last Monday of August (ADR 0078).
 *
 * A local holiday is kept only at its sites. Each method takes the site the
 * person is based at ({@see siteOf()}); without one, only the holidays kept
 * everywhere count. {@see forEmployees()} answers for many people at once.
 */
class HolidayCalendar
{
    /** The columns placing a holiday needs. */
    private const COLUMNS = ['id', 'name', 'date', 'type', 'is_recurring', 'recurrence', 'rule'];

    /**
     * The distinct non-working holiday dates (as "Y-m-d") that fall within an
     * inclusive range. Tenant-scoped via the {@see Holiday} global scope.
     *
     * @return list<string>
     */
    public static function datesInRange(CarbonInterface $start, CarbonInterface $end, ?int $locationId = null): array
    {
        $found = self::inRange($start, $end, $locationId);

        return array_values(array_keys(array_filter(
            $found,
            fn (Holiday $holiday): bool => in_array($holiday->type, Holiday::NON_WORKING_TYPES, true),
        )));
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
    public static function inRange(CarbonInterface $start, CarbonInterface $end, ?int $locationId = null): array
    {
        if ($end->lt($start)) {
            return [];
        }

        return self::place(
            Holiday::query()->keptAt($locationId)->get(self::COLUMNS),
            CarbonImmutable::instance($start),
            CarbonImmutable::instance($end),
        );
    }

    /**
     * The holiday on one calendar date ("Y-m-d"), of any type, or null.
     */
    public static function on(string $date, ?int $locationId = null): ?Holiday
    {
        $day = CarbonImmutable::parse($date);

        return self::inRange($day, $day, $locationId)[$day->toDateString()] ?? null;
    }

    /**
     * The site whose local holidays a person keeps: their base — the primary
     * site, or their only one ({@see WorkLocation::primaryFor()}).
     */
    public static function siteOf(Employee|int|null $employee): ?int
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        if ($id === null) {
            return null;
        }

        $base = WorkLocation::primaryFor(collect([['id' => $id]]))[$id] ?? null;

        return $base?->id;
    }

    /**
     * The holidays of a whole roster across a range, in two queries: each
     * person gets the ones kept everywhere and their base site's own.
     *
     * @param  Collection<int, Employee>  $employees
     */
    public static function forEmployees(Collection $employees, CarbonInterface $start, CarbonInterface $end): HolidayMap
    {
        if ($end->lt($start)) {
            return HolidayMap::empty();
        }

        $from = CarbonImmutable::instance($start);
        $to = CarbonImmutable::instance($end);

        $holidays = Holiday::query()->with('locations:id')->get(self::COLUMNS);
        $everywhere = $holidays->filter(fn (Holiday $holiday): bool => $holiday->locations->isEmpty());

        $siteOf = array_map(
            fn (WorkLocation $site): int => (int) $site->id,
            WorkLocation::primaryFor($employees),
        );

        $bySite = [];

        foreach (array_unique(array_values($siteOf)) as $siteId) {
            $local = $holidays->filter(fn (Holiday $holiday): bool => $holiday->locations->contains('id', $siteId));

            if ($local->isNotEmpty()) {
                $bySite[$siteId] = self::place($everywhere->concat($local), $from, $to);
            }
        }

        return new HolidayMap(self::place($everywhere, $from, $to), $bySite, $siteOf);
    }

    /**
     * Put each holiday on the dates it falls on within the range, keyed "Y-m-d",
     * a non-working holiday winning a date two share.
     *
     * @param  Collection<int, Holiday>  $holidays
     * @return array<string, Holiday>
     */
    private static function place(Collection $holidays, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $found = [];

        $ordered = $holidays
            ->sortBy(fn (Holiday $holiday): int => in_array($holiday->type, Holiday::NON_WORKING_TYPES, true) ? 0 : 1)
            ->values();

        foreach ($ordered as $holiday) {
            for ($year = $from->year; $year <= $to->year; $year++) {
                $date = $holiday->occurrenceIn($year);

                if ($date !== null && $date->betweenIncluded($from, $to)) {
                    $found[$date->toDateString()] ??= $holiday;
                }
            }
        }

        ksort($found);

        return $found;
    }
}
