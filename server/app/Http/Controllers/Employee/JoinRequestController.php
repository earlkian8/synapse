<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OrganizationJoinRequest;
use App\Support\WorkspaceJoin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * Admits or turns away the people who used the organisation's join code but could
 * not be matched to a roster line automatically (ADR 0026).
 *
 * Approving is not a rubber stamp: HR must nominate *which* employee record the
 * person is, because binding the wrong one hands somebody another human's 201
 * file. All of that is enforced in {@see WorkspaceJoin}.
 *
 * Route-model binding is tenant-scoped by the global scope on
 * {@see OrganizationJoinRequest}, so a request belonging to another company is a
 * 404 here rather than something to check for.
 */
class JoinRequestController extends Controller
{
    /**
     * Approve a pending request and bind it to the roster line HR nominates.
     */
    public function approve(Request $request, OrganizationJoinRequest $joinRequest): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => [
                'required',
                // Scoped by the same global scope, so this can only ever name an
                // employee of the current organisation.
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        try {
            WorkspaceJoin::approve($joinRequest, $employee, $request->user());
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond("{$joinRequest->user?->full_name} now has access as {$employee->full_name}.");
    }

    /**
     * Turn a pending request down, optionally telling them why.
     */
    public function decline(Request $request, OrganizationJoinRequest $joinRequest): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            WorkspaceJoin::decline($joinRequest, $request->user(), $validated['reason'] ?? null);
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Request declined.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
