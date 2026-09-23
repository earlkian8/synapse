<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\PunchRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use App\Support\OrganizationClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile DTR surface: the same {@see AttendanceClock} the web self-service
 * uses, exposed over a token-authenticated API. Punches default to `source =
 * mobile` and carry GPS coordinates + an optional selfie.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * The current day's record plus the next expected and allowed punches (drives
     * the app's primary button). "Current" is the shift the employee is on — a
     * night shift after midnight included — or the day a clock-in would open.
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $record = $this->clock->currentRecord($employee);

        return response()->json([
            'data' => (new AttendanceRecordResource($record))->resolve($request),
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
        ]);
    }

    /**
     * Record a punch (clock in/out or break) from the mobile app.
     *
     * A punch the app queued while offline (ADR 0040) carries the time the phone
     * gave it and is judged at that time — within the policy's window, and
     * flagged when the phone's clock was off. Every punch carries the app's own
     * id for it, so a resend after a lost response returns the punch already
     * recorded instead of a second one.
     */
    public function punch(PunchRequest $request): JsonResponse
    {
        $employee = $this->employee($request);
        $offline = $request->filled('punched_at');

        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', 'public')
            : null;

        try {
            $captured = $this->clock->capture($employee, $request->string('type')->toString(), [
                'source' => 'mobile',
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'accuracy' => $request->input('accuracy'),
                'photo' => $photo,
                'note' => $request->input('note'),
                'external_id' => $request->input('client_id'),
                'punched_at' => $offline ? $request->date('punched_at') : null,
                'offline' => $offline,
                'sent_at' => $request->input('sent_at'),
            ]);
        } catch (AttendancePunchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record = $captured->record->load('punches');

        if (! $captured->duplicate) {
            ActivityLogger::log(
                event: 'updated',
                description: 'Mobile punch ('.$request->string('type')->toString().')'.($offline ? ', sent after being offline' : ''),
                subject: $record,
                logName: 'attendance',
                subjectLabel: $employee->full_name,
            );
        }

        return response()->json([
            'data' => (new AttendanceRecordResource($record))->resolve($request),
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
            'duplicate' => $captured->duplicate,
        ]);
    }

    /**
     * My DTR history, optionally bounded by from/to dates (paginated).
     */
    public function records(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $records = AttendanceRecord::query()
            ->with('punches')
            ->where('employee_id', $employee->id)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('work_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('work_date', '<=', $request->date('to')))
            ->orderByDesc('work_date')
            ->paginate(min($request->integer('per_page', 31), 100));

        return AttendanceRecordResource::collection($records)->response();
    }

    /**
     * Aggregate metrics over a date range (defaults to the current month): status
     * counts and summed minutes, for the Attendance tab's summary card.
     */
    public function summary(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $from = $request->filled('from') ? $request->date('from') : OrganizationClock::now()->startOfMonth();
        $to = $request->filled('to') ? $request->date('to') : OrganizationClock::now()->endOfMonth();

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get([
                'status', 'worked_minutes', 'break_minutes',
                'late_minutes', 'undertime_minutes', 'overtime_minutes',
                'regular_minutes', 'approved_overtime_minutes', 'night_minutes',
                'rest_day_minutes', 'holiday_minutes',
            ]);

        $statuses = array_fill_keys(AttendanceRecord::STATUSES, 0);

        foreach ($records as $record) {
            if (array_key_exists($record->status, $statuses)) {
                $statuses[$record->status]++;
            }
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days_recorded' => $records->count(),
            'status_counts' => $statuses,
            'worked_minutes' => (int) $records->sum('worked_minutes'),
            'break_minutes' => (int) $records->sum('break_minutes'),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'undertime_minutes' => (int) $records->sum('undertime_minutes'),
            'overtime_minutes' => (int) $records->sum('overtime_minutes'),
            // The buckets a payroll reads (ADR 0038).
            'regular_minutes' => (int) $records->sum('regular_minutes'),
            'approved_overtime_minutes' => (int) $records->sum('approved_overtime_minutes'),
            'night_minutes' => (int) $records->sum('night_minutes'),
            'rest_day_minutes' => (int) $records->sum('rest_day_minutes'),
            'holiday_minutes' => (int) $records->sum('holiday_minutes'),
        ]);
    }

    /**
     * Resolve the token user's Employee, or 403 if the account is unlinked.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
