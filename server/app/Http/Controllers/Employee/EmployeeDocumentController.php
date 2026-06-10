<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeDocumentController extends Controller
{
    /**
     * Attach a document to an employee's 201 file.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['contract', 'cv', 'govt_id', 'other'])],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240'],
        ]);

        $employee->documents()->create([
            'title' => $validated['title'],
            'type' => $validated['type'],
            'file' => $request->file('file')->store('employee-documents', 'public'),
            'uploaded_by' => $request->user()->id,
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: "Added document \"{$validated['title']}\" to {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document uploaded.']);

        return back();
    }

    /**
     * Remove a document.
     */
    public function destroy(Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        abort_unless($document->employee_id === $employee->id, 404);

        Storage::disk('public')->delete($document->file);
        $document->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document removed.']);

        return back();
    }
}
