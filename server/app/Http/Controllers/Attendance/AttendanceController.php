<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceRecordRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\AttendanceStatistics;
use App\Queries\AttendanceWeeklyQuery;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
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
     * The attendance workspace: a daily log, a weekly grid and a monthly report
     * over the same roster. Only the active tab's dataset is built — the weekly
     * and monthly closures stay cheap on the (default) daily tab and are fetched
     * on demand via Inertia partial reloads when the tab changes.
     */
    public function index(
        Request $request,
        AttendanceRecordsIndexQuery $query,
        AttendanceStatistics $statistics,
        AttendanceWeeklyQuery $weekly,
        AttendanceMonthlyReport $monthly,
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

        return in_array($tab, ['today', 'weekly', 'monthly'], true) ? $tab : 'today';
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function indexOptions(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
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
