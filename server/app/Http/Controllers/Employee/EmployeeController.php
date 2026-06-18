<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AllowanceType;
use App\Models\DeductionType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Queries\EmployeesIndexQuery;
use App\Queries\EmployeeStatistics;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    /**
     * Display the employee directory.
     */
    public function index(Request $request, EmployeesIndexQuery $query, EmployeeStatistics $statistics): Response
    {
        [$sort, $direction] = $query->sort($request);

        return Inertia::render('employees/index', [
            'employees' => EmployeeResource::collection($query->paginate($request)),
            'stats' => $statistics->toArray(),
            'options' => $this->formOptions(),
            'can' => [
                'create' => $request->user()->can('employees.create'),
                'update' => $request->user()->can('employees.update'),
                'delete' => $request->user()->can('employees.delete'),
                'restore' => $request->user()->can('employees.restore'),
                'forceDelete' => $request->user()->can('employees.force-delete'),
                'export' => $request->user()->can('employees.export'),
                'manageDocuments' => $request->user()->can('employees.manage-documents'),
            ],
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request),
                'type' => $query->type($request),
                'department' => $request->integer('department') ?: null,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $query->perPage($request),
            ],
        ]);
    }

    /**
     * Return a single employee with all sub-records (for the detail drawer). The
     * allowance / deduction type catalogues are attached alongside so the
     * Compensation tab's pickers read the tenant's Company-Setup config.
     */
    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $employee->load([
            'department', 'position', 'manager', 'workSchedule', 'user',
            'documents.uploader', 'certifications',
            'promotions.fromPosition', 'promotions.toPosition',
            'allowances.allowanceType', 'recurringDeductions.deductionType',
            'benefitEnrollments.plan',
            'performanceEvaluations.period:id,name,start_date,end_date',
            'trainingEnrollments.program:id,name,provider,start_date,end_date',
            'awards.awardType:id,name,color',
            'eventAttendances.event:id,title,type,starts_at,ends_at',
        ])->loadCount(['documents', 'certifications']);

        return (new EmployeeResource($employee))->additional([
            'allowance_types' => AllowanceType::orderBy('name')->get(['id', 'name']),
            'deduction_types' => DeductionType::orderBy('name')->get(['id', 'name']),
            'can_adjust_payroll' => $request->user()->can('payroll.adjust'),
        ]);
    }

    /**
     * Store a newly created employee.
     */
    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $data = Arr::except($request->validated(), ['photo']);
        $data['employee_no'] = $data['employee_no'] ?: $this->nextEmployeeNo();

        $employee = new Employee($data);

        if ($request->hasFile('photo')) {
            $employee->photo = $request->file('photo')->store('employee-photos', 'public');
        }

        $employee->save();

        ActivityLogger::log(
            event: 'created',
            description: "Added employee {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Employee added.');
    }

    /**
     * Update the given employee.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $original = $employee->only(['position_id', 'basic_salary']);

        $data = Arr::except($request->validated(), ['photo', 'remove_photo']);
        $data['employee_no'] = $data['employee_no'] ?: $employee->employee_no;

        $employee->fill($data);

        if ($request->boolean('remove_photo')) {
            $this->deletePhoto($employee);
        }

        if ($request->hasFile('photo')) {
            $this->deletePhoto($employee);
            $employee->photo = $request->file('photo')->store('employee-photos', 'public');
        }

        $employee->save();

        // Record career history whenever the position or salary changes.
        $this->recordPromotion($request, $employee, $original);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated employee {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Employee updated.');
    }

    /**
     * Archive (soft delete) the given employee.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived employee {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Employee archived.');
    }

    /**
     * Restore a previously archived employee.
     */
    public function restore(int $employee): RedirectResponse
    {
        $model = Employee::onlyTrashed()->findOrFail($employee);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored employee {$model->full_name}",
            subject: $model,
            logName: 'employees',
            subjectLabel: $model->full_name,
        );

        return $this->respond('Employee restored.');
    }

    /**
     * Permanently delete an employee.
     */
    public function forceDelete(int $employee): RedirectResponse
    {
        $model = Employee::withTrashed()->findOrFail($employee);
        $label = $model->full_name;

        $this->deletePhoto($model);
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted employee {$label}",
            logName: 'employees',
            subjectLabel: $label,
        );

        return $this->respond('Employee permanently deleted.');
    }

    /**
     * Record a promotion/career-history row when position or salary changed.
     *
     * @param  array{position_id: int|null, basic_salary: string|null}  $original
     */
    private function recordPromotion(Request $request, Employee $employee, array $original): void
    {
        $positionChanged = (int) $original['position_id'] !== (int) $employee->position_id;
        $salaryChanged = (string) $original['basic_salary'] !== (string) $employee->basic_salary;

        if (! $positionChanged && ! $salaryChanged) {
            return;
        }

        $employee->promotions()->create([
            'from_position_id' => $original['position_id'],
            'to_position_id' => $employee->position_id,
            'from_salary' => $original['basic_salary'],
            'to_salary' => $employee->basic_salary,
            'effective_date' => now()->toDateString(),
            'reason' => $positionChanged ? 'Position change' : 'Salary adjustment',
            'approved_by' => $request->user()->id,
        ]);
    }

    /**
     * Build the select options used by the filters and the create/edit form.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(['id', 'name', 'code']),
            'positions' => Position::orderBy('title')->get(['id', 'title', 'department_id']),
            'schedules' => WorkSchedule::orderBy('name')->get(['id', 'name']),
            'managers' => Employee::orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
                ->map(fn (Employee $e): array => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_no' => $e->employee_no,
                ])
                ->all(),
            'users' => User::query()
                ->whereDoesntHave('employee')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'email'])
                ->map(fn ($u): array => [
                    'id' => $u->id,
                    'full_name' => $u->full_name,
                    'email' => $u->email,
                ])
                ->all(),
        ];
    }

    /**
     * Generate the next sequential employee number.
     */
    private function nextEmployeeNo(): string
    {
        $next = (int) Employee::withTrashed()->max('id') + 1;

        return 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Remove an employee's stored photo from disk, if any.
     */
    private function deletePhoto(Employee $employee): void
    {
        if ($employee->photo) {
            Storage::disk('public')->delete($employee->photo);
            $employee->photo = null;
        }
    }

    /**
     * Flash a toast and bounce back to the listing.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
