<?php

namespace App\Queries;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ShiftRosterEntry;
use App\Support\Attendance\ResolvedShift;
use App\Support\Attendance\ShiftResolver;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The roster board: who works what, across a week (ADR 0037) — employees down,
 * days across, each cell the shift {@see ShiftResolver} says applies and the link
 * in the chain it came from.
 *
 * It is the one screen that shows the *plan* rather than the record, so it reads
 * forward as happily as back: a week that has not happened yet is exactly as
 * legible as last week's.
 */
class ShiftRosterQuery
{
    /** How many days the board shows at once. */
    public const DAYS = 7;

    public function __construct(private readonly ShiftResolver $shifts = new ShiftResolver) {}

    /**
     * The week containing `$date`, as the board renders it.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $date, ?int $department = null, string $search = ''): array
    {
        $start = CarbonImmutable::parse($date)->startOfWeek();
        $end = $start->addDays(self::DAYS - 1);
        $today = OrganizationClock::today();

        $employees = Employee::query()
            ->with(['department:id,name'])
            ->when($department, fn (Builder $q) => $q->where('department_id', $department))
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $shifts = $this->shifts->forMany($employees, $start->toDateString(), $end->toDateString());
        $holidays = HolidayCalendar::inRange($start, $end);

        // Which cells carry an override, so one can be edited or cleared without
        // guessing from the source alone.
        $entries = ShiftRosterEntry::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(
                fn (ShiftRosterEntry $entry): string => $entry->date->toDateString(),
            ));

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $this->days($start, $today, $holidays),
            'rows' => $employees
                ->map(fn (Employee $employee): array => [
                    'employee' => [
                        'id' => $employee->id,
                        'full_name' => $employee->full_name,
                        'initials' => $employee->initials(),
                        'employee_no' => $employee->employee_no,
                        'photo' => $employee->photo_url,
                        'department' => $employee->department
                            ? ['id' => $employee->department->id, 'name' => $employee->department->name]
                            : null,
                    ],
                    'cells' => $this->cells(
                        $shifts[$employee->id] ?? [],
                        $entries->get($employee->id) ?? collect(),
                        $start,
                    ),
                ])
                ->all(),
        ];
    }

    /**
     * The column headers: each day, whether it is today, and the holiday on it.
     *
     * @param  array<string, Holiday>  $holidays
     * @return list<array<string, mixed>>
     */
    private function days(CarbonImmutable $start, string $today, array $holidays): array
    {
        $out = [];

        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $start->addDays($i);
            $date = $day->toDateString();

            $out[] = [
                'date' => $date,
                'weekday' => $day->format('D'),
                'day' => $day->format('j'),
                'is_today' => $date === $today,
                'holiday' => $holidays[$date]?->name ?? null,
            ];
        }

        return $out;
    }

    /**
     * One employee's week: the shift for each day, how it reads, and where it
     * came from.
     *
     * @param  array<string, ResolvedShift>  $shifts
     * @param  Collection<string, ShiftRosterEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function cells(array $shifts, Collection $entries, CarbonImmutable $start): array
    {
        $out = [];

        for ($i = 0; $i < self::DAYS; $i++) {
            $date = $start->addDays($i)->toDateString();
            $shift = $shifts[$date] ?? ResolvedShift::fallback($date);
            $entry = $entries->get($date);

            $out[] = [
                'date' => $date,
                'label' => $shift->label(),
                'type' => $shift->type,
                'is_working_day' => $shift->isWorkingDay,
                'segments' => $shift->segments,
                'required_minutes' => $shift->requiredMinutes,
                'schedule_name' => $shift->scheduleName,
                'source' => $shift->source,
                'entry_hashid' => $entry?->hashid,
                'reason' => $entry?->reason,
            ];
        }

        return $out;
    }
}
