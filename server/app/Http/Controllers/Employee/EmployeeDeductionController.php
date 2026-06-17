<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeDeductionRequest;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * An employee's recurring deductions (e.g. a loan) — the per-employee pay items
 * that drive deduction lines on their payslips on top of the mandatory statutory
 * ones (see App\Support\Payroll). Thin: authorize (route gate `payroll.adjust`),
 * validate, delegate, return.
 */
class EmployeeDeductionController extends Controller
{
    /**
     * Assign a recurring deduction to the employee.
     */
    public function store(EmployeeDeductionRequest $request, Employee $employee): RedirectResponse
    {
        $deduction = $employee->recurringDeductions()->create($request->validated());
        $deduction->load('deductionType');

        ActivityLogger::log(
            event: 'updated',
            description: "Added {$deduction->deductionType?->name} deduction for {$employee->full_name}",
            subject: $employee,
            logName: 'payroll',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Deduction added.');
    }

    /**
     * Update one of the employee's recurring deductions.
     */
    public function update(EmployeeDeductionRequest $request, Employee $employee, EmployeeDeduction $deduction): RedirectResponse
    {
        abort_unless($deduction->employee_id === $employee->id, 404);

        $deduction->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated a deduction for {$employee->full_name}",
            subject: $employee,
            logName: 'payroll',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Deduction updated.');
    }

    /**
     * Remove one of the employee's recurring deductions.
     */
    public function destroy(Employee $employee, EmployeeDeduction $deduction): RedirectResponse
    {
        abort_unless($deduction->employee_id === $employee->id, 404);

        $deduction->delete();

        ActivityLogger::log(
            event: 'updated',
            description: "Removed a deduction for {$employee->full_name}",
            subject: $employee,
            logName: 'payroll',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Deduction removed.');
    }

    private function respond(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
