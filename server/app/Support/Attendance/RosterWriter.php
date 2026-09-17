<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\ShiftRosterEntry;

/**
 * Writes the one-off shift overrides the roster board and the assistant both set
 * (ADR 0037): "on this date, this person works this instead".
 *
 * One entry per employee per date, so setting an override twice corrects it
 * rather than stacking a second. Clearing one gives the day back to the
 * precedence chain — the assignment, the department default, and so on.
 */
class RosterWriter
{
    /**
     * Create or correct an employee's override for a date.
     *
     * @param  array{work_schedule_id?: int|null, segments?: list<array{start: string, end: string}>|null, required_minutes?: int|null, is_rest_day?: bool, reason?: string|null}  $attributes
     */
    public function set(Employee $employee, string $date, array $attributes, ?int $createdBy = null): ShiftRosterEntry
    {
        $isRestDay = (bool) ($attributes['is_rest_day'] ?? false);
        $segments = $isRestDay ? null : $this->segments($attributes['segments'] ?? null);

        return ShiftRosterEntry::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $date],
            [
                'work_schedule_id' => $isRestDay ? null : ($attributes['work_schedule_id'] ?? null),
                'segments' => $segments,
                'required_minutes' => $isRestDay ? null : ($attributes['required_minutes'] ?? null),
                'is_rest_day' => $isRestDay,
                'reason' => $this->reason($attributes['reason'] ?? null),
                'created_by' => $createdBy,
            ],
        );
    }

    /**
     * Drop an override, handing the day back to the precedence chain.
     */
    public function clear(ShiftRosterEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * Well-formed "HH:MM" segment pairs, or null when none were given (the entry
     * then borrows a template's pattern for that date).
     *
     * @param  mixed  $segments
     * @return list<array{start: string, end: string}>|null
     */
    private function segments($segments): ?array
    {
        if (! is_array($segments)) {
            return null;
        }

        $out = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $start = trim((string) ($segment['start'] ?? ''));
            $end = trim((string) ($segment['end'] ?? ''));

            if ($start !== '' && $end !== '') {
                $out[] = ['start' => substr($start, 0, 5), 'end' => substr($end, 0, 5)];
            }
        }

        return $out === [] ? null : $out;
    }

    private function reason(mixed $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
