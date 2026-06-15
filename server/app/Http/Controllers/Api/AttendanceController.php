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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The mobile DTR surface: the same {@see AttendanceClock} the web self-service
 * uses, exposed over a token-authenticated API. Punches default to `source =
 * mobile` and carry GPS coordinates + an optional selfie.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * Today's record plus the next expected and allowed punches (drives the app's
     * primary button).
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $record = $this->clock->displayRecord($employee, Carbon::today()->toDateString());

        return response()->json([
            'data' => (new AttendanceRecordResource($record))->resolve($request),
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
        ]);
    }

    /**
     * Record a punch (clock in/out or break) from the mobile app.
     */
    public function punch(PunchRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', 'public')
            : null;

        try {
            $record = $this->clock->punch($employee, $request->string('type')->toString(), [
                'source' => 'mobile',
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'accuracy' => $request->input('accuracy'),
                'photo' => $photo,
                'note' => $request->input('note'),
            ]);
        } catch (AttendancePunchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record->load('punches');

        ActivityLogger::log(
            event: 'updated',
            description: 'Mobile punch ('.$request->string('type')->toString().')',
            subject: $record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return response()->json([
            'data' => (new AttendanceRecordResource($record))->resolve($request),
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
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
     * Resolve the token user's Employee, or 403 if the account is unlinked.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->with('workSchedule')->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
