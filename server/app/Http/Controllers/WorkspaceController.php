<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Queries\MyWorkspaces;
use App\Support\Workspaces\Workspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The post-login workspace picker, and starting a new workspace.
 *
 * A user is one identity that may belong to several companies (ADR 0023). Rather
 * than drop straight into one, login lands here so the user chooses which company
 * to work in — the same "pick a project" landing pattern people know from tools
 * like Supabase or Vercel. Choosing a workspace posts to
 * {@see OrganizationSwitchController}, which binds the tenant for every later
 * request via {@see SetCurrentOrganization}.
 *
 * A user with a single membership has nothing to choose, so they skip the picker
 * and go straight to their dashboard. A user with none — they left their last
 * company, or registered in the app and have not joined one — sees how to start
 * one or join one (ADR 0089).
 */
class WorkspaceController extends Controller
{
    public function index(Request $request, MyWorkspaces $workspaces): Response|RedirectResponse
    {
        // One company means nothing to choose — drop straight in. The active org is
        // already bound (and persisted to the session) by SetCurrentOrganization.
        if ($request->user()->memberships()->count() === 1) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('workspaces', [
            'workspaces' => $workspaces->for($request->user()),
        ]);
    }

    /**
     * Start a new workspace and step into it. It has never been set up, so it
     * opens on the setup wizard — straight there, so the toast survives.
     */
    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $organization = Workspaces::create($request->user(), $request->validated('name'));

        $request->session()->put('active_organization_id', $organization->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace created.']);

        return redirect()->route('setup.wizard.show');
    }
}
