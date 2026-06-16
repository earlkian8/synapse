<?php

namespace App\Queries;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Attendance\AttendanceCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceRecordsIndexQuery
{
    /**
     * Statuses the daily board can be filtered by (plus `all`).
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'present', 'late', 'undertime', 'absent', 'on_leave', 'day_off', 'incomplete'];

    /**
     * The day's roster after applying the request's status filter.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function get(Request $request): Collection
    {
        $status = $this->status($request);

        $rows = $this->roster(
            $this->date($request),
            department: $request->integer('department') ?: null,
            search: $request->string('search')->toString(),
        );

        if ($status !== 'all') {
            $rows = $rows->where('status', $status)->values();
        }

        return $rows;
    }

    /**
     * Build the full daily roster: every (matching) employee paired with their
     * record for the date, synthesising a transient record (day_off / on_leave /
     * absent) for those who have none, so the board always shows the whole team.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function roster(string $date, ?int $department = null, string $search = ''): Collection
    {
        $employees = Employee::query()
            ->with(['department:id,name', 'position:id,title', 'workSchedule'])
            ->when($department, fn (Builder $q) => $q->where('department_id', $department))
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $records = AttendanceRecord::query()
            ->with('punches')
            ->forDate($date)
            ->get()
            ->keyBy('employee_id');

        $onLeave = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->flip();

        return $employees
            ->map(function (Employee $employee) use ($records, $date, $onLeave): AttendanceRecord {
                $record = $records->get($employee->id) ?? $this->synthesize($employee, $date, $onLeave->has($employee->id));
                $record->setRelation('employee', $employee);

                return $record;
            })
            ->values();
    }

    /**
     * A transient (unsaved) record for an employee with no punches on the date.
     */
    private function synthesize(Employee $employee, string $date, bool $onLeave): AttendanceRecord
    {
        $day = Carbon::parse($date);
        $schedule = $employee->workSchedule;

        $status = match (true) {
            $onLeave => 'on_leave',
            ! AttendanceCalculator::isWorkingDay($day, $schedule) => 'day_off',
            default => 'absent',
        };

        $record = new AttendanceRecord([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'work_schedule_id' => $schedule?->id,
            'scheduled_start' => $schedule?->start_time,
            'scheduled_end' => $schedule?->end_time,
            'status' => $status,
        ]);

        $record->setRelation('punches', new Collection);

        return $record;
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'all';
    }

    public function date(Request $request): string
    {
        $raw = $request->string('date')->toString();

        try {
            return $raw !== '' ? Carbon::parse($raw)->toDateString() : Carbon::today()->toDateString();
        } catch (\Throwable) {
            return Carbon::today()->toDateString();
        }
    }
}
