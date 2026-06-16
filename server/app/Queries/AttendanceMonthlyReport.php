<?php

namespace App\Queries;

use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * The monthly report: one summary row per employee for the month containing the
 * anchor date — present days, late count, absences, overtime, attendance rate,
 * and a per-day worked-minutes trend for the inline sparkline. Built on the
 * shared {@see AttendanceRangeQuery}.
 */
class AttendanceMonthlyReport
{
    /** Statuses that count as the employee having shown up for work that day. */
    private const PRESENT_STATUSES = ['present', 'late', 'undertime', 'incomplete'];

    public function __construct(private readonly AttendanceRangeQuery $range) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $anchor, ?int $department = null, string $search = ''): array
    {
        $start = CarbonImmutable::parse($anchor)->startOfMonth();
        $end = $start->endOfMonth();

        $rows = $this->range
            ->days($start->toDateString(), $end->toDateString(), $department, $search)
            ->map(fn (array $row): array => $this->summarize($row['employee'], $row['cells']))
            ->values();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $start->format('F Y'),
            'rows' => $rows->all(),
        ];
    }

    /**
     * Roll a month of day-cells into one employee's summary.
     *
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    private function summarize(Employee $employee, array $cells): array
    {
        $present = 0;
        $late = 0;
        $absent = 0;
        $scheduled = 0;
        $overtimeMinutes = 0;
        $workedMinutes = 0;
        $trend = [];

        foreach ($cells as $cell) {
            if ($cell['is_future']) {
                continue;
            }

            $status = $cell['status'];
            $isPresent = in_array($status, self::PRESENT_STATUSES, true);

            if ($isPresent) {
                $present++;
                $scheduled++;
            } elseif ($status === 'absent') {
                $absent++;
                $scheduled++;
            }

            if ($status === 'late') {
                $late++;
            }

            $overtimeMinutes += (int) $cell['overtime_minutes'];
            $workedMinutes += (int) $cell['worked_minutes'];

            // The sparkline shows the worked-hours rhythm across the whole month
            // (zeros on rest / absence days read as the natural weekly cadence).
            $trend[] = (int) $cell['worked_minutes'];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials(),
                'employee_no' => $employee->employee_no,
                'photo' => $employee->photo_url,
                'department' => $employee->relationLoaded('department') && $employee->department
                    ? ['id' => $employee->department->id, 'name' => $employee->department->name]
                    : null,
            ],
            'present_days' => $present,
            'late_count' => $late,
            'absent_count' => $absent,
            'overtime_hours' => round($overtimeMinutes / 60, 1),
            'worked_hours' => round($workedMinutes / 60, 1),
            'attendance_rate' => $scheduled > 0 ? (int) round($present / $scheduled * 100) : null,
            'trend' => $trend,
        ];
    }
}
