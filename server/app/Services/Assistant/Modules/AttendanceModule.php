<?php

namespace App\Services\Assistant\Modules;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use Illuminate\Support\Carbon;

/**
 * Attendance capability: look up an employee's Daily Time Records and record a
 * clock punch on their behalf. Every punch goes through {@see AttendanceClock} —
 * the same engine the web and mobile API use — so totals and status stay correct.
 */
class AttendanceModule extends Module
{
    public function __construct(private readonly AttendanceClock $clock) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('attendance.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_attendance' => 'findAttendance',
            'record_punch' => 'recordPunch',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(): string
    {
        return <<<'TXT'
        ATTENDANCE — Daily Time Records (DTR): one record per employee per day, built from clock in/out and break punches. Worked hours, lateness, undertime and overtime are computed server-side against the employee's work schedule.
        - find_attendance lists an employee's recent records (pass `date` as YYYY-MM-DD for one specific day).
        - record_punch logs a clock punch for an employee: type is clock_in, clock_out, break_start or break_end. Punch order is validated (you can't clock out before clocking in).
        - Pass `employee` as a name or employee number.
        TXT;
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'find_attendance',
                'description' => "List an employee's daily time records, most recent first.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD to fetch a single day (optional).'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'record_punch',
                'description' => 'Record a clock punch (in/out or break) for an employee.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'type' => ['type' => 'STRING', 'enum' => ['clock_in', 'clock_out', 'break_start', 'break_end']],
                    ],
                    'required' => ['employee', 'type'],
                ],
            ],
        ];
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findAttendance(User $user, array $args): ToolResult
    {
        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $date = $this->date($args['date'] ?? null);

        $records = AttendanceRecord::query()
            ->with('employee')
            ->where('employee_id', $employee->id)
            ->when($date, fn ($q) => $q->whereDate('work_date', $date))
            ->orderByDesc('work_date')
            ->limit($date ? 1 : 7)
            ->get();

        $cards = $records->map(fn (AttendanceRecord $r): array => $this->recordCard($r, 'find', 'neutral'))->all();

        return ToolResult::found("Attendance for {$employee->full_name}", count($cards).' day'.(count($cards) === 1 ? '' : 's'), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function recordPunch(User $user, array $args): ToolResult
    {
        if ($user->cannot('attendance.manage')) {
            return $this->denied('record attendance punches');
        }

        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $type = strtolower(trim((string) ($args['type'] ?? '')));

        try {
            $record = $this->clock->punch($employee, $type, ['source' => 'manual', 'recorded_by' => $user->id]);
        } catch (AttendancePunchException $e) {
            return ToolResult::error('Recorded the punch', $e->getMessage());
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Recorded {$type} for {$employee->full_name} via assistant",
            subject: $record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        $record->setRelation('employee', $employee);

        return ToolResult::ok(
            $this->label($type)." for {$employee->full_name}",
            $record->status,
            $this->recordCard($record, 'add', 'positive'),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateEmployee(array $args): ?Employee
    {
        $needle = $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']);

        return $needle ? $this->matchByTokens(Employee::query(), $needle)->first() : null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function label(string $type): string
    {
        return match ($type) {
            'clock_in' => 'Clocked in',
            'clock_out' => 'Clocked out',
            'break_start' => 'Started break',
            'break_end' => 'Ended break',
            default => 'Punched',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function recordCard(AttendanceRecord $record, string $kind, string $tone): array
    {
        $employee = $record->employee;
        $in = $record->first_in_at?->format('g:i A') ?? '—';
        $out = $record->last_out_at?->format('g:i A') ?? '—';
        $hours = round($record->worked_minutes / 60, 1);

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: ucfirst(str_replace('_', ' ', $record->status)),
            title: $employee?->full_name ?? 'Employee',
            subtitle: $record->work_date->format('D, M j')." · {$in} – {$out}",
            meta: ["{$hours} h", $record->late_minutes > 0 ? "{$record->late_minutes}m late" : '', $record->overtime_minutes > 0 ? "{$record->overtime_minutes}m OT" : ''],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $record->id,
        );
    }
}
