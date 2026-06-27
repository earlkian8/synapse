<?php

namespace App\Support;

use App\Http\Controllers\Recruitment\HireController;
use App\Models\Applicant;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\User;
use App\Notifications\EmployeeCredentialsNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The recruitment → workforce bridge: turning a hired application into an
 * Employee. Extracted from {@see HireController}
 * so the manual UI and the assistant share one implementation (see ADR 0006/0007).
 */
class ApplicantHirer
{
    /**
     * Hire an applicant. Creates the Employee, copies the résumé into the new 201
     * file, seeds onboarding, marks the application hired and fills the posting
     * when its openings are met. Returns the new employee.
     *
     * @param  bool  $sendCredentials  Email the new hire their temporary password.
     *
     * @throws RuntimeException when the application has already been hired.
     */
    public static function hire(JobApplication $application, User $actor, bool $sendCredentials = true): Employee
    {
        $application->loadMissing(['applicant', 'jobPosting']);

        if ($application->hired_employee_id !== null) {
            throw new RuntimeException('This candidate has already been hired.');
        }

        $applicant = $application->applicant;
        $posting = $application->jobPosting;
        $temporaryPassword = null;

        $employee = DB::transaction(function () use ($application, $applicant, $posting, $actor, &$temporaryPassword): Employee {
            $employee = new Employee([
                'employee_no' => self::nextEmployeeNo(),
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

            self::copyResumeToFile($applicant, $employee, $actor->id);

            // Seed the new hire's onboarding from the best-matching program.
            OnboardingProvisioner::start($employee);

            // Provision the login account they'll use on the mobile app; the
            // temporary password is captured for the welcome email below.
            [, $temporaryPassword] = EmployeeAccountProvisioner::provision($employee);

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

        // Email the credentials after commit so nothing is sent on a rollback,
        // and only when a brand-new account (and password) was just created.
        if ($sendCredentials && $temporaryPassword !== null && $employee->user) {
            $employee->user->notify(new EmployeeCredentialsNotification(
                email: $employee->email,
                temporaryPassword: $temporaryPassword,
                organizationName: $posting->organization?->name ?? config('app.name', 'SYNAPSE'),
            ));
        }

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
            actor: $actor,
        );

        return $employee;
    }

    /**
     * Copy the applicant's résumé into the new employee's 201 file, if present.
     */
    private static function copyResumeToFile(Applicant $applicant, Employee $employee, int $userId): void
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
    private static function nextEmployeeNo(): string
    {
        $next = (int) Employee::withTrashed()->max('id') + 1;

        return 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
