<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Models\Employee;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeStatusController extends Controller
{
    /**
     * Quickly change an employee's employment status.
     */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'employment_status' => ['required', Rule::in(StoreEmployeeRequest::EMPLOYMENT_STATUSES)],
        ]);

        $employee->update(['employment_status' => $validated['employment_status']]);

        ActivityLogger::log(
            event: 'updated',
            description: "Set {$employee->full_name} to ".str_replace('_', ' ', $validated['employment_status']),
            subject: $employee,
            properties: ['employment_status' => $validated['employment_status']],
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Employment status updated.']);

        return back();
    }
}
