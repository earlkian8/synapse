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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class AttendanceRecordsIndexQuery
{
    public function __construct(private readonly ShiftResolver $shifts = new ShiftResolver) {}

    /**
     * Statuses the daily board can be filtered by (plus `all`).
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'present', 'late', 'undertime', 'absent', 'on_leave', 'holiday', 'day_off', 'incomplete'];

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
     * record for the date, synthesising a transient record (on_leave / holiday /
     * day_off / absent) for those who have none, so the board always shows the
     * whole team.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function roster(string $date, ?int $department = null, string $search = ''): Collection
    {
        $employees = Employee::query()
            ->with(['department:id,name', 'position:id,title'])
            ->when($department, fn (Builder $q) => $q->where('department_id', $department))
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        // Every shift for the whole board in a fixed number of queries (ADR 0037),
        // so a row without a record still knows what the person was due to work.
        $shifts = $this->shifts->forMany($employees, $date, $date);

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

        // Once for the day, not once per person.
        $holiday = HolidayCalendar::on($date);

        return $employees
            ->map(function (Employee $employee) use ($records, $date, $onLeave, $holiday, $shifts): AttendanceRecord {
                $record = $records->get($employee->id) ?? $this->synthesize(
                    $employee,
                    $shifts[$employee->id][$date] ?? ResolvedShift::fallback($date),
                    $onLeave->has($employee->id),
                    $holiday,
                );
                $record->setRelation('employee', $employee);

                return $record;
            })
            ->values();
    }

    /**
     * A transient (unsaved) record for an employee with no punches on the date,
     * carrying the rules a real record would have frozen from the same resolved
     * shift, and the status those rules give a day without punches.
     */
    private function synthesize(Employee $employee, ResolvedShift $shift, bool $onLeave, ?Holiday $holiday): AttendanceRecord
    {
        $rules = DayRules::fromShift($shift, $holiday);

        $record = new AttendanceRecord([
            'employee_id' => $employee->id,
            'work_date' => $shift->date,
            'work_schedule_id' => $shift->scheduleId,
            'scheduled_start' => $shift->startTime(),
            'scheduled_end' => $shift->endTime(),
            'scheduled_start_at' => $shift->startsAt(),
            'scheduled_end_at' => $shift->endsAt(),
            'rules' => $rules->toArray(),
            'status' => AttendanceCalculator::noPunchStatus($rules, $onLeave),
        ]);

        $record->setRelation('punches', new Collection);

        return $record;
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'all';
    }

    /**
     * The board's date: the one asked for, or the organisation's today.
     */
    public function date(Request $request): string
    {
        $raw = $request->string('date')->toString();

        try {
            return $raw !== '' ? CarbonImmutable::parse($raw)->toDateString() : OrganizationClock::today();
        } catch (\Throwable) {
            return OrganizationClock::today();
        }
    }
}
