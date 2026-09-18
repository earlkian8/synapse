<?php

namespace App\Support\Attendance;

use App\Http\Controllers\Attendance\AttendanceExportController;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRangeQuery;

/**
 * The payroll period summary (ADR 0038): one row per employee for a period,
 * every figure in whole minutes so nothing is lost to rounding on the way into a
 * payroll system — minutes, never money (ADR 0019).
 *
 * Written in two places, from this one class: the board's download
 * ({@see AttendanceExportController}, `tab=period`), and the file a period keeps
 * when it locks ({@see PeriodLocker}, ADR 0039), so what payroll received and
 * what the board would download for the same dates are the same columns.
 */
class PeriodSummaryExport
{
    /**
     * The columns, in order. Documented in docs/modules/attendance-policies.md —
     * change one and the doc changes too.
     */
    public const COLUMNS = [
        'Employee', 'Employee No.', 'Department', 'Period Start', 'Period End',
        'Days Worked', 'Absences', 'Half Days', 'Late Days', 'Holidays',
        'Worked (min)', 'Late (min)', 'Undertime (min)', 'Regular (min)', 'Overtime (min)',
        'Approved Overtime (min)', 'Night (min)', 'Rest Day (min)', 'Holiday (min)',
    ];

    public function __construct(private readonly AttendanceRangeQuery $range) {}

    /**
     * Write the summary for [from, to] to an open stream.
     *
     * @param  resource  $handle
     */
    public function write($handle, string $from, string $to, ?int $department = null, string $search = ''): void
    {
        fputcsv($handle, self::COLUMNS);

        foreach ($this->range->days($from, $to, $department, $search) as $row) {
            $summary = AttendanceMonthlyReport::summarize($row['employee'], $row['cells']);
            $minutes = $summary['minutes'];

            fputcsv($handle, [
                $summary['employee']['full_name'],
                $summary['employee']['employee_no'],
                $summary['employee']['department']['name'] ?? null,
                $from,
                $to,
                $summary['present_days'],
                $summary['absent_count'],
                $summary['half_day_count'],
                $summary['late_count'],
                $summary['holiday_count'],
                $minutes['worked'],
                $minutes['late'],
                $minutes['undertime'],
                $minutes['regular'],
                $minutes['overtime'],
                $minutes['approved_overtime'],
                $minutes['night'],
                $minutes['rest_day'],
                $minutes['holiday'],
            ]);
        }
    }

    /**
     * The whole company's summary for [from, to], as a string — the file a
     * locked period keeps.
     */
    public function contents(string $from, string $to): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->write($handle, $from, $to);
        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }
}
