<?php

namespace App\Queries;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\DayRules;
use App\Support\Attendance\ResolvedShift;
use App\Support\Attendance\ShiftResolver;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds a per-employee, per-day matrix of attendance across a date range — the
 * shared foundation for the weekly grid ({@see AttendanceWeeklyQuery}) and the
 * monthly report ({@see AttendanceMonthlyReport}).
 *
 * Like the daily roster, every (filtered) employee appears for every day: a saved
 * {@see AttendanceRecord}'s status + totals where one exists, otherwise a
 * synthesized cell (on_leave / holiday / day_off / absent). Days after the
 * organisation's today carry a null status (nothing has happened yet) so the UI
 * can leave them blank.
 */
class AttendanceRangeQuery
{
    public function __construct(private readonly ShiftResolver $shifts = new ShiftResolver) {}

    /**
     * Eligible employees paired with their ordered day-cells for [start, end].
     *
     * @return Collection<int, array{employee: Employee, cells: list<array<string, mixed>>}>
     */
    public function days(string $start, string $end, ?int $department = null, string $search = ''): Collection
    {
        $startDate = CarbonImmutable::parse($start)->startOfDay();
        $endDate = CarbonImmutable::parse($end)->startOfDay();
        $today = CarbonImmutable::parse(OrganizationClock::today());

        $employees = Employee::query()
            ->with(['department:id,name'])
            ->when($department, fn (Builder $q) => $q->where('department_id', $department))
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        // All saved records in the window, grouped by employee then keyed by date.
        $records = AttendanceRecord::query()
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(
                fn (AttendanceRecord $record): string => $record->work_date->format('Y-m-d'),
            ));

        // Approved leave overlapping the window, grouped by employee.
        $leaves = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        // The window's holidays, loaded once and keyed by date.
        $holidays = HolidayCalendar::inRange($startDate, $endDate);

        // Every shift in the window, resolved in a fixed number of queries
        // however wide the range is (ADR 0037).
        $shifts = $this->shifts->forMany($employees, $startDate->toDateString(), $endDate->toDateString());

        return $employees
            ->map(function (Employee $employee) use ($records, $leaves, $holidays, $shifts, $startDate, $endDate, $today): array {
                $own = $records->get($employee->id) ?? collect();
                $ownLeaves = $leaves->get($employee->id) ?? collect();
                $ownShifts = $shifts[$employee->id] ?? [];

                $cells = [];

                for ($day = $startDate; $day->lte($endDate); $day = $day->addDay()) {
                    $date = $day->format('Y-m-d');

                    $cells[] = $this->cell(
                        $day,
                        $today,
                        $ownShifts[$date] ?? ResolvedShift::fallback($date),
                        $own,
                        $ownLeaves,
                        $holidays[$date] ?? null,
                    );
                }

                return ['employee' => $employee, 'cells' => $cells];
            })
            ->values();
    }

    /**
     * One day's cell for an employee: the saved record's figures, or a synthesized
     * status when there is none (and a null status for days still in the future).
     *
     * @param  Collection<string, AttendanceRecord>  $records  keyed by Y-m-d
     * @param  Collection<int, LeaveRequest>  $leaves
     * @return array<string, mixed>
     */
    private function cell(
        CarbonImmutable $day,
        CarbonImmutable $today,
        ResolvedShift $shift,
        Collection $records,
        Collection $leaves,
        ?Holiday $holiday,
    ): array {
        $date = $day->format('Y-m-d');
        $isFuture = $day->gt($today);

        $record = $records->get($date);

        if ($record) {
            return [
                'date' => $date,
                'status' => $record->status,
                'worked_minutes' => (int) $record->worked_minutes,
                'late_minutes' => (int) $record->late_minutes,
                'overtime_minutes' => (int) $record->overtime_minutes,
                'undertime_minutes' => (int) $record->undertime_minutes,
                'regular_minutes' => (int) $record->regular_minutes,
                'approved_overtime_minutes' => (int) $record->approved_overtime_minutes,
                'night_minutes' => (int) $record->night_minutes,
                'rest_day_minutes' => (int) $record->rest_day_minutes,
                'holiday_minutes' => (int) $record->holiday_minutes,
                'flags' => array_values($record->flags ?? []),
                'first_in_at' => $record->first_in_at?->toIso8601String(),
                'last_out_at' => $record->last_out_at?->toIso8601String(),
                // The holiday the day was judged with, which is not necessarily
                // the calendar's today (ADR 0036).
                'holiday' => $record->rules['holiday_name'] ?? null,
                'hashid' => $record->hashid,
                'is_future' => false,
                'shift' => $shift->label(),
                'shift_source' => $shift->source,
            ];
        }

        $status = $isFuture
            ? null
            : AttendanceCalculator::noPunchStatus(
                DayRules::fromShift($shift, $holiday),
                $this->onLeave($leaves, $day),
            );

        return [
            'date' => $date,
            'status' => $status,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'overtime_minutes' => 0,
            'undertime_minutes' => 0,
            'regular_minutes' => 0,
            'approved_overtime_minutes' => 0,
            'night_minutes' => 0,
            'rest_day_minutes' => 0,
            'holiday_minutes' => 0,
            'flags' => [],
            'first_in_at' => null,
            'last_out_at' => null,
            'holiday' => $holiday?->name,
            'hashid' => null,
            'is_future' => $isFuture,
            'shift' => $shift->label(),
            'shift_source' => $shift->source,
        ];
    }

    /**
     * Whether any of the employee's approved leaves covers the given day.
     *
     * @param  Collection<int, LeaveRequest>  $leaves
     */
    private function onLeave(Collection $leaves, CarbonInterface $day): bool
    {
        return $leaves->contains(
            fn (LeaveRequest $leave): bool => $day->betweenIncluded($leave->start_date, $leave->end_date),
        );
    }
}
