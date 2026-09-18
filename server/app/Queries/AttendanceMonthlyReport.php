<?php

namespace App\Queries;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * The monthly report: one summary row per employee for the month containing the
 * anchor date — present days, late count, half days, absences, the minute buckets
 * a payroll reads (ADR 0038), attendance rate, and a per-day worked-minutes trend
 * for the inline sparkline. Built on the shared {@see AttendanceRangeQuery}.
 *
 * {@see summarize()} is also the period export's roll-up, for any range.
 */
class AttendanceMonthlyReport
{
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
            ->map(fn (array $row): array => self::summarize($row['employee'], $row['cells']))
            ->values();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $start->format('F Y'),
            'rows' => $rows->all(),
        ];
    }

    /**
     * Roll a range of day-cells into one employee's summary.
     *
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    public static function summarize(Employee $employee, array $cells): array
    {
        $present = 0;
        $late = 0;
        $halfDays = 0;
        $absent = 0;
        $holidays = 0;
        $scheduled = 0;
        $minutes = array_fill_keys([
            'worked', 'late', 'undertime', 'regular', 'overtime', 'approved_overtime', 'night', 'rest_day', 'holiday',
        ], 0);
        $trend = [];

        foreach ($cells as $cell) {
            if ($cell['is_future']) {
                continue;
            }

            $status = $cell['status'];
            $isPresent = in_array($status, AttendanceRecord::PRESENT_STATUSES, true);

            if ($isPresent) {
                $present++;
                $scheduled++;
            } elseif ($status === 'absent') {
                $absent++;
                $scheduled++;
            } elseif ($status === 'holiday') {
                // Nobody was expected: a holiday is neither attendance nor absence,
                // so it stays out of the rate.
                $holidays++;
            }

            if ($status === 'late') {
                $late++;
            }

            if ($status === 'half_day') {
                $halfDays++;
            }

            foreach (array_keys($minutes) as $bucket) {
                $minutes[$bucket] += (int) ($cell[$bucket.'_minutes'] ?? 0);
            }

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
            'holiday_count' => $holidays,
            'half_day_count' => $halfDays,
            'overtime_hours' => round($minutes['overtime'] / 60, 1),
            'worked_hours' => round($minutes['worked'] / 60, 1),
            // Every bucket in minutes, for the period export and the totals.
            'minutes' => $minutes,
            'attendance_rate' => $scheduled > 0 ? (int) round($present / $scheduled * 100) : null,
            'trend' => $trend,
        ];
    }
}
