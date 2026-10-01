<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Queries\Setup\DepartmentsScreen;
use App\Support\Hashid;
use App\Support\Setup\DepartmentException;
use App\Support\Setup\DepartmentWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The org-structure board and its department writes. Every write goes through
 * {@see DepartmentWorkflow} — the path the assistant takes too. Thin (route
 * gates `setup.departments.view` / `setup.departments.manage`).
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentWorkflow $workflow) {}

    /**
     * Display the org-structure board: the department hierarchy + positions.
     */
    public function index(Request $request, DepartmentsScreen $screen): Response
    {
        return Inertia::render('setup/departments', $screen->toArray($request));
    }

    /**
     * Return a single department with its positions — the detail drawer fetch.
     */
    public function show(Request $request, Department $department): DepartmentResource
    {
        $department->load([
            'head:id,first_name,middle_name,last_name,suffix,employee_no',
            'parent:id,name',
            'defaultWorkSchedule:id,name',
            'positions' => fn ($query) => $query->withCount('employees')->orderBy('title'),
        ])->loadCount(['employees', 'children']);

        return new DepartmentResource($department);
    }

    /**
     * Store a new department.
     */
    public function store(DepartmentRequest $request): RedirectResponse
    {
        $this->workflow->create($request->validated());

        return $this->respond('Department created.');
    }

    /**
     * Update the given department.
     */
    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->workflow->update($department, $request->validated());

        return $this->respond('Department updated.');
    }

    /**
     * Archive (soft-delete) a department.
     */
    public function destroy(Department $department): RedirectResponse
    {
        $this->workflow->archive($department);

        return $this->respond('Department archived.');
    }

    /**
     * Restore an archived department.
     */
    public function restore(string $department): RedirectResponse
    {
        try {
            $this->workflow->restore($this->findTrashed($department));
        } catch (DepartmentException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Department restored.');
    }

    /**
     * Permanently delete an archived department. Its positions, employees and
     * sub-departments are detached (their FKs null out), not deleted.
     */
    public function forceDelete(string $department): RedirectResponse
    {
        $this->workflow->forceDelete($this->findTrashed($department));

        return $this->respond('Department permanently deleted.');
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

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
