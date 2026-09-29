<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeCertificationRequest;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Support\Employees\EmployeeCertifications;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Adds and removes an employee's certifications. Thin: gated by
 * `employees.manage-documents`, it delegates to {@see EmployeeCertifications},
 * which the assistant uses too.
 */
class EmployeeCertificationController extends Controller
{
    public function store(StoreEmployeeCertificationRequest $request, Employee $employee, EmployeeCertifications $certifications): RedirectResponse
    {
        $certifications->add($employee, $request->safe()->except('file'), $request->file('file'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certification added.']);

        return back();
    }

    public function destroy(Employee $employee, EmployeeCertification $certification, EmployeeCertifications $certifications): RedirectResponse
    {
        abort_unless($certification->employee_id === $employee->id, 404);

        $certifications->remove($employee, $certification);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certification removed.']);

        return back();
    }
}
