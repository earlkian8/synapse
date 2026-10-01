<?php

namespace App\Support\Reports\Reports;

use App\Queries\AttendanceMonthlyReport;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The monthly attendance summary: present days, lates, absences, worked and overtime
 * hours, and attendance rate — one row per employee. Built directly on the canonical
 * {@see AttendanceMonthlyReport}, so it reconciles exactly with the Attendance module's
 * own monthly report.
 */
class AttendanceSummaryReport implements Report
{
    use BuildsReport;

    public function __construct(private readonly AttendanceMonthlyReport $report) {}

    public function key(): string
    {
        return 'attendance-summary';
    }

    public function name(): string
    {
        return 'Attendance Summary';
    }

    public function description(): string
    {
        return 'Per-employee attendance for a month: present days, lates, absences, hours and rate.';
    }

    public function group(): string
    {
        return 'Attendance';
    }

    public function permission(): string
    {
        return 'attendance.view';
    }

    public function filters(): array
    {
        return [
            $this->monthFilter('Month'),
            $this->departmentFilter(),
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee_no', 'label' => 'Employee No', 'align' => 'left', 'type' => 'text'],
            ['key' => 'full_name', 'label' => 'Name', 'align' => 'left', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'type' => 'text'],
            ['key' => 'present_days', 'label' => 'Present', 'align' => 'right', 'type' => 'number'],
            ['key' => 'late_count', 'label' => 'Late', 'align' => 'right', 'type' => 'number'],
            ['key' => 'absent_count', 'label' => 'Absent', 'align' => 'right', 'type' => 'number'],
            ['key' => 'worked_hours', 'label' => 'Worked hrs', 'align' => 'right', 'type' => 'number'],
            ['key' => 'overtime_hours', 'label' => 'OT hrs', 'align' => 'right', 'type' => 'number'],
            ['key' => 'attendance_rate', 'label' => 'Rate', 'align' => 'right', 'type' => 'text'],
        ];
    }

    public function rows(array $params): Collection
    {
        $anchor = $params['month'].'-01';
        $department = $params['department'] === 'all' ? null : (int) $params['department'];

        $data = $this->report->toArray($anchor, $department, '');

        return collect($data['rows'])->map(fn (array $row): array => [
            'employee_no' => $row['employee']['employee_no'] ?? '—',
            'full_name' => $row['employee']['full_name'],
            'department' => $row['employee']['department']['name'] ?? '—',
            'present_days' => $row['present_days'],
            'late_count' => $row['late_count'],
            'absent_count' => $row['absent_count'],
            'worked_hours' => $row['worked_hours'],
            'overtime_hours' => $row['overtime_hours'],
            'attendance_rate' => $row['attendance_rate'] === null ? '—' : $row['attendance_rate'].'%',
        ])->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        $rated = $rows->where('attendance_rate', '!=', '—');
        $avgRate = $rated->isEmpty()
            ? '—'
            : round($rated->avg(fn (array $row): float => (float) rtrim($row['attendance_rate'], '%'))).'%';

        return [
            ['label' => 'Employees', 'value' => number_format($rows->count())],
            ['label' => 'Total present days', 'value' => number_format((int) $rows->sum('present_days'))],
            ['label' => 'Total absences', 'value' => number_format((int) $rows->sum('absent_count'))],
            ['label' => 'Avg. rate', 'value' => $avgRate],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->donut('Attendance mix', [
                'Present' => (int) $rows->sum('present_days'),
                'Late' => (int) $rows->sum('late_count'),
                'Absent' => (int) $rows->sum('absent_count'),
            ]),
        ];
    }
}
