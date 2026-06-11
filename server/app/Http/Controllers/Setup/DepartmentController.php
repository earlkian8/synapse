<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Employee;
use App\Queries\DepartmentStatistics;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Display the org-structure board: the department hierarchy + positions.
     */
    public function index(Request $request, DepartmentStatistics $statistics): Response
    {
        return Inertia::render('setup/departments', [
            'departments' => DepartmentResource::collection($this->listing()->get())->resolve($request),
            'archived' => DepartmentResource::collection(
                $this->listing()->onlyTrashed()->get()
            )->resolve($request),
            'stats' => $statistics->toArray(),
            'options' => ['employees' => $this->employeeOptions()],
            'can' => ['manage' => $request->user()->can('setup.departments.manage')],
        ]);
    }

    /**
     * Return a single department with its positions — the detail drawer fetch.
     */
    public function show(Request $request, Department $department): DepartmentResource
    {
        $department->load([
            'head:id,first_name,middle_name,last_name,suffix,employee_no',
            'parent:id,name',
            'positions' => fn ($query) => $query->withCount('employees')->orderBy('title'),
        ])->loadCount(['employees', 'children']);

        return new DepartmentResource($department);
    }

    /**
     * Store a new department.
     */
    public function store(DepartmentRequest $request): RedirectResponse
    {
        $department = Department::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created department \"{$department->name}\"",
            subject: $department,
            logName: 'company-setup',
            subjectLabel: $department->name,
        );

        return $this->respond('Department created.');
    }

    /**
     * Update the given department.
     */
    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated department \"{$department->name}\"",
            subject: $department,
            logName: 'company-setup',
            subjectLabel: $department->name,
        );

        return $this->respond('Department updated.');
    }

    /**
     * Archive (soft-delete) a department.
     */
    public function destroy(Department $department): RedirectResponse
    {
        $name = $department->name;
        $department->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived department \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Department archived.');
    }

    /**
     * Restore an archived department.
     */
    public function restore(string $department): RedirectResponse
    {
        $model = $this->findTrashed($department);

        // The per-tenant unique index ignores archived rows, so a code may have been
        // reused while this one was archived — refuse rather than hit a DB error.
        $clash = Department::where('code', $model->code)->whereKeyNot($model->id)->exists();

        if ($clash) {
            return $this->respond("Another department already uses the code \"{$model->code}\".", 'warning');
        }

        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored department \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Department restored.');
    }

    /**
     * Permanently delete an archived department. Its positions, employees and
     * sub-departments are detached (their FKs null out), not deleted.
     */
    public function forceDelete(string $department): RedirectResponse
    {
        $model = $this->findTrashed($department);
        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted department \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Department permanently deleted.');
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
     * Resolve an archived department from its hashid, or 404.
     */
    private function findTrashed(string $code): Department
    {
        $id = Hashid::decode($code);

        abort_if($id === null, 404);

        return Department::onlyTrashed()->findOrFail($id);
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

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
