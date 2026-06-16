<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * The canonical punch engine. Every clock action — web self-service, the mobile
 * API and the assistant — goes through {@see punch()} so the transition rules,
 * schedule snapshot and recomputation live in exactly one place.
 */
class AttendanceClock
{
    /**
     * Record a punch for an employee and return the (recomputed, saved) day record.
     *
     * @param  array{source?: string, latitude?: float|string|null, longitude?: float|string|null, accuracy?: float|string|null, photo?: string|null, note?: string|null, recorded_by?: int|null, punched_at?: \DateTimeInterface|string|null}  $context
     *
     * @throws AttendancePunchException when the punch is invalid for the day's state
     */
    public function punch(Employee $employee, string $type, array $context = []): AttendanceRecord
    {
        if (! in_array($type, AttendancePunch::TYPES, true)) {
            throw new AttendancePunchException('That punch type is not recognised.');
        }

        $at = isset($context['punched_at']) ? Carbon::parse($context['punched_at']) : Carbon::now();
        $record = $this->openRecord($employee, $at->toDateString());

        $this->assertAllowed($record, $type);

        $record->punches()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'punched_at' => $at,
            'source' => $context['source'] ?? 'web',
            'latitude' => $context['latitude'] ?? null,
            'longitude' => $context['longitude'] ?? null,
            'accuracy' => $context['accuracy'] ?? null,
            'photo' => $context['photo'] ?? null,
            'note' => $context['note'] ?? null,
            'recorded_by' => $context['recorded_by'] ?? null,
        ]);

        $this->refresh($record, $employee);

        return $record;
    }

    /**
     * Find (or create) the employee's record for the given date, snapshotting the
     * schedule times the first time the day is touched.
     */
    public function openRecord(Employee $employee, string $date): AttendanceRecord
    {
        $schedule = $employee->workSchedule;

        return AttendanceRecord::firstOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $date],
            [
                'work_schedule_id' => $schedule?->id,
                'scheduled_start' => $schedule?->start_time,
                'scheduled_end' => $schedule?->end_time,
                'status' => 'absent',
            ],
        );
    }

    /**
     * The employee's record for a date for **display** — the existing row (with its
     * punches), or a transient unsaved one with the status computed, so merely
     * viewing the clock never writes an empty row. Persist only on an actual punch.
     */
    public function displayRecord(Employee $employee, string $date): AttendanceRecord
    {
        $record = AttendanceRecord::query()
            ->with('punches')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        if ($record) {
            return $record;
        }

        $schedule = $employee->workSchedule;
        $record = new AttendanceRecord([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'work_schedule_id' => $schedule?->id,
            'scheduled_start' => $schedule?->start_time,
            'scheduled_end' => $schedule?->end_time,
            'status' => 'absent',
        ]);
        $record->setRelation('punches', new EloquentCollection);

        AttendanceCalculator::recompute(
            $record,
            $schedule,
            $this->isOnApprovedLeave($employee->id, $date),
        );

        return $record;
    }

    /**
     * Replace a record's punches with HR-entered manual times, mark it manual and
     * recompute. Absent fields are simply omitted (e.g. a no-show keeps no punches).
     *
     * @param  array{time_in?: ?string, break_start?: ?string, break_end?: ?string, time_out?: ?string}  $times  Clock-face "HH:MM" values.
     */
    public function applyManualPunches(AttendanceRecord $record, array $times, int $recordedBy): void
    {
        $record->punches()->delete();

        $date = $record->work_date->toDateString();
        $map = ['time_in' => 'clock_in', 'break_start' => 'break_start', 'break_end' => 'break_end', 'time_out' => 'clock_out'];

        foreach ($map as $field => $type) {
            $time = trim((string) ($times[$field] ?? ''));

            if ($time === '') {
                continue;
            }

            $record->punches()->create([
                'employee_id' => $record->employee_id,
                'type' => $type,
                'punched_at' => Carbon::parse($date)->setTimeFromTimeString($time),
                'source' => 'manual',
                'recorded_by' => $recordedBy,
            ]);
        }

        $record->is_manual = true;
        $this->refresh($record);
    }

    /**
     * Reload the punches and recompute every derived field, then persist.
     */
    public function refresh(AttendanceRecord $record, ?Employee $employee = null): void
    {
        $employee ??= $record->employee;
        $record->load('punches');

        $onLeave = $this->isOnApprovedLeave($record->employee_id, $record->work_date->toDateString());

        AttendanceCalculator::recompute($record, $employee?->workSchedule, $onLeave);
        $record->save();
    }

    /**
     * The punch the UI should offer next given the day's state, or null when the
     * day is complete (clocked out).
     */
    public function nextExpected(AttendanceRecord $record): ?string
    {
        $state = $this->state($record);

        if (! $state['onClock']) {
            return $record->last_out_at ? null : 'clock_in';
        }

        return $state['onBreak'] ? 'break_end' : 'clock_out';
    }

    /**
     * Every punch valid for the day's current state.
     *
     * @return list<string>
     */
    public function allowed(AttendanceRecord $record): array
    {
        $state = $this->state($record);

        if (! $state['onClock']) {
            return ['clock_in'];
        }

        return $state['onBreak'] ? ['break_end', 'clock_out'] : ['break_start', 'clock_out'];
    }

    /**
     * @throws AttendancePunchException
     */
    private function assertAllowed(AttendanceRecord $record, string $type): void
    {
        if (in_array($type, $this->allowed($record), true)) {
            return;
        }

        $state = $this->state($record);

        throw new AttendancePunchException(match (true) {
            $type === 'clock_in' && $state['onClock'] => "You're already clocked in.",
            $type === 'clock_out' && ! $state['onClock'] => 'You need to clock in first.',
            $type === 'break_start' && ! $state['onClock'] => 'Clock in before starting a break.',
            $type === 'break_start' && $state['onBreak'] => "You're already on a break.",
            $type === 'break_end' && ! $state['onBreak'] => "You're not on a break.",
            default => 'That punch is not allowed right now.',
        });
    }

    /**
     * Replay the day's punches to the current on-clock / on-break state.
     *
     * @return array{onClock: bool, onBreak: bool}
     */
    private function state(AttendanceRecord $record): array
    {
        $onClock = false;
        $onBreak = false;

        // A transient (unsaved) record carries its punches as a loaded relation;
        // a persisted one is queried fresh so the state reflects the database.
        $punches = $record->exists
            ? $record->punches()->orderBy('punched_at')->orderBy('id')->get()
            : $record->punches->sortBy(['punched_at', 'id']);

        foreach ($punches as $punch) {
            match ($punch->type) {
                'clock_in' => $onClock = true,
                'clock_out' => [$onClock, $onBreak] = [false, false],
                'break_start' => $onBreak = true,
                'break_end' => $onBreak = false,
                default => null,
            };
        }

        return ['onClock' => $onClock, 'onBreak' => $onBreak];
    }

    private function isOnApprovedLeave(int $employeeId, string $date): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }
}
