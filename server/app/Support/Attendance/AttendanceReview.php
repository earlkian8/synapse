<?php

namespace App\Support\Attendance;

use App\Http\Requests\Attendance\ReapplyScheduleRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reviewing recorded days (ADR 0059): signing a day off — which grants the
 * overtime it shows — one at a time or every pending day at once, and
 * re-judging a stretch of days by the schedules and policies in force now.
 *
 * The Attendance screen and the assistant both come through here; the judging
 * itself is {@see AttendanceClock}'s. Nobody signs off their own day — a sweep
 * leaves those and says how many. A range is at most
 * {@see ReapplyScheduleRequest::MAX_DAYS} days, the screen's own limit.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class AttendanceReview
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * Sign one day off.
     *
     * @throws AttendanceException
     */
    public function signOff(AttendanceRecord $record, User $by, string $channel = ''): void
    {
        $this->clock->signOff($record, $by);

        $record->load('employee:id,first_name,middle_name,last_name,suffix');

        ActivityLogger::log(
            event: 'updated',
            description: "Signed off attendance for {$record->employee?->full_name} on {$record->work_date->format('M j')}{$channel}",
            subject: $record,
            properties: ['approved_overtime_minutes' => $record->approved_overtime_minutes],
            logName: 'attendance',
            subjectLabel: $record->employee?->full_name ?? 'employee',
        );
    }

    /**
     * Sign off every pending day — or only one person's, or only a date range's.
     * Returns how many were signed off, and how many were left (the reviewer's
     * own).
     *
     * @return array{signed: int, skipped: int}
     */
    public function signOffPending(User $by, ?int $employeeId = null, ?string $from = null, ?string $to = null, string $channel = ''): array
    {
        $signed = 0;
        $skipped = 0;

        $this->pending($employeeId, $from, $to)
            ->with('employee:id,user_id')
            ->chunkById(200, function ($records) use ($by, &$signed, &$skipped): void {
                foreach ($records as $record) {
                    try {
                        $this->clock->signOff($record, $by);
                        $signed++;
                    } catch (AttendanceException) {
                        $skipped++;
                    }
                }
            });

        if ($signed > 0) {
            ActivityLogger::log(
                event: 'updated',
                description: "Bulk-approved {$signed} pending attendance record".($signed === 1 ? '' : 's').$channel,
                properties: array_filter(['employee_id' => $employeeId, 'from' => $from, 'to' => $to]),
                logName: 'attendance',
                subjectLabel: 'Attendance',
            );
        }

        return ['signed' => $signed, 'skipped' => $skipped];
    }

    /**
     * The days awaiting a sign-off.
     *
     * @return Builder<AttendanceRecord>
     */
    public function pending(?int $employeeId = null, ?string $from = null, ?string $to = null): Builder
    {
        return AttendanceRecord::query()
            ->where('approval_status', 'pending')
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->when($from !== null, fn (Builder $q) => $q->where('work_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('work_date', '<=', $to));
    }

    /**
     * Re-judge every recorded day in a range (optionally one department's) by
     * the schedules and policies in force now. Returns how many days were read
     * and how many changed.
     *
     * @return array{total: int, changed: int}
     */
    public function reapplyRange(string $from, string $to, ?int $departmentId = null, string $channel = ''): array
    {
        $holidays = HolidayCalendar::inRange(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
        $total = 0;
        $changed = 0;

        AttendanceRecord::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($departmentId !== null, fn (Builder $query) => $query->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('department_id', $departmentId),
            ))
            ->with('employee')
            ->orderBy('work_date')
            ->orderBy('id')
            ->chunk(200, function ($records) use ($holidays, &$total, &$changed): void {
                $changed += $this->clock->reapplyMany($records, $holidays);
                $total += $records->count();
            });

        if ($total > 0) {
            $period = CarbonImmutable::parse($from)->format('M j').($from === $to ? '' : ' – '.CarbonImmutable::parse($to)->format('M j'));

            ActivityLogger::log(
                event: 'updated',
                description: "Re-applied current schedules and policies to {$total} attendance ".str('record')->plural($total)." ({$period}){$channel}",
                properties: ['from' => $from, 'to' => $to, 'department' => $departmentId, 'records' => $total, 'changed' => $changed],
                logName: 'attendance',
                subjectLabel: 'Attendance',
            );
        }

        return ['total' => $total, 'changed' => $changed];
    }
}
