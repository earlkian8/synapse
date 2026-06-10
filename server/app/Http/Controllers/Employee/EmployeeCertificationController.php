<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class EmployeeCertificationController extends Controller
{
    /**
     * Record a certification for an employee.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $employee->certifications()->create([
            'name' => $validated['name'],
            'issuer' => $validated['issuer'] ?? null,
            'issued_date' => $validated['issued_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'file' => $request->hasFile('file')
                ? $request->file('file')->store('employee-certifications', 'public')
                : null,
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: "Added certification \"{$validated['name']}\" to {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certification added.']);

        return back();
    }

    /**
     * Remove a certification.
     */
    public function destroy(Employee $employee, EmployeeCertification $certification): RedirectResponse
    {
        abort_unless($certification->employee_id === $employee->id, 404);

        if ($certification->file) {
            Storage::disk('public')->delete($certification->file);
        }

        $certification->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certification removed.']);

        return back();
    }
}
