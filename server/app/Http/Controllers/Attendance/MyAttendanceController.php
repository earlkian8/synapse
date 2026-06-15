<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\PunchRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Employee self-service: the personal Clock In/Out surface and DTR history. Backed
 * by the same {@see AttendanceClock} the mobile API uses, so a web punch and a
 * phone punch are indistinguishable downstream (only the `source` differs).
 */
class MyAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * My clock card, today's punches and recent DTR history.
     */
    public function index(Request $request): Response
    {
        $employee = $this->employee($request);
        $today = Carbon::today()->toDateString();

        $record = $this->clock->displayRecord($employee, $today);

        $history = AttendanceRecord::query()
            ->with('punches')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', Carbon::today()->subDays(30)->toDateString())
            ->orderByDesc('work_date')
            ->get();

        return Inertia::render('attendance/me', [
            'employee' => [
                'full_name' => $employee->full_name,
                'initials' => $employee->initials(),
                'photo' => $employee->photo_url,
                'employee_no' => $employee->employee_no,
                'schedule' => $employee->workSchedule ? [
                    'name' => $employee->workSchedule->name,
                    'start_time' => $employee->workSchedule->start_time ? substr((string) $employee->workSchedule->start_time, 0, 5) : null,
                    'end_time' => $employee->workSchedule->end_time ? substr((string) $employee->workSchedule->end_time, 0, 5) : null,
                ] : null,
            ],
            'today' => (new AttendanceRecordResource($record))->resolve($request),
            'nextExpected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
            'history' => AttendanceRecordResource::collection($history)->resolve($request),
            'summary' => $this->monthSummary($employee->id),
            'can' => ['clock' => $request->user()->can('attendance.clock')],
        ]);
    }

    /**
     * Record one of my own punches (web self-service, source = web).
     */
    public function punch(PunchRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);

        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', 'public')
            : null;

        try {
            $record = $this->clock->punch($employee, $request->string('type')->toString(), [
                'source' => 'web',
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'accuracy' => $request->input('accuracy'),
                'photo' => $photo,
                'note' => $request->input('note'),
            ]);
        } catch (AttendancePunchException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        ActivityLogger::log(
            event: 'updated',
            description: $this->punchLabel($request->string('type')->toString()).' (self-service)',
            subject: $record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return $this->respond($this->punchLabel($request->string('type')->toString()).'.');
    }

    /**
     * Resolve the signed-in user's Employee record, or 403 if unlinked.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->with('workSchedule')->first();

        if (! $employee) {
            throw new HttpException(403, 'Your account is not linked to an employee record.');
        }

        return $employee;
    }

    /**
     * This month's headline figures for the employee.
     *
     * @return array<string, int|float>
     */
    private function monthSummary(int $employeeId): array
    {
        $records = AttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereYear('work_date', now()->year)
            ->whereMonth('work_date', now()->month)
            ->get(['status', 'worked_minutes', 'late_minutes', 'overtime_minutes']);

        return [
            'worked_hours' => round($records->sum('worked_minutes') / 60, 1),
            'overtime_hours' => round($records->sum('overtime_minutes') / 60, 1),
            'late_count' => $records->where('late_minutes', '>', 0)->count(),
            'absent_count' => $records->where('status', 'absent')->count(),
        ];
    }

    private function punchLabel(string $type): string
    {
        return match ($type) {
            'clock_in' => 'Clocked in',
            'clock_out' => 'Clocked out',
            'break_start' => 'Started break',
            'break_end' => 'Ended break',
            default => 'Punched',
        };
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
