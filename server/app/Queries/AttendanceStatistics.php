<?php

namespace App\Queries;

use App\Models\AttendanceRecord;

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
        $present = ($counts['present'] ?? 0) + ($counts['late'] ?? 0) + ($counts['undertime'] ?? 0) + ($counts['incomplete'] ?? 0);

        $workedMinutes = $rows->sum('worked_minutes');
        $workedRows = $rows->where('worked_minutes', '>', 0)->count();

        return [
            'present' => $present,
            'late' => $counts['late'] ?? 0,
            'absent' => $counts['absent'] ?? 0,
            'on_leave' => $counts['on_leave'] ?? 0,
            'avg_hours' => $workedRows > 0 ? round($workedMinutes / $workedRows / 60, 1) : 0.0,
            'pending' => AttendanceRecord::where('approval_status', 'pending')->count(),
        ];
    }
}
