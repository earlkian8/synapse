<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Requests\Awards\EmployeeAwardRequest;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Support\Awards\AwardException;
use App\Support\Awards\AwardWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Give and manage recognitions: award an employee, edit an award (type / date /
 * reason), or remove it. The rules live in {@see AwardWorkflow}, which the
 * assistant uses too. Thin (route gate `awards.manage`). The granting user is
 * recorded as `awarded_by`.
 */
class EmployeeAwardController extends Controller
{
    public function __construct(private readonly AwardWorkflow $workflow) {}

    /**
     * Give a recognition to an employee.
     */
    public function store(EmployeeAwardRequest $request): RedirectResponse
    {
        // Resolved through the models, so the tenant scope applies here as well
        // as in the request's rules.
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));
        $type = AwardType::query()->withTrashed()->findOrFail($request->validated('award_type_id'));

        try {
            $this->workflow->give(
                $employee,
                $type,
                $request->validated('awarded_on'),
                $request->validated('reason'),
                $request->user(),
            );
        } catch (AwardException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Recognition given.');
    }

    /**
     * Update an award (type / date / reason). The recipient never changes here.
     */
    public function update(EmployeeAwardRequest $request, EmployeeAward $employeeAward): RedirectResponse
    {
        try {
            $this->workflow->revise($employeeAward, $request->safe()->except(['employee_id']));
        } catch (AwardException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Recognition updated.');
    }

    /**
     * Remove an award.
     */
    public function destroy(EmployeeAward $employeeAward): RedirectResponse
    {
        $this->workflow->remove($employeeAward);

        return $this->respond('Recognition removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
