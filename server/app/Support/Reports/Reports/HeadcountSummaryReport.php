<?php

namespace App\Support\Reports\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * Headcount by department, broken down by employment type — a one-page establishment
 * report. Counts every department (including those with no staff) plus an "Unassigned"
 * line, so the column totals reconcile exactly to the masterlist.
 */
class HeadcountSummaryReport implements Report
{
    use BuildsReport;

    public function key(): string
    {
        return 'headcount-summary';
    }

    public function name(): string
    {
        return 'Headcount Summary';
    }

    public function description(): string
    {
        return 'Active headcount per department, split by employment type, with an on-leave count.';
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
        return [];
    }

    public function columns(): array
    {
        return [
            ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'type' => 'text'],
            ['key' => 'headcount', 'label' => 'Headcount', 'align' => 'right', 'type' => 'number'],
            ['key' => 'regular', 'label' => 'Regular', 'align' => 'right', 'type' => 'number'],
            ['key' => 'probationary', 'label' => 'Probationary', 'align' => 'right', 'type' => 'number'],
            ['key' => 'contractual', 'label' => 'Contractual', 'align' => 'right', 'type' => 'number'],
            ['key' => 'part_time', 'label' => 'Part-time', 'align' => 'right', 'type' => 'number'],
            ['key' => 'on_leave', 'label' => 'On leave', 'align' => 'right', 'type' => 'number'],
        ];
    }

    public function rows(array $params): Collection
    {
        // One small read of the active workforce; aggregated in memory so every
        // department line (and the totals) comes from the same source rows.
        $employees = Employee::query()
            ->where('employment_status', '!=', 'resigned')
            ->where('employment_status', '!=', 'terminated')
            ->get(['id', 'department_id', 'employment_type', 'employment_status']);

        $byDepartment = $employees->groupBy('department_id');

        $rows = Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => $this->line(
                $department->name,
                $byDepartment->get($department->id, collect()),
            ));

        $unassigned = $byDepartment->get(null, collect());

        if ($unassigned->isNotEmpty()) {
            $rows->push($this->line('Unassigned', $unassigned));
        }

        return $rows->values();
    }

    /**
     * Roll a group of employees into one department line.
     *
     * @param  Collection<int, Employee>  $group
     * @return array<string, mixed>
     */
    private function line(string $name, Collection $group): array
    {
        return [
            'department' => $name,
            'headcount' => $group->count(),
            'regular' => $group->where('employment_type', 'regular')->count(),
            'probationary' => $group->where('employment_type', 'probationary')->count(),
            'contractual' => $group->where('employment_type', 'contractual')->count(),
            'part_time' => $group->where('employment_type', 'part_time')->count(),
            'on_leave' => $group->where('employment_status', 'on_leave')->count(),
        ];
    }

    public function summary(Collection $rows, array $params): array
    {
        return [
            ['label' => 'Departments', 'value' => number_format($rows->where('department', '!=', 'Unassigned')->count())],
            ['label' => 'Total headcount', 'value' => number_format((int) $rows->sum('headcount'))],
            ['label' => 'On leave', 'value' => number_format((int) $rows->sum('on_leave'))],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        $departmentBars = $rows
            ->filter(fn (array $row): bool => $row['headcount'] > 0)
            ->map(fn (array $row): array => ['label' => $row['department'], 'value' => $row['headcount']])
            ->values()
            ->all();

        return [
            $this->bars('Headcount by department', $departmentBars),
            $this->donut('By employment type', [
                'Regular' => (int) $rows->sum('regular'),
                'Probationary' => (int) $rows->sum('probationary'),
                'Contractual' => (int) $rows->sum('contractual'),
                'Part-time' => (int) $rows->sum('part_time'),
            ]),
        ];
    }
}
