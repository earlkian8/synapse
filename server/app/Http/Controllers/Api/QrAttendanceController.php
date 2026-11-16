<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\QrPunchRequest;
use App\Http\Requests\Attendance\QrScanRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Employee;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use App\Support\Attendance\QrScanException;
use App\Support\Attendance\QrScans;
use App\Support\Files\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clocking in by scanning a QR station's code with the app (ADR 0103): the scan,
 * which proves the person was at the screen and buys a ticket, then the punch,
 * which spends it. Both through {@see QrScans}; the punch through
 * {@see AttendanceClock}, as every punch is.
 *
 * A refusal is answered 422 with the reason, so the app can say what to do.
 * Nothing here is queued offline: a code is only good while the screen shows it.
 */
class QrAttendanceController extends Controller
{
    public function __construct(
        private readonly QrScans $scans,
        private readonly AttendanceClock $clock,
    ) {}

    public function scan(QrScanRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        try {
            $result = $this->scans->scan(
                $employee,
                $request->string('code')->toString(),
                $request->only(['latitude', 'longitude', 'accuracy', 'mocked']),
                $request->input('device_id'),
                $request->ip(),
            );
        } catch (QrScanException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function punch(QrPunchRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', Uploads::DISK)
            : null;

        try {
            $captured = $this->scans->punch(
                $employee,
                $request->string('ticket')->toString(),
                $request->string('type')->toString(),
                $photo,
            );
        } catch (QrScanException $e) {
            $this->discard($photo);

            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (AttendancePunchException $e) {
            $this->discard($photo);

            return response()->json(['message' => $e->getMessage(), 'reason' => 'punch_refused'], 422);
        }

        $record = $captured->record->load('punches');

        return response()->json([
            'data' => (new AttendanceRecordResource($record))->resolve($request),
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
            'duplicate' => false,
        ]);
    }

    /** A refused punch keeps no photo. */
    private function discard(?string $photo): void
    {
        if ($photo !== null) {
            Uploads::disk()->delete($photo);
        }
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
