<?php

namespace App\Queries;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;

class AttendanceStatistics
{
    public function __construct(private readonly AttendanceRecordsIndexQuery $roster) {}

    /**
     * Headline metrics for the daily attendance board (for the given date).
     *
     * @return array<string, int|float>
     */
    public function toArray(string $date): array
    {
        $rows = $this->roster->roster($date);

        $counts = $rows->countBy('status');
        $present = collect(AttendanceRecord::PRESENT_STATUSES)->sum(fn (string $status): int => $counts[$status] ?? 0);

        $workedMinutes = $rows->sum('worked_minutes');
        $workedRows = $rows->where('worked_minutes', '>', 0)->count();

        return [
            'present' => $present,
            'late' => $counts['late'] ?? 0,
            'absent' => $counts['absent'] ?? 0,
            'on_leave' => $counts['on_leave'] ?? 0,
            'avg_hours' => $workedRows > 0 ? round($workedMinutes / $workedRows / 60, 1) : 0.0,
            // Days waiting for sign-off, and requests waiting for a decision
            // (ADR 0039) — both real queues now.
            'pending' => AttendanceRecord::where('approval_status', 'pending')->count(),
            'pending_requests' => AttendanceRequest::where('status', 'pending')->count(),
        ];
    }
}
