<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeAllowanceRequest;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * An employee's recurring allowances — the per-employee pay items that drive the
 * allowance earning lines on their payslips (see App\Support\Payroll). Thin:
 * authorize (route gate `payroll.adjust`), validate, delegate, return.
 */
class EmployeeAllowanceController extends Controller
{
    /**
     * Assign a recurring allowance to the employee.
     */
    public function store(EmployeeAllowanceRequest $request, Employee $employee): RedirectResponse
    {
        $allowance = $employee->allowances()->create($request->validated());
        $allowance->load('allowanceType');

        ActivityLogger::log(
            event: 'updated',
            description: "Added {$allowance->allowanceType?->name} allowance for {$employee->full_name}",
            subject: $employee,
            logName: 'payroll',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Allowance added.');
    }

    /**
     * Update one of the employee's recurring allowances.
     */
    public function update(EmployeeAllowanceRequest $request, Employee $employee, EmployeeAllowance $allowance): RedirectResponse
    {
        abort_unless($allowance->employee_id === $employee->id, 404);

        $allowance->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated an allowance for {$employee->full_name}",
            subject: $employee,
            logName: 'payroll',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Allowance updated.');
    }

    /**
     * Remove one of the employee's recurring allowances.
     */
    public function destroy(Employee $employee, EmployeeAllowance $allowance): RedirectResponse
    {
        abort_unless($allowance->employee_id === $employee->id, 404);

        $allowance->delete();

        ActivityLogger::log(
            event: 'updated',
            description: "Removed an allowance for {$employee->full_name}",
            subject: $employee,
            logName: 'payroll',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Allowance removed.');
    }

    private function respond(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
