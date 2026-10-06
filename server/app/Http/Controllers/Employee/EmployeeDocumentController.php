<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Employees\EmployeeDocuments;
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
    public function store(Request $request, Employee $employee, EmployeeDocuments $documents): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(EmployeeDocuments::TYPES)],
            'file' => ['required', 'file', 'mimes:'.EmployeeDocuments::FILE_MIMES, 'max:'.EmployeeDocuments::FILE_MAX_KB],
        ]);

        $documents->add(
            $employee,
            $validated['title'],
            $validated['type'],
            $request->file('file')->store('employee-documents', 'public'),
            $request->user()->id,
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
