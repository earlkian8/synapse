<?php

namespace App\Support\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ShiftRosterEntry;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Which shift applies to this person on this day (ADR 0037).
 *
 * The one place that answers it, so the board, the roster, the punch engine, the
 * mobile API and the assistant never disagree. Precedence, most specific first:
 *
 *  1. **roster** — a one-off entry for that employee and date.
 *  2. **assignment** — the dated assignment covering the date.
 *  3. **employee** — the denormalised `employees.work_schedule_id`, for rows made
 *     before assignments existed (or by a seeder that only set the pointer).
 *  4. **department** — the department's default schedule.
 *  5. **location** — the default schedule of the person's primary work
 *     location (ADR 0040).
 *  6. **organization** — the company's default schedule.
 *  7. **fallback** — Mon–Fri 08:00–17:00, eight hours ({@see ResolvedShift::fallback()}).
 *
 * {@see forMany()} answers for a whole roster over a whole range in a fixed
 * number of queries — six, whatever the range — so the weekly grid and the
 * monthly report do not grow a query per day.
 */
class ShiftResolver
{
    /**
     * Schedules already loaded with their day pattern, keyed by id. Held for the
     * life of the resolver so a board that asks about the same shift 300 times
     * reads it once.
     *
     * @var array<int, WorkSchedule>
     */
    private array $schedules = [];

    /**
     * The shift one employee works on one date.
     */
    public function for(Employee $employee, string $date): ResolvedShift
    {
        return $this->forMany(collect([$employee]), $date, $date)[$employee->id][$date]
            ?? ResolvedShift::fallback($date);
    }

    /**
     * Every shift for every employee across [$from, $to], as
     * `[employeeId][Y-m-d] => ResolvedShift`.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, ResolvedShift>>
     */
    public function forMany(Collection $employees, string $from, string $to): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $dates = $this->dates($from, $to);
        $ids = $employees->pluck('id')->all();

        $roster = $this->rosterEntries($ids, $from, $to);
        $assignments = $this->assignments($ids, $from, $to);
        $departmentDefaults = $this->departmentDefaults($employees);
        $locationDefaults = array_map(
            fn (WorkLocation $location): ?int => $location->default_work_schedule_id !== null ? (int) $location->default_work_schedule_id : null,
            WorkLocation::primaryFor($employees),
        );
        $organizationDefault = $this->organizationDefault();

        $this->preloadSchedules(array_filter(array_merge(
            $roster->flatten(1)->pluck('work_schedule_id')->all(),
            $assignments->flatten(1)->pluck('work_schedule_id')->all(),
            $employees->pluck('work_schedule_id')->all(),
            array_values($departmentDefaults),
            array_values($locationDefaults),
            [$organizationDefault],
        )));

        $out = [];

        foreach ($employees as $employee) {
            $ownRoster = $roster->get($employee->id) ?? collect();
            $ownAssignments = $assignments->get($employee->id) ?? collect();
            $departmentDefault = $departmentDefaults[$employee->department_id] ?? null;
            $locationDefault = $locationDefaults[$employee->id] ?? null;

            foreach ($dates as $date) {
                $out[$employee->id][$date] = $this->resolve(
                    $date,
                    $ownRoster->get($date),
                    $ownAssignments->first(fn (EmployeeScheduleAssignment $a): bool => $this->covers($a, $date)),
                    $employee->work_schedule_id,
                    $departmentDefault,
                    $locationDefault,
                    $organizationDefault,
                );
            }
        }

