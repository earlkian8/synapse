<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ReapplyScheduleRequest;
use App\Http\Requests\Attendance\StoreAttendanceRecordRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendancePeriodResource;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceRequestResource;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\AttendanceRequestsIndexQuery;
use App\Queries\AttendanceStatistics;
use App\Queries\AttendanceWeeklyQuery;
use App\Queries\ShiftRosterQuery;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceException;
use App\Support\Attendance\PeriodLocker;
use App\Support\HolidayCalendar;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The HR-facing attendance board — a day's Daily Time Records for the whole team,
 * with manual entry, corrections and sign-off; the request inbox and the periods
 * (ADR 0039) are two more of its tabs. Self-service clocking lives in
 * {@see MyAttendanceController}; the mobile API in App\Http\Controllers\Api.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * The attendance workspace: a daily log, a weekly grid, a monthly report, the
     * shift roster, the request inbox and the periods. Only the active tab's dataset is built
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
        AttendanceRequestsIndexQuery $requests,
        PeriodLocker $locker,
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
            // What people asked for, and what attendance closes on (ADR 0039).
            'requests' => fn () => $tab === 'requests' && $request->user()->can('attendance.requests.review')
                ? AttendanceRequestResource::collection($requests->get($request))->resolve($request)
                : null,
            'periods' => fn () => $tab === 'periods' && $request->user()->can('attendance.period.manage')
                ? $this->periods($request, $locker)
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
                'request_status' => $requests->status($request),
                'request_type' => $requests->type($request),
            ],
        ]);
    }

    /**
     * The periods, newest first, each open one with its lock checklist, and the
     * calendar they are generated on.
     *
     * @return array<string, mixed>
     */
    private function periods(Request $request, PeriodLocker $locker): array
    {
        $organization = app(Tenancy::class)->organization();

        $periods = AttendancePeriod::query()
            ->with(['locker:id,first_name,middle_name,last_name,suffix', 'unlocker:id,first_name,middle_name,last_name,suffix'])
            ->orderByDesc('start_date')
            ->limit(24)
            ->get()
            ->each(function (AttendancePeriod $period) use ($locker): void {
                if (! $period->isLocked()) {
                    $period->checklist = $locker->checklist($period);
                }
            });

        return [
            'items' => AttendancePeriodResource::collection($periods)->resolve($request),
            'settings' => [
                'frequency' => $organization?->attendance_period_frequency ?? 'semi_monthly',
                'reminder_days' => (int) ($organization?->attendance_lock_reminder_days ?? 2),
            ],
        ];
    }

    /**
     * The active workspace tab; defaults to the daily log.
     */
    private function tab(Request $request): string
    {
        $tab = $request->string('tab')->toString();

        return in_array($tab, ['today', 'weekly', 'monthly', 'roster', 'requests', 'periods'], true) ? $tab : 'today';
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
            'replacedPunches',
            'approver:id,first_name,middle_name,last_name,suffix',
        ]);

        // The requests that concern the day (ADR 0039).
        $attendanceRecord->setRelation('dayRequests', $attendanceRecord->relatedRequests()->get());

        return new AttendanceRecordResource($attendanceRecord);
    }

    /**
     * HR manual entry of a record for an employee/date (times recomputed server-side).
     */
    public function store(StoreAttendanceRecordRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        try {
            $record = $this->clock->openRecord($employee, $request->date('work_date')->toDateString());
            $this->clock->applyManualPunches($record, $request->only(['time_in', 'break_start', 'break_end', 'time_out']), $request->user()->id);
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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
        try {
            $this->clock->applyManualPunches($attendanceRecord, $request->only(['time_in', 'break_start', 'break_end', 'time_out']), $request->user()->id);
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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
     * Sign a day off (ADR 0039): what was awaiting review on it — overtime that
     * needs approval — is approved as it stands.
     */
    public function approve(Request $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        try {
            $this->clock->signOff($attendanceRecord, $request->user());
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        $attendanceRecord->load('employee:id,first_name,middle_name,last_name,suffix');

        ActivityLogger::log(
            event: 'updated',
            description: "Signed off attendance for {$attendanceRecord->employee?->full_name} on {$attendanceRecord->work_date->format('M j')}",
            subject: $attendanceRecord,
            properties: ['approved_overtime_minutes' => $attendanceRecord->approved_overtime_minutes],
            logName: 'attendance',
            subjectLabel: $attendanceRecord->employee?->full_name ?? 'employee',
        );

        return $this->respond('Day signed off.');
    }

    /**
     * Re-judge one day by the employee's current schedule, attendance policy and
     * the holiday calendar. A day keeps the rules it opened with (ADR 0036, 0038);
     * this is the explicit way they change, so it is always logged — with the rules
     * that now apply.
     */
    public function reapply(AttendanceRecord $attendanceRecord): RedirectResponse
    {
        try {
            $changed = $this->clock->reapplySchedule($attendanceRecord);
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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
        $locked = 0;

        AttendanceRecord::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($department, fn (Builder $query) => $query->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('department_id', $department),
            ))
            ->with('employee')
            ->orderBy('work_date')
            ->orderBy('id')
            ->chunk(200, function ($records) use ($holidays, &$total, &$changed, &$locked): void {
                $changed += $this->clock->reapplyMany($records, $holidays, $skipped);
                $total += $records->count() - $skipped;
                $locked += $skipped;
            });

        $period = CarbonImmutable::parse($from)->format('M j').($from === $to ? '' : ' – '.CarbonImmutable::parse($to)->format('M j'));

        if ($total > 0) {
            ActivityLogger::log(
                event: 'updated',
                description: "Re-applied current schedules and policies to {$total} attendance ".str('record')->plural($total)." ({$period})",
                properties: ['from' => $from, 'to' => $to, 'department' => $department, 'records' => $total, 'changed' => $changed, 'locked' => $locked],
                logName: 'attendance',
                subjectLabel: 'Attendance',
            );
        }

        $frozen = $locked > 0 ? " {$locked} ".str('day')->plural($locked).' in a locked period left as they are.' : '';

        return $this->respond(
            $total > 0
                ? "Re-applied to {$total} ".str('day')->plural($total)." — {$changed} changed.{$frozen}"
                : ($locked > 0 ? 'Every recorded day in that period is locked.' : 'No recorded days in that period.'),
            $total > 0 ? 'success' : 'info',
        );
    }

    /**
     * Sign off every day still awaiting it — a one-click way to clear the queue
     * instead of one day at a time. Only pending days are touched; each goes
     * through the engine, so a day in a locked period, or the signer's own, is
     * left and counted.
     */
    public function approveAll(Request $request): RedirectResponse
    {
        $count = 0;
        $skipped = 0;

        AttendanceRecord::query()
            ->where('approval_status', 'pending')
            ->with('employee:id,user_id')
            ->chunkById(200, function ($records) use ($request, &$count, &$skipped): void {
                foreach ($records as $record) {
                    try {
                        $this->clock->signOff($record, $request->user());
                        $count++;
                    } catch (AttendanceException) {
                        $skipped++;
                    }
                }
            });

        if ($count > 0) {
            ActivityLogger::log(
                event: 'updated',
                description: "Bulk-approved {$count} pending attendance record".($count === 1 ? '' : 's'),
                logName: 'attendance',
                subjectLabel: 'Attendance',
            );
        }

        $left = $skipped > 0 ? " {$skipped} left — your own, or in a locked period." : '';

        return $this->respond(
            $count > 0
                ? "Approved {$count} pending record".($count === 1 ? '' : 's').".{$left}"
                : ($skipped > 0 ? "Nothing approved.{$left}" : 'No pending records to approve.'),
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

        try {
            $this->clock->deleteRecord($attendanceRecord);
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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
            'request' => $user->can('attendance.request'),
            'reviewRequests' => $user->can('attendance.requests.review'),
            'managePeriods' => $user->can('attendance.period.manage'),
            'unlockPeriods' => $user->can('attendance.period.unlock'),
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
