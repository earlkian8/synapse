<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\AttendanceWeeklyQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the attendance board as a CSV download, matching whichever tab the HR
 * user is viewing (daily log, weekly timesheet, or monthly summary) and honouring
 * the active date / department / search / status filters. Reuses the same queries
 * that build the on-screen views so the export always mirrors what's displayed.
 */
class AttendanceExportController extends Controller
{
    public function __invoke(
        Request $request,
        AttendanceRecordsIndexQuery $roster,
        AttendanceWeeklyQuery $weekly,
        AttendanceMonthlyReport $monthly,
    ): StreamedResponse {
        $date = $roster->date($request);
        $tab = $this->tab($request);
        $department = $request->integer('department') ?: null;
        $search = $request->string('search')->toString();

        $filename = "attendance-{$tab}-{$date}.csv";
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($tab, $date, $department, $search, $request, $roster, $weekly, $monthly): void {
            $handle = fopen('php://output', 'w');

            match ($tab) {
                'weekly' => $this->weekly($handle, $weekly->toArray($date, $department, $search)),
                'monthly' => $this->monthly($handle, $monthly->toArray($date, $department, $search)),
                default => $this->daily($handle, $roster->get($request)),
            };

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * The active tab, defaulting to the daily log (mirrors the index controller).
     */
    private function tab(Request $request): string
    {
        $tab = $request->string('tab')->toString();

        return in_array($tab, ['today', 'weekly', 'monthly'], true) ? $tab : 'today';
    }

    /**
     * The daily log: one row per employee on the date.
     *
     * @param  resource  $handle
     * @param  Collection<int, AttendanceRecord>  $records
     */
    private function daily($handle, $records): void
    {
        fputcsv($handle, [
            'Employee', 'Employee No.', 'Department', 'Status', 'Time In', 'Time Out',
            'Worked (h)', 'Late (min)', 'Undertime (min)', 'Overtime (min)', 'Approval', 'Remarks',
        ]);

        foreach ($records as $record) {
            /** @var AttendanceRecord $record */
            fputcsv($handle, [
                $record->employee?->full_name,
                $record->employee?->employee_no,
                $record->employee?->department?->name,
                $record->status,
                $this->time($record->first_in_at),
                $this->time($record->last_out_at),
                $this->hours($record->worked_minutes),
                $record->late_minutes,
                $record->undertime_minutes,
                $record->overtime_minutes,
                $record->approval_status,
                $record->remarks,
            ]);
        }
    }

    /**
     * The weekly timesheet: one row per employee with a worked-hours column per day.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $week
     */
    private function weekly($handle, array $week): void
    {
        $dayLabels = array_map(
            fn (array $day): string => Carbon::parse($day['date'])->format('D j'),
            $week['days'],
        );

        fputcsv($handle, ['Employee', 'Employee No.', 'Department', ...$dayLabels, 'Total (h)']);

        foreach ($week['rows'] as $row) {
            $daily = array_map(
                fn (array $cell): string => $this->hours((int) $cell['worked_minutes']),
                $row['cells'],
            );
            $total = array_sum(array_map(fn (array $cell): int => (int) $cell['worked_minutes'], $row['cells']));

            fputcsv($handle, [
                $row['employee']['full_name'],
                $row['employee']['employee_no'],
                $row['employee']['department']['name'] ?? null,
                ...$daily,
                $this->hours($total),
            ]);
        }
    }

    /**
     * The monthly summary: one roll-up row per employee.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $report
     */
    private function monthly($handle, array $report): void
    {
        fputcsv($handle, [
            'Employee', 'Employee No.', 'Department', 'Present Days', 'Late', 'Absent',
            'Worked (h)', 'Overtime (h)', 'Attendance %',
        ]);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, [
                $row['employee']['full_name'],
                $row['employee']['employee_no'],
                $row['employee']['department']['name'] ?? null,
                $row['present_days'],
                $row['late_count'],
                $row['absent_count'],
                $row['worked_hours'],
                $row['overtime_hours'],
                $row['attendance_rate'] === null ? '' : $row['attendance_rate'],
            ]);
        }
    }

    private function time(?Carbon $value): string
    {
        return $value?->format('H:i') ?? '';
    }

    private function hours(int $minutes): string
    {
        return $minutes > 0 ? (string) round($minutes / 60, 1) : '';
    }
}
