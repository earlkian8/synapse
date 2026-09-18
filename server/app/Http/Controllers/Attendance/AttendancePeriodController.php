<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendancePeriodSettingsRequest;
use App\Http\Requests\Attendance\LockAttendancePeriodRequest;
use App\Models\AttendancePeriod;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceException;
use App\Support\Attendance\PeriodCalendar;
use App\Support\Attendance\PeriodLocker;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance periods (ADR 0039): the calendar attendance closes on, generating
 * the periods on it, locking one (with its checklist) and unlocking it, and the
 * file a locked period kept. The list itself is a tab of the attendance board
 * ({@see AttendanceController::index()}).
 */
class AttendancePeriodController extends Controller
{
    public function __construct(
        private readonly PeriodCalendar $calendar,
        private readonly PeriodLocker $locker,
    ) {}

    /**
     * Make sure the current period and the next one exist.
     */
    public function generate(): RedirectResponse
    {
        $created = $this->calendar->ensureCurrent();

        if ($created > 0) {
            ActivityLogger::log(
                event: 'created',
                description: "Generated {$created} attendance ".str('period')->plural($created),
                logName: 'attendance',
                subjectLabel: 'Attendance periods',
            );
        }

        return $this->respond(
            $created > 0 ? "Added {$created} ".str('period')->plural($created).'.' : 'The current and next periods already exist.',
            $created > 0 ? 'success' : 'info',
        );
    }

    /**
     * Change the period calendar. Existing periods keep their dates; the next one
     * generated carries the company onto the new calendar.
     */
    public function settings(AttendancePeriodSettingsRequest $request): RedirectResponse
    {
        $organization = app(Tenancy::class)->organization();

        $organization->update([
            'attendance_period_frequency' => $request->string('frequency')->toString(),
            'attendance_lock_reminder_days' => $request->integer('reminder_days'),
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: 'Changed the attendance period calendar to '.str_replace('_', '-', $organization->attendance_period_frequency),
            properties: ['frequency' => $organization->attendance_period_frequency, 'reminder_days' => $organization->attendance_lock_reminder_days],
            logName: 'attendance',
            subjectLabel: 'Attendance periods',
        );

        return $this->respond('Period settings saved.');
    }

    public function lock(LockAttendancePeriodRequest $request, AttendancePeriod $attendancePeriod): RedirectResponse
    {
        try {
            $this->locker->lock($attendancePeriod, $request->user(), $request->input('reason'));
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond("Locked {$attendancePeriod->label()}. Its payroll summary is saved with it.");
    }

    public function unlock(LockAttendancePeriodRequest $request, AttendancePeriod $attendancePeriod): RedirectResponse
    {
        try {
            $this->locker->unlock($attendancePeriod, $request->user(), $request->string('reason')->toString());
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond("Unlocked {$attendancePeriod->label()}. Its days can change again.");
    }

    /**
     * The summary written when the period locked — what payroll received.
     */
    public function export(AttendancePeriod $attendancePeriod): StreamedResponse
    {
        abort_if($attendancePeriod->export_path === null || ! Storage::disk('local')->exists($attendancePeriod->export_path), 404);

        $name = sprintf('attendance-period-%s-to-%s.csv', $attendancePeriod->start_date->toDateString(), $attendancePeriod->end_date->toDateString());

        return Storage::disk('local')->download($attendancePeriod->export_path, $name, ['Content-Type' => 'text/csv']);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
