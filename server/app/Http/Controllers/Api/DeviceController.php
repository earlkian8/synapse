<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Requests\Devices\DevicePunchesRequest;
use App\Http\Requests\Devices\KioskPunchRequest;
use App\Models\AttendanceDevice;
use App\Models\Employee;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceException;
use App\Support\Attendance\DayCloser;
use App\Support\Attendance\DevicePunchIngestor;
use App\Support\Employees\EmployeeNumbers;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The device-facing API (ADR 0040), authenticated by a device key rather than a
 * user ({@see AuthenticateDevice}):
 *
 *  - **`POST /api/devices/punches`** — a biometric scanner (or a hardware kiosk)
 *    pushes a batch. Recorded, never refused for being out of order; each row
 *    gets its outcome.
 *  - **The web kiosk** — a shared tablet at the door. Somebody types their
 *    employee number, sees their name and the punches their day allows, and the
 *    kiosk takes their photo with the punch. This is a person punching, so the
 *    punch engine holds them to the day's order and policy exactly as it does on
 *    the web or the phone; only the source (`kiosk`) and the place (the
 *    device's location) differ.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * What the device is: the kiosk's header, and a scanner's connection check.
     */
    public function show(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $organization = app(Tenancy::class)->organization();

        return response()->json([
            'data' => [
                'name' => $device->name,
                'type' => $device->type,
                'location' => $device->location?->name,
                'organization' => $organization?->name,
                'timezone' => OrganizationClock::timezone(),
            ],
        ]);
    }

    /**
     * Take a batch of punches from a device.
     */
    public function punches(DevicePunchesRequest $request, DevicePunchIngestor $ingestor): JsonResponse
    {
        $report = $ingestor->ingest(
            $this->device($request),
            $request->validated('punches'),
            $request->validated('sent_at'),
        );

        return response()->json($report);
    }

    /**
     * Who a number belongs to, and what they can punch now — so the kiosk can
     * greet them by name and offer only the punches their day allows.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['employee_ref' => ['required', 'string', 'max:64']]);

        $employee = $this->employee((string) $request->input('employee_ref'));

        if ($employee === null) {
            return response()->json(['message' => 'That number isn’t recognised. Check it and try again, or ask HR.'], 404);
        }

        $record = $this->clock->currentRecord($employee);

        return response()->json([
            'data' => [
                'first_name' => $employee->first_name,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials(),
                'next_expected' => $this->clock->nextExpected($record),
                'allowed' => $this->clock->allowed($record),
                'first_in_at' => $record->first_in_at?->toIso8601String(),
                'last_out_at' => $record->last_out_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Record a punch at the kiosk.
     */
    public function punch(KioskPunchRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $employee = $this->employee($request->string('employee_ref')->toString());

        if ($employee === null) {
            return response()->json(['message' => 'That number isn’t recognised. Check it and try again, or ask HR.'], 404);
        }

        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', 'public')
            : null;

        try {
            $record = $this->clock->punch($employee, $request->string('type')->toString(), [
                'source' => 'kiosk',
                'attendance_device_id' => $device->id,
                'work_location_id' => $device->work_location_id,
                'photo' => $photo,
            ]);
        } catch (AttendanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLogger::log(
            event: 'updated',
            description: 'Kiosk punch ('.$request->string('type')->toString().") at {$device->name}",
            subject: $record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return response()->json([
            'data' => [
                'type' => $request->string('type')->toString(),
                'at' => now()->toIso8601String(),
                'status' => $record->status,
            ],
        ]);
    }

    private function device(Request $request): AttendanceDevice
    {
        return $request->attributes->get('attendance_device');
    }

    /**
     * An employee the company still expects at work, by the reference a device
     * knows them by. At the kiosk the digits alone will do — "42" finds
     * EMP-00042 — because nobody should have to type the prefix at the door;
     * only when no number matches exactly.
     */
    private function employee(string $reference): ?Employee
    {
        $find = fn (string $ref): ?Employee => Employee::query()
            ->whereDeviceReference($ref)
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->first();

        $reference = trim($reference);

        return $find($reference)
            ?? (ctype_digit($reference) && $reference !== '' ? $find(EmployeeNumbers::format((int) $reference)) : null);
    }
}
