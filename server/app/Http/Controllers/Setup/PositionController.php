<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\PositionRequest;
use App\Models\Department;
use App\Models\Position;
use App\Support\Setup\DepartmentWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Positions under a department: add, edit, delete. The writes go through
 * {@see DepartmentWorkflow}, the path the assistant takes too. Thin (route gate
 * `setup.departments.manage`).
 */
class PositionController extends Controller
{
    public function __construct(private readonly DepartmentWorkflow $workflow) {}

    /**
     * Add a position under a department.
     */
    public function store(PositionRequest $request, Department $department): RedirectResponse
    {
        $this->workflow->addPosition($department, $request->validated());

        return $this->respond('Position added.');
    }

    /**
     * Update a position.
     */
    public function update(PositionRequest $request, Position $position): RedirectResponse
    {
        $this->workflow->updatePosition($position, $request->validated());

        return $this->respond('Position updated.');
    }

    /**
     * Delete a position. Employees holding it keep their record (the FK nulls out).
     */
    public function destroy(Position $position): RedirectResponse
    {
        $this->workflow->deletePosition($position);

        return $this->respond('Position deleted.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
