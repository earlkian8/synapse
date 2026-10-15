<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\LeaveWorkspaceRequest;
use App\Models\Organization;
use App\Queries\MyWorkspaces;
use App\Support\Workspaces\WorkspaceException;
use App\Support\Workspaces\Workspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Workspaces (ADR 0089): every company this person belongs to, and
 * leaving one. Theirs to do, like the rest of Settings — no workspace
 * permission is asked, and none could be: the question is about all of them.
 */
class WorkspaceMembershipController extends Controller
{
    public function edit(Request $request, MyWorkspaces $workspaces): Response
    {
        return Inertia::render('settings/workspaces', [
            'workspaces' => $workspaces->for($request->user(), withLeaving: true),
        ]);
    }

    /**
     * Leave a workspace. Leaving the one they are working in hands the session
     * to their next one; leaving their last sends them to start or join one.
     */
    public function destroy(LeaveWorkspaceRequest $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isMemberOf($organization), 404);

        try {
            Workspaces::leave($user, $organization);
        } catch (WorkspaceException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        if ((int) $request->session()->get('active_organization_id') === $organization->id) {
            $request->session()->forget('active_organization_id');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "You left {$organization->name}."]);

        return $user->memberships()->exists()
            ? redirect()->route('workspaces.manage')
            : redirect()->route('workspaces');
    }
}
