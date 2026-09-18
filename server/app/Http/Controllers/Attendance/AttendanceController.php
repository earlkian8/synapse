<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ReapplyScheduleRequest;
use App\Http\Requests\Attendance\StoreAttendanceRecordRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\AttendanceStatistics;
use App\Queries\AttendanceWeeklyQuery;
use App\Queries\ShiftRosterQuery;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The HR-facing attendance board — a day's Daily Time Records for the whole team,
 * with manual entry, corrections and approvals. Self-service clocking lives in
 * {@see MyAttendanceController}; the mobile API in App\Http\Controllers\Api.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * The attendance workspace: a daily log, a weekly grid, a monthly report and
     * the shift roster over the same team. Only the active tab's dataset is built
     * — the other closures stay cheap on the (default) daily tab and are fetched
     * on demand via Inertia partial reloads when the tab changes.
     */
    public function index(
        Request $request,
        AttendanceRecordsIndexQuery $query,
        AttendanceStatistics $statistics,
        AttendanceWeeklyQuery $weekly,
        AttendanceMonthlyReport $monthly,
        ShiftRosterQuery $roster,
    ): Response {
        $date = $query->date($request);
        $tab = $this->tab($request);
        $department = $request->integer('department') ?: null;
        $search = $request->string('search')->toString();

        return Inertia::render('attendance/index', [
            'records' => fn () => $tab === 'today'
                ? AttendanceRecordResource::collection($query->get($request))->resolve($request)
                : [],
            'week' => fn () => $tab === 'weekly'
                ? $weekly->toArray($date, $department, $search)
                : null,
            'report' => fn () => $tab === 'monthly'
                ? $monthly->toArray($date, $department, $search)
                : null,
            // The plan rather than the record: what each person is due to work
            // this week, and why (ADR 0037).
            'roster' => fn () => $tab === 'roster' && $request->user()->can('attendance.roster.view')
                ? $roster->toArray($date, $department, $search)
                : null,
            'stats' => $statistics->toArray($date),
            'options' => $this->indexOptions(),
            'can' => $this->permissions($request),
            'filters' => [
                'date' => $date,
                'tab' => $tab,
                'search' => $search,
                'status' => $query->status($request),
                'department' => $department,
            ],
        ]);
    }

    /**
     * The active workspace tab; defaults to the daily log.
     */
    private function tab(Request $request): string
    {
        $tab = $request->string('tab')->toString();

        return in_array($tab, ['today', 'weekly', 'monthly', 'roster'], true) ? $tab : 'today';
    }

    /**
     * One record with its full punch timeline — the day-detail drawer fetch.
     */
    public function show(AttendanceRecord $attendanceRecord): AttendanceRecordResource
    {
        $attendanceRecord->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'punches.recorder:id,first_name,middle_name,last_name,suffix',
            'approver:id,first_name,middle_name,last_name,suffix',
        ]);

        return new AttendanceRecordResource($attendanceRecord);
    }

    /**
     * HR manual entry of a record for an employee/date (times recomputed server-side).
     */
    public function store(StoreAttendanceRecordRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $record = $this->clock->openRecord($employee, $request->date('work_date')->toDateString());

        $this->clock->applyManualPunches($record, $request->only(['time_in', 'break_start', 'break_end', 'time_out']), $request->user()->id);

        if ($request->filled('remarks')) {
            $record->update(['remarks' => $request->string('remarks')->toString()]);
        }

        ActivityLogger::log(
            event: 'created',
            description: "Recorded attendance for {$employee->full_name} on {$record->work_date->format('M j')}",
            subject: $record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Attendance recorded.');
    }

    /**
     * Correct an existing record's punch times and/or remarks.
     */
    public function update(UpdateAttendanceRecordRequest $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $this->clock->applyManualPunches($attendanceRecord, $request->only(['time_in', 'break_start', 'break_end', 'time_out']), $request->user()->id);

        $attendanceRecord->update(['remarks' => $request->string('remarks')->toString() ?: null]);
        $attendanceRecord->load('employee:id,first_name,middle_name,last_name,suffix');

        ActivityLogger::log(
            event: 'updated',
            description: "Corrected attendance for {$attendanceRecord->employee?->full_name} on {$attendanceRecord->work_date->format('M j')}",
            subject: $attendanceRecord,
            logName: 'attendance',
            subjectLabel: $attendanceRecord->employee?->full_name ?? 'employee',
        );

        return $this->respond('Attendance updated.');
    }

    /**
     * Approve a flagged record (a correction or overtime awaiting sign-off).
     */
    public function approve(Request $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $attendanceRecord->update([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        $attendanceRecord->load('employee:id,first_name,middle_name,last_name,suffix');

        ActivityLogger::log(
            event: 'updated',
            description: "Approved attendance for {$attendanceRecord->employee?->full_name} on {$attendanceRecord->work_date->format('M j')}",
            subject: $attendanceRecord,
            logName: 'attendance',
            subjectLabel: $attendanceRecord->employee?->full_name ?? 'employee',
        );

        return $this->respond('Attendance approved.');
    }

    /**
     * Re-judge one day by the employee's current schedule, attendance policy and
     * the holiday calendar. A day keeps the rules it opened with (ADR 0036, 0038);
     * this is the explicit way they change, so it is always logged — with the rules
     * that now apply.
     */
    public function reapply(AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $changed = $this->clock->reapplySchedule($attendanceRecord);
        $name = $attendanceRecord->employee?->full_name ?? 'employee';

        ActivityLogger::log(
            event: 'updated',
            description: "Re-applied the current schedule and policy to {$name}'s attendance on {$attendanceRecord->work_date->format('M j')}",
            subject: $attendanceRecord,
            properties: ['changed' => $changed, 'rules' => $attendanceRecord->rules],
            logName: 'attendance',
            subjectLabel: $name,
        );

        return $this->respond(
            $changed ? 'Day re-judged by the current schedule and policy.' : 'This day already matches the current schedule and policy.',
            $changed ? 'success' : 'info',
        );
    }

    /**
     * Re-apply current schedules and policies to every recorded day in a period
     * (optionally one department's) — after a schedule or policy was corrected, or
     * a holiday added late. Walked in date order, because weekly overtime and a
     * monthly grace allowance read the days before each one.
     */
    public function reapplyRange(ReapplyScheduleRequest $request): RedirectResponse
    {
        $from = $request->date('from')->toDateString();
        $to = $request->date('to')->toDateString();
        $department = $request->integer('department') ?: null;

        $holidays = HolidayCalendar::inRange(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
        $total = 0;
        $changed = 0;

        AttendanceRecord::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($department, fn (Builder $query) => $query->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('department_id', $department),
            ))
            ->with('employee')
            ->orderBy('work_date')
            ->orderBy('id')
            ->chunk(200, function ($records) use ($holidays, &$total, &$changed): void {
                $total += $records->count();
                $changed += $this->clock->reapplyMany($records, $holidays);
            });

        $period = CarbonImmutable::parse($from)->format('M j').($from === $to ? '' : ' – '.CarbonImmutable::parse($to)->format('M j'));

        if ($total > 0) {
            ActivityLogger::log(
                event: 'updated',
                description: "Re-applied current schedules and policies to {$total} attendance ".str('record')->plural($total)." ({$period})",
                properties: ['from' => $from, 'to' => $to, 'department' => $department, 'records' => $total, 'changed' => $changed],
                logName: 'attendance',
                subjectLabel: 'Attendance',
            );
        }

        return $this->respond(
            $total > 0
                ? "Re-applied to {$total} ".str('day')->plural($total)." — {$changed} changed."
                : 'No recorded days in that period.',
            $total > 0 ? 'success' : 'info',
        );
    }

    /**
     * Approve every record still awaiting sign-off — a one-click way to clear the
     * correction/overtime queue instead of approving them one at a time.
     */
    public function approveAll(Request $request): RedirectResponse
    {
        $count = AttendanceRecord::where('approval_status', 'pending')->update([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if ($count > 0) {
            ActivityLogger::log(
                event: 'updated',
                description: "Bulk-approved {$count} pending attendance record".($count === 1 ? '' : 's'),
                logName: 'attendance',
                subjectLabel: 'Attendance',
            );
        }

        return $this->respond(
            $count > 0
                ? "Approved {$count} pending record".($count === 1 ? '' : 's').'.'
                : 'No pending records to approve.',
            $count > 0 ? 'success' : 'info',
        );
    }

    /**
     * Delete a record (and its punches via cascade).
     */
    public function destroy(AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $attendanceRecord->load('employee:id,first_name,middle_name,last_name,suffix');
        $name = $attendanceRecord->employee?->full_name ?? 'employee';
        $date = $attendanceRecord->work_date->format('M j');
        $attendanceRecord->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted attendance for {$name} on {$date}",
            logName: 'attendance',
            subjectLabel: $name,
        );

        return $this->respond('Attendance record deleted.');
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => $user->can('attendance.manage'),
            'clock' => $user->can('attendance.clock'),
            'viewRoster' => $user->can('attendance.roster.view'),
            'manageRoster' => $user->can('attendance.roster.manage'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function indexOptions(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            // The templates the roster's override and assign dialogs choose from.
            'schedules' => WorkSchedule::query()
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'cycle_length_days'])
                ->map(fn (WorkSchedule $schedule): array => [
                    'id' => $schedule->id,
                    'name' => $schedule->name,
                    'type' => $schedule->type,
                    'cycle_length_days' => (int) $schedule->cycle_length_days,
                ]),
            // What an assignment can single somebody out to be judged by (ADR 0038).
            'policies' => AttendancePolicy::query()->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()
                ->orderBy('first_name')
                ->limit(500)
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
                ->map(fn (Employee $e): array => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_no' => $e->employee_no,
                ]),
        ];
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
