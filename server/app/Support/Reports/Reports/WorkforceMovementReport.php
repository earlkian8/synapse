<?php

namespace App\Support\Reports\Reports;

use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * Workforce movement: every hire and every completed separation inside a date window,
 * the raw material for turnover analysis. Hires come from `date_hired`; separations
 * come from completed offboarding cases (using the last working day as the exit date),
 * so an exit is only counted once it is final.
 */
class WorkforceMovementReport implements Report
{
    use BuildsReport;

    private const EXIT_TYPE_LABELS = [
        'resignation' => 'Resignation',
        'termination' => 'Termination',
        'retirement' => 'Retirement',
        'end_of_contract' => 'End of contract',
    ];

    public function key(): string
    {
        return 'workforce-movement';
    }

    public function name(): string
    {
        return 'Workforce Movement';
    }

    public function description(): string
    {
        return 'Hires and separations within a period — the basis for turnover and net-growth analysis.';
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
            // A trailing-two-year window so the report opens on real movement history;
            // narrow it to a quarter or a year as needed.
            $this->dateRangeFilter('Period', now()->subYears(2)->startOfMonth(), now()),
            $this->selectFilter('event', 'Movement', ['hire' => 'Hires', 'separation' => 'Separations'], 'All movement'),
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'Date', 'align' => 'left', 'type' => 'date'],
            ['key' => 'event', 'label' => 'Movement', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'full_name', 'label' => 'Employee', 'align' => 'left', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'type' => 'text'],
            ['key' => 'employment_type', 'label' => 'Type', 'align' => 'left', 'type' => 'text'],
            ['key' => 'detail', 'label' => 'Detail', 'align' => 'left', 'type' => 'text'],
        ];
    }

    public function rows(array $params): Collection
    {
        $start = $params['start'];
        $end = $params['end'];
        $rows = collect();

        if ($params['event'] !== 'separation') {
            $hires = Employee::query()
                ->with('department:id,name')
                ->whereBetween('date_hired', [$start, $end])
                ->get()
                ->map(fn (Employee $employee): array => [
                    'date' => $employee->date_hired?->toDateString() ?? '',
                    'event' => 'Hired',
                    'full_name' => $employee->full_name,
                    'department' => $employee->department?->name ?? '—',
                    'employment_type' => EmployeeMasterlistReport::TYPE_LABELS[$employee->employment_type] ?? $employee->employment_type,
                    'detail' => 'New hire',
                ]);

            $rows = $rows->concat($hires);
        }

        if ($params['event'] !== 'hire') {
            $exits = OffboardingCase::query()
                ->with('employee:id,first_name,middle_name,last_name,suffix,department_id', 'employee.department:id,name')
                ->where('status', 'completed')
                ->whereNotNull('last_working_day')
                ->whereBetween('last_working_day', [$start, $end])
                ->get()
                ->map(fn (OffboardingCase $case): array => [
                    'date' => $case->last_working_day?->toDateString() ?? '',
                    'event' => 'Separated',
                    'full_name' => $case->employee?->full_name ?? '—',
                    'department' => $case->employee?->department?->name ?? '—',
                    'employment_type' => '—',
                    'detail' => self::EXIT_TYPE_LABELS[$case->type] ?? $case->type,
                ]);

            $rows = $rows->concat($exits);
        }

        return $rows->sortByDesc('date')->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        $hires = $rows->where('event', 'Hired')->count();
        $exits = $rows->where('event', 'Separated')->count();

        return [
            ['label' => 'Hires', 'value' => number_format($hires)],
            ['label' => 'Separations', 'value' => number_format($exits)],
            ['label' => 'Net change', 'value' => sprintf('%+d', $hires - $exits)],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->donut('Movement mix', [
                'Hired' => $rows->where('event', 'Hired')->count(),
                'Separated' => $rows->where('event', 'Separated')->count(),
            ]),
            $this->barsFromCounts('By department', $rows->groupBy('department')->map->count()->all()),
        ];
    }
}