        return $out;
    }

    /**
     * Walk the precedence chain for one date and build the shift the winner says.
     */
    private function resolve(
        string $date,
        ?ShiftRosterEntry $roster,
        ?EmployeeScheduleAssignment $assignment,
        ?int $employeeScheduleId,
        ?int $departmentScheduleId,
        ?int $locationScheduleId,
        ?int $organizationScheduleId,
    ): ResolvedShift {
        if ($roster !== null) {
            return $this->fromRosterEntry($roster, $date);
        }

        if ($assignment !== null) {
            $shift = $this->fromSchedule($assignment->work_schedule_id, $date, 'assignment', $assignment->cycle_offset);

            if ($shift !== null) {
                return $shift;
            }
        }

        foreach ([[$employeeScheduleId, 'employee'], [$departmentScheduleId, 'department'], [$locationScheduleId, 'location'], [$organizationScheduleId, 'organization']] as [$id, $source]) {
            $shift = $this->fromSchedule($id, $date, $source);

            if ($shift !== null) {
                return $shift;
            }
        }

        return ResolvedShift::fallback($date);
    }

    /**
     * The shift a template gives a date, or null when the template is missing.
     */
    private function fromSchedule(?int $scheduleId, string $date, string $source, int $cycleOffset = 0): ?ResolvedShift
    {
        $schedule = $scheduleId === null ? null : ($this->schedules[$scheduleId] ?? null);

        if ($schedule === null) {
            return null;
        }

        $index = $this->dayIndex($schedule, $date, $cycleOffset);
        $day = $schedule->patternDays()->get($index);

        // A rotation whose pattern is short of a day is a misconfiguration, not a
        // reason to judge somebody by hours nobody wrote: treat it as a rest day.
        $isRestDay = $day === null || $day->is_rest_day;
        $segments = $isRestDay ? [] : $this->segments($day->segments);

        return new ResolvedShift(
            date: $date,
            type: in_array($schedule->type, WorkSchedule::TYPES, true) ? $schedule->type : 'fixed',
            isWorkingDay: ! $isRestDay,
            segments: $segments,
            requiredMinutes: $isRestDay ? 0 : (int) ($day->required_minutes ?? DayRules::DEFAULT_REQUIRED_MINUTES),
            graceMinutes: (int) ($schedule->grace_minutes ?? 0),
            unpaidBreakMinutes: (int) ($day?->unpaid_break_minutes ?? 0),
            source: $source,
            coreStart: $day?->core_start,
            coreEnd: $day?->core_end,
            earliestStart: $day?->earliest_start,
            latestEnd: $day?->latest_end,
            scheduleId: $schedule->id,
            scheduleName: $schedule->name,
            cycleOffset: $cycleOffset,
            dayIndex: $index,
        );
    }

    /**
     * A one-off roster entry: a rest day, another template's pattern for the
     * date, or times written on the entry itself.
     */
    private function fromRosterEntry(ShiftRosterEntry $entry, string $date): ResolvedShift
    {
        if ($entry->is_rest_day) {
            return new ResolvedShift(
                date: $date,
                type: 'fixed',
                isWorkingDay: false,
                segments: [],
                requiredMinutes: 0,
                graceMinutes: 0,
                unpaidBreakMinutes: 0,
                source: 'roster',
            );
        }

        $segments = $this->segments($entry->segments);

        // Times of its own win; otherwise the borrowed template's pattern for
        // that date, relabelled as the override it is.
        if ($segments === [] && $entry->work_schedule_id !== null) {
            $borrowed = $this->fromSchedule($entry->work_schedule_id, $date, 'roster');

            if ($borrowed !== null) {
                return $borrowed;
            }
        }

        $schedule = $entry->work_schedule_id === null ? null : ($this->schedules[$entry->work_schedule_id] ?? null);

        return new ResolvedShift(
            date: $date,
            type: 'fixed',
            isWorkingDay: $segments !== [],
            segments: $segments,
            requiredMinutes: (int) ($entry->required_minutes ?? $this->spanMinutes($segments)),
            graceMinutes: (int) ($schedule?->grace_minutes ?? 0),
            unpaidBreakMinutes: 0,
            source: 'roster',
            scheduleId: $schedule?->id,
            scheduleName: $schedule?->name,
        );
    }

    /**
     * Which day of a schedule's cycle a date is. A weekly pattern indexes by
     * weekday (1 = Monday); a rotation counts days from its anchor, shifted by the
     * crew's offset, so two crews on one template never work the same day.
     */
    private function dayIndex(WorkSchedule $schedule, string $date, int $cycleOffset): int
    {
        $length = max(1, (int) $schedule->cycle_length_days);

        if ($length === WorkSchedule::WEEKLY_CYCLE_LENGTH && $schedule->cycle_anchor_date === null) {
            return CarbonImmutable::parse($date)->isoWeekday();
        }

        $anchor = $schedule->cycle_anchor_date !== null
            ? CarbonImmutable::parse($schedule->cycle_anchor_date->toDateString())
            : CarbonImmutable::parse($date)->startOfWeek();

        $elapsed = (int) $anchor->diffInDays(CarbonImmutable::parse($date), false);

        // PHP's % keeps the sign of the dividend, so dates before the anchor need
        // the extra turn to land in 1..length.
        return (($elapsed + $cycleOffset) % $length + $length) % $length + 1;
    }

    /**
     * Normalise a stored segment list: well-formed "HH:MM" pairs, in order.
     *
     * @param  mixed  $segments
     * @return list<array{start: string, end: string}>
     */
    private function segments($segments): array
    {
        if (! is_array($segments)) {
            return [];
        }

        $out = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $start = WorkSchedule::clockFace($segment['start'] ?? null);
            $end = WorkSchedule::clockFace($segment['end'] ?? null);

            if ($start !== null && $end !== null) {
                $out[] = ['start' => $start, 'end' => $end];
            }
        }

        return $out;
    }

    /**
     * How long a segment list runs, in minutes — the required minutes a roster
     * entry gets when it does not name its own.
     *
     * @param  list<array{start: string, end: string}>  $segments
     */
    private function spanMinutes(array $segments): int
    {
        $total = 0;

        foreach ($segments as $segment) {
            $start = $this->minuteOfDay($segment['start']);
            $end = $this->minuteOfDay($segment['end']);

            $total += $end <= $start ? 1440 - $start + $end : $end - $start;
        }

        return $total;
    }

    /**
     * "HH:MM" as minutes past midnight.
     */
    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }

    /**
     * Whether an assignment's range covers a date.
     */
    private function covers(EmployeeScheduleAssignment $assignment, string $date): bool
    {
        if ($assignment->effective_from->toDateString() > $date) {
            return false;
        }

        return $assignment->effective_to === null || $assignment->effective_to->toDateString() >= $date;
    }

    /**
     * Every date in the inclusive range.
     *
     * @return list<string>
     */
    private function dates(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $dates = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * Roster overrides in the window, per employee, keyed by date.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, Collection<string, ShiftRosterEntry>>
     */
    private function rosterEntries(array $employeeIds, string $from, string $to): Collection
    {
        return ShiftRosterEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(
                fn (ShiftRosterEntry $entry): string => $entry->date->toDateString(),
            ));
    }

    /**
     * Assignments touching the window, per employee, most specific (latest
     * start) first so the first match wins.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, Collection<int, EmployeeScheduleAssignment>>
     */
    private function assignments(array $employeeIds, string $from, string $to): Collection
    {
        return EmployeeScheduleAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->overlapping($from, $to)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * The default schedule of every department on the roster, keyed by department.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, int>
     */
    private function departmentDefaults(Collection $employees): array
    {
        $ids = $employees->pluck('department_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Department::query()
            ->whereIn('id', $ids)
            ->whereNotNull('default_work_schedule_id')
            ->pluck('default_work_schedule_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The organisation's own default schedule, if it has set one.
     */
    private function organizationDefault(): ?int
    {
        $id = app(Tenancy::class)->organization()?->default_work_schedule_id;

        return $id === null ? null : (int) $id;
    }

    /**
     * Load every schedule the chain might reach, with its day pattern, in one
     * query — including archived ones, so an assignment to a retired shift still
     * resolves.
     *
     * @param  array<int, int|string|null>  $ids
     */
    private function preloadSchedules(array $ids): void
    {
        $missing = collect($ids)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => isset($this->schedules[$id]))
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        WorkSchedule::withTrashed()
            ->whereIn('id', $missing)
            ->with(['days' => fn ($query) => $query->orderBy('day_index')])
            ->get()
            ->each(function (WorkSchedule $schedule): void {
                // Materialise the pattern once — a schedule with no day rows
                // synthesises seven from its legacy columns, and doing that per
                // employee per day would be the query-count regression this class
                // exists to avoid.
                $schedule->setRelation('days', new EloquentCollection($schedule->patternDays()->values()->all()));

                $this->schedules[$schedule->id] = $schedule;
            });
    }
}
