<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Requests\Awards\EmployeeAwardRequest;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Give and manage recognitions: award an employee, edit an award (type / date /
 * reason), or remove it. Thin (route gate `awards.manage`). The granting user is
 * recorded as `awarded_by`.
 */
class EmployeeAwardController extends Controller
{
    /**
     * Give a recognition to an employee.
     */
    public function store(EmployeeAwardRequest $request): RedirectResponse
    {
        $award = EmployeeAward::create([
            ...$request->validated(),
            'awarded_by' => $request->user()->id,
        ]);

        $employee = Employee::find($award->employee_id);
        $type = AwardType::find($award->award_type_id);

        ActivityLogger::log(
            event: 'created',
            description: "Recognised {$employee?->full_name} — {$type?->name}",
            subject: $award,
            logName: 'awards',
            subjectLabel: $employee?->full_name,
        );

        return $this->respond('Recognition given.');
    }

    /**
     * Update an award (type / date / reason). The recipient never changes here.
     */
    public function update(EmployeeAwardRequest $request, EmployeeAward $employeeAward): RedirectResponse
    {
        $employeeAward->update($request->safe()->except(['employee_id']));

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a recognition',
            subject: $employeeAward,
            logName: 'awards',
            subjectLabel: $employeeAward->employee?->full_name,
        );

        return $this->respond('Recognition updated.');
    }

    /**
     * Remove an award.
     */
    public function destroy(EmployeeAward $employeeAward): RedirectResponse
    {
        $name = $employeeAward->employee?->full_name;
        $employeeAward->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed a recognition',
            logName: 'awards',
            subjectLabel: $name,
        );

        return $this->respond('Recognition removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
