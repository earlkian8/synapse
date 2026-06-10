<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class HireController extends Controller
{
    /**
     * Hire an applicant — the recruitment → workforce bridge.
     *
     * Creates an Employee from the applicant + posting, copies the résumé into the
     * new 201 file, marks the application hired (linking the employee), and fills
     * the posting when its openings are met. See ADR 0006.
     */
    public function __invoke(Request $request, JobApplication $application): RedirectResponse
    {
        $application->load(['applicant', 'jobPosting']);

        if ($application->hired_employee_id !== null) {
            return $this->respond('This candidate has already been hired.', 'warning');
        }

        $applicant = $application->applicant;
        $posting = $application->jobPosting;

        $employee = DB::transaction(function () use ($request, $application, $applicant, $posting): Employee {
            $employee = new Employee([
                'employee_no' => $this->nextEmployeeNo(),
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'department_id' => $posting->department_id,
                'position_id' => $posting->position_id,
                'employment_type' => $posting->employment_type,
                'employment_status' => 'active',
                'date_hired' => now()->toDateString(),
            ]);
            $employee->save();

            $this->copyResumeToFile($applicant, $employee, $request->user()->id);

            $application->update([
                'stage' => 'hired',
                'hired_employee_id' => $employee->id,
                'decided_at' => now(),
            ]);

            // Auto-fill the posting once all openings are taken.
            $hired = $posting->applications()->where('stage', 'hired')->count();

            if ($posting->status === 'open' && $hired >= $posting->openings) {
                $posting->update(['status' => 'filled']);
            }

            return $employee;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Hired {$applicant->full_name} as employee {$employee->employee_no}",
            subject: $employee,
            properties: ['job_posting' => $posting->title, 'application_id' => $application->id],
            logName: 'recruitment',
            subjectLabel: $applicant->full_name,
        );

        Notifier::toRole(
            'hr-manager',
            'New hire',
            "{$applicant->full_name} was hired for {$posting->title} (employee {$employee->employee_no}).",
            url: '/employees',
            level: 'success',
            category: 'recruitment',
            actor: $request->user(),
        );

        return $this->respond("{$applicant->full_name} hired — employee {$employee->employee_no} created.");
    }

    /**
     * Copy the applicant's résumé into the new employee's 201 file, if present.
     */
    private function copyResumeToFile(Applicant $applicant, Employee $employee, int $userId): void
    {
        if (! $applicant->resume || ! Storage::disk('public')->exists($applicant->resume)) {
            return;
        }

        $extension = pathinfo($applicant->resume, PATHINFO_EXTENSION) ?: 'pdf';
        $destination = 'employee-documents/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->copy($applicant->resume, $destination);

        $employee->documents()->create([
            'title' => 'Résumé (from application)',
            'type' => 'cv',
            'file' => $destination,
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Generate the next sequential employee number for the current tenant.
     */
    private function nextEmployeeNo(): string
    {
        $next = (int) Employee::withTrashed()->max('id') + 1;

        return 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
