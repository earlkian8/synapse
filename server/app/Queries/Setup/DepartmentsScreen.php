<?php

namespace App\Queries\Setup;

use App\Http\Resources\DepartmentResource;
use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Queries\DepartmentStatistics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Company Setup → Departments: the org-structure board — the department
 * hierarchy, the positions under each, and what a department's members default
 * to.
 */
class DepartmentsScreen implements SetupScreen
{
    public function __construct(private readonly DepartmentStatistics $statistics) {}

    public function toArray(Request $request): array
    {
        return [
            'departments' => DepartmentResource::collection($this->listing()->get())->resolve($request),
            'archived' => DepartmentResource::collection(
                $this->listing()->onlyTrashed()->get()
            )->resolve($request),
            'stats' => $this->statistics->toArray(),
            'options' => [
                'employees' => $this->employeeOptions(),
                // The shift a department's members work by default (ADR 0037).
                'schedules' => WorkSchedule::orderBy('name')->get(['id', 'name']),
                // …and the attendance policy they are judged by (ADR 0038).
                'policies' => AttendancePolicy::orderBy('name')->get(['id', 'name']),
            ],
            'can' => ['manage' => $request->user()->can('setup.departments.manage')],
        ];
    }

    /**
     * The base listing query, shared by the active and archived sets.
     *
     * @return Builder<Department>
     */
    private function listing(): Builder
    {
        return Department::query()
            ->with([
                'head:id,first_name,middle_name,last_name,suffix,employee_no',
                'parent:id,name',
                'positions' => fn ($query) => $query->withCount('employees')->orderBy('title'),
            ])
            ->withCount(['employees', 'positions', 'children'])
            ->orderBy('name');
    }

    /**
     * Active employees a department may be headed by.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function employeeOptions(): array
    {
        return Employee::query()
            ->orderBy('first_name')
            ->limit(500)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'employee_no' => $e->employee_no,
            ])
            ->all();
    }
}
