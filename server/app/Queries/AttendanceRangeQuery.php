<?php

namespace App\Queries;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Attendance\AttendanceCalculator;
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
 * synthesized cell (day_off / on_leave / absent). Days after today carry a null
 * status (nothing has happened yet) so the UI can leave them blank.
 */
class AttendanceRangeQuery
{
    /**
     * Eligible employees paired with their ordered day-cells for [start, end].
     *
     * @return Collection<int, array{employee: Employee, cells: list<array<string, mixed>>}>
     */
    public function days(string $start, string $end, ?int $department = null, string $search = ''): Collection
    {
        $startDate = CarbonImmutable::parse($start)->startOfDay();
        $endDate = CarbonImmutable::parse($end)->startOfDay();
        $today = CarbonImmutable::today();

        $employees = Employee::query()
            ->with(['department:id,name', 'workSchedule'])
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

        return $employees
            ->map(function (Employee $employee) use ($records, $leaves, $startDate, $endDate, $today): array {
                $own = $records->get($employee->id) ?? collect();
                $ownLeaves = $leaves->get($employee->id) ?? collect();

                $cells = [];

                for ($day = $startDate; $day->lte($endDate); $day = $day->addDay()) {
                    $cells[] = $this->cell($day, $today, $employee, $own, $ownLeaves);
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
        Employee $employee,
        Collection $records,
        Collection $leaves,
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
                'first_in_at' => $record->first_in_at?->toIso8601String(),
                'last_out_at' => $record->last_out_at?->toIso8601String(),
                'hashid' => $record->hashid,
                'is_future' => false,
            ];
        }

        $status = match (true) {
            $isFuture => null,
            $this->onLeave($leaves, $day) => 'on_leave',
            ! AttendanceCalculator::isWorkingDay($day, $employee->workSchedule) => 'day_off',
            default => 'absent',
        };

        return [
            'date' => $date,
            'status' => $status,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'overtime_minutes' => 0,
            'undertime_minutes' => 0,
            'first_in_at' => null,
            'last_out_at' => null,
            'hashid' => null,
            'is_future' => $isFuture,
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
