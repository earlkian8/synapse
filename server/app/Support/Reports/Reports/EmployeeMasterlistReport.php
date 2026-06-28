<?php

namespace App\Support\Reports\Reports;

use App\Models\Employee;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The employee masterlist: the authoritative roster snapshot, filterable by
 * department, status and employment type. The system of record for "who is on the
 * books right now".
 */
class EmployeeMasterlistReport implements Report
{
    use BuildsReport;

    public const STATUS_LABELS = [
        'active' => 'Active',
        'on_leave' => 'On leave',
        'suspended' => 'Suspended',
        'resigned' => 'Resigned',
        'terminated' => 'Terminated',
    ];

    public const TYPE_LABELS = [
        'regular' => 'Regular',
        'probationary' => 'Probationary',
        'contractual' => 'Contractual',
        'part_time' => 'Part-time',
    ];

    public function key(): string
    {
        return 'employee-masterlist';
    }

    public function name(): string
    {
        return 'Employee Masterlist';
    }

    public function description(): string
    {
        return 'The full roster of employees with department, position and employment standing.';
    }

    public function group(): string
    {
        return 'Workforce';
    }

    public function permission(): string
    {
        return 'employees.view';
    }

    public function filters(): array
    {
        return [
            $this->departmentFilter(),
            $this->selectFilter('status', 'Status', self::STATUS_LABELS, 'All statuses'),
            $this->selectFilter('type', 'Type', self::TYPE_LABELS, 'All types'),
            $this->searchFilter('Name, employee no, email…'),
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee_no', 'label' => 'Employee No', 'align' => 'left', 'type' => 'text'],
            ['key' => 'full_name', 'label' => 'Name', 'align' => 'left', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'type' => 'text'],
            ['key' => 'position', 'label' => 'Position', 'align' => 'left', 'type' => 'text'],
            ['key' => 'employment_type', 'label' => 'Type', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'employment_status', 'label' => 'Status', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'date_hired', 'label' => 'Date Hired', 'align' => 'left', 'type' => 'date'],
        ];
    }

    public function rows(array $params): Collection
    {
        $query = Employee::query()->with(['department:id,name', 'position:id,title']);

        if ($params['department'] !== 'all') {
            $query->where('department_id', (int) $params['department']);
        }

        if ($params['status'] !== 'all') {
            $query->where('employment_status', $params['status']);
        }

        if ($params['type'] !== 'all') {
            $query->where('employment_type', $params['type']);
        }

        if ($params['search'] !== '') {
            $query->search($params['search']);
        }

        return $query
            ->orderBy('employee_no')
            ->get()
            ->map(fn (Employee $employee): array => [
                'employee_no' => $employee->employee_no ?? '—',
                'full_name' => $employee->full_name,
                'department' => $employee->department?->name ?? '—',
                'position' => $employee->position?->title ?? '—',
                'employment_type' => self::TYPE_LABELS[$employee->employment_type] ?? $employee->employment_type,
                'employment_status' => self::STATUS_LABELS[$employee->employment_status] ?? $employee->employment_status,
                'date_hired' => $employee->date_hired?->toDateString() ?? '',
            ])
            ->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        $active = $rows->where('employment_status', self::STATUS_LABELS['active'])->count();

        return [
            ['label' => 'Employees', 'value' => number_format($rows->count())],
            ['label' => 'Active', 'value' => number_format($active)],
        ];
    }
}
