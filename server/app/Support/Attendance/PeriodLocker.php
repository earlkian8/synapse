<?php

namespace App\Support\Attendance;

use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Closes and reopens attendance periods (ADR 0039).
 *
 * **Before locking**, a checklist: requests still waiting in the period, days
 * still missing a clock-out, days still waiting for sign-off. Each is something
 * that would change the numbers once decided. HR can lock with the checklist
 * open, but only by saying why — the reason is kept on the period and in the log.
 *
 * **Locking** writes the period summary ({@see PeriodSummaryExport}) and keeps it
 * as the period's file, on the private `local` disk, so what payroll received on
 * the day the period closed can always be retrieved — even after an unlock and a
 * correction. From then on {@see PeriodLock} refuses every change to its days.
 *
 * **Unlocking** is its own permission, always needs a reason, and is always
 * logged. The file from the lock is kept; locking again writes a new one.
 */
class PeriodLocker
{
    public function __construct(
        private readonly PeriodSummaryExport $summary,
        private readonly PeriodLock $lock,
    ) {}

    /**
     * What is still open in a period — each a number, and zero when clear.
     *
     * @return array{pending_requests: int, incomplete_days: int, pending_sign_offs: int, clear: bool}
     */
    public function checklist(AttendancePeriod $period): array
    {
        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();

        $counts = [
            'pending_requests' => AttendanceRequest::query()->where('status', 'pending')->overlapping($from, $to)->count(),
            'incomplete_days' => AttendanceRecord::query()->whereBetween('work_date', [$from, $to])->where('status', 'incomplete')->count(),
            'pending_sign_offs' => AttendanceRecord::query()->whereBetween('work_date', [$from, $to])->where('approval_status', 'pending')->count(),
        ];

        return $counts + ['clear' => array_sum($counts) === 0];
    }

    /**
     * Lock a period. With the checklist open, a reason is required.
     *
     * @throws AttendanceException
     */
    public function lock(AttendancePeriod $period, User $by, ?string $reason = null): AttendancePeriod
    {
        if ($period->isLocked()) {
            throw new AttendanceException('This period is already locked.');
        }

        $checklist = $this->checklist($period);
        $reason = filled($reason) ? trim((string) $reason) : null;

        if (! $checklist['clear'] && $reason === null) {
            throw new AttendanceException('This period still has open items. Resolve them, or say why it should be locked anyway.');
        }

        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();
        $path = sprintf('attendance-periods/%d/%s_%s_%s.csv', $period->organization_id, $from, $to, now()->format('YmdHis'));

        DB::transaction(function () use ($period, $by, $reason, $from, $to, $path): void {
            // Written inside the transaction that locks, so the file is exactly
            // what the locked days said.
            Storage::disk('local')->put($path, $this->summary->contents($from, $to));

            $period->update([
                'status' => 'locked',
                'locked_by' => $by->id,
                'locked_at' => now(),
                'lock_note' => $reason,
                'export_path' => $path,
            ]);
        });

        $this->lock->flush();

        ActivityLogger::log(
            event: 'updated',
            description: "Locked the attendance period {$period->label()}".($reason !== null ? ' with open items' : ''),
            subject: $period,
            properties: ['checklist' => $checklist, 'reason' => $reason, 'export' => $path],
            logName: 'attendance',
            subjectLabel: 'Attendance period',
        );

        return $period;
    }

    /**
     * Reopen a locked period. Always with a reason, always logged.
     *
     * @throws AttendanceException
     */
    public function unlock(AttendancePeriod $period, User $by, string $reason): AttendancePeriod
    {
        if (! $period->isLocked()) {
            throw new AttendanceException('This period is not locked.');
        }

        $period->update([
            'status' => 'open',
            'unlocked_by' => $by->id,
            'unlocked_at' => now(),
            'unlock_reason' => trim($reason),
        ]);

        $this->lock->flush();

        ActivityLogger::log(
            event: 'updated',
            description: "Unlocked the attendance period {$period->label()}",
            subject: $period,
            properties: ['reason' => trim($reason)],
            logName: 'attendance',
            subjectLabel: 'Attendance period',
        );

        return $period;
    }
}
