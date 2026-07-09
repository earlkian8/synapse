<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The post-login workspace picker.
 *
 * A user is one identity that may belong to several companies (ADR 0023). Rather
 * than drop straight into one, login lands here so the user chooses which company
 * to work in — the same "pick a project" landing pattern people know from tools
 * like Supabase or Vercel. Choosing a workspace posts to
 * {@see OrganizationSwitchController}, which binds the tenant for every later
 * request via {@see SetCurrentOrganization}.
 *
 * A user with a single membership has nothing to choose, so they skip the picker
 * and go straight to their dashboard.
 */
class WorkspaceController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        $memberships = $user->memberships()
            ->orderByDesc('organization_user.is_default')
            ->orderBy('organizations.name')
            ->get();

        // One company means nothing to choose — drop straight in. The active org is
        // already bound (and persisted to the session) by SetCurrentOrganization.
        if ($memberships->count() <= 1) {
            return redirect()->route('dashboard');
        }

        // Captured before the per-org runFor loop rebinds the tenant below.
        $activeId = $this->tenancy->id();

        $workspaces = $memberships
            ->map(fn (Organization $organization): array => $this->workspacePayload($organization, $user, $activeId))
            ->all();

        return Inertia::render('workspaces', [
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * Build the card payload for one workspace. Roles and employee counts are
     * tenant-scoped, so each is computed with the organisation bound as the current
     * tenant (mirrors how the mobile API resolves a per-org payload).
     *
     * @return array<string, mixed>
     */
    private function workspacePayload(Organization $organization, mixed $user, ?int $activeId): array
    {
        return $this->tenancy->runFor($organization, function () use ($organization, $user, $activeId): array {
            // Drop any roles memoised under a previous tenant before reading them.
            $user->forgetCachedPermissions();

            return [
                'id' => $organization->id,
                'name' => $organization->name,
                'logo_url' => $organization->logo_url,
                'initials' => $organization->initials(),
                'role' => $this->primaryRoleLabel($user->roles()->get()),
                'people_count' => $organization->employees()->count(),
                'is_default' => (bool) $organization->pivot->is_default,
                'is_active' => $organization->id === $activeId,
            ];
        });
    }

    /**
     * A short, human label for the user's standing in a workspace: the most
     * privileged role, with a "+N" hint when they hold several.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function primaryRoleLabel(Collection $roles): ?string
    {
        if ($roles->isEmpty()) {
            return null;
        }

        // The owner role (HR Manager) reads first; otherwise the first role assigned.
        $primary = $roles->firstWhere('name', Role::SUPER_ADMIN)
            ?? $roles->first();

        $extra = $roles->count() - 1;

        return $extra > 0 ? "{$primary->label} +{$extra}" : $primary->label;
    }
}
