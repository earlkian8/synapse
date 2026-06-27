<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Notifications\EmployeeCredentialsNotification;
use App\Support\ActivityLogger;
use App\Support\EmployeeAccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Manages the login account behind an employee — the bridge between the 201
 * record and their SYNAPSE app access. Reuses {@see EmployeeAccountProvisioner}
 * (the same code the hire bridge uses) so password rotation and first-time
 * provisioning behave identically everywhere.
 */
class EmployeeAccountController extends Controller
{
    /**
     * Reset (or first-time provision) the employee's mobile-app login and email
     * them the new temporary password.
     */
    public function resetPassword(Employee $employee): RedirectResponse
    {
        if (! filled($employee->email)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'This employee has no email address on file to send credentials to.']);

            return back();
        }

        [$user, $password] = EmployeeAccountProvisioner::resetPassword($employee);

        if (! $user || $password === null) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Could not reset this employee’s login.']);

            return back();
        }

        $user->notify(new EmployeeCredentialsNotification(
            email: $user->email,
            temporaryPassword: $password,
            organizationName: $employee->organization?->name ?? config('app.name', 'SYNAPSE'),
        ));

        ActivityLogger::log(
            event: 'updated',
            description: "Reset login credentials for {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "New credentials sent to {$user->email}."]);

        return back();
    }
}
