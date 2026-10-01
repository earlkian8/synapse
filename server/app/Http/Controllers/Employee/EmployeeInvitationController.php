<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeeInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * Issues and withdraws the claim ticket that lets an employee reach the app
 * (ADR 0026). Every decision lives in {@see EmployeeInvitations}; this only
 * authorises, validates and reports.
 */
class EmployeeInvitationController extends Controller
{
    /**
     * Invite (or re-invite) an employee. Re-inviting supersedes the previous code.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            // Optional override for people whose 201 address is a work mailbox they
            // cannot read yet, or who simply want the invite somewhere else.
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $invitation = EmployeeInvitations::invite($employee, $request->user(), $validated['email'] ?? null);
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond("Invitation sent to {$invitation->email}.");
    }

    /**
     * Withdraw the outstanding invitation on a roster line.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        $revoked = EmployeeInvitations::revoke($employee);

        return $revoked
            ? $this->respond("The invitation for {$employee->full_name} was revoked.")
            : $this->respond('There was no outstanding invitation to revoke.', 'warning');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
