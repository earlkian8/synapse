<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Switches the web session's active organisation for users who belong to more than
 * one company (ADR 0023). The chosen organisation is validated against the user's
 * memberships and stored in the session, where {@see SetCurrentOrganization}
 * reads it to bind the tenant on every subsequent request.
 */
class OrganizationSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        if (! $user->isMemberOf((int) $data['organization_id'])) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You are not a member of that organisation.']);

            return back();
        }

        $request->session()->put('active_organization_id', (int) $data['organization_id']);

        // Land on a neutral page so we never show a record from the previous tenant.
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace switched.']);

        return redirect()->route('dashboard');
    }
}
