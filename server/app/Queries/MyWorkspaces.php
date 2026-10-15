<?php

namespace App\Queries;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use App\Support\Workspaces\Workspaces;
use Illuminate\Database\Eloquent\Collection;

/**
 * The workspaces one person belongs to, as the workspace picker and Settings →
 * Workspaces show them: the company, their role there, its headcount, and —
 * for Settings — whether they may leave it (ADR 0089).
 *
 * Roles and employee counts are tenant-scoped, so each is read with that
 * organisation bound as the tenant (mirroring how the mobile API resolves a
 * per-organisation payload).
 */
final class MyWorkspaces
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user, bool $withLeaving = false): array
    {
        // Captured before the per-organisation loop below rebinds the tenant.
        $activeId = $this->tenancy->id();

        $workspaces = $user->memberships()
            ->orderByDesc('organization_user.is_default')
            ->orderBy('organizations.name')
            ->get()
            ->map(fn (Organization $organization): array => [
                ...$this->card($organization, $user, $activeId),
                ...($withLeaving ? ['leaving' => $this->leaving($organization, $user)] : []),
            ])
            ->all();

        // Leave the identity's roles as the active tenant would read them.
        $user->forgetCachedPermissions();

        return $workspaces;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Organization $organization, User $user, ?int $activeId): array
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
                'joined_on' => $organization->pivot->joined_at
                    ? substr((string) $organization->pivot->joined_at, 0, 10)
                    : null,
                'is_default' => (bool) $organization->pivot->is_default,
                'is_active' => $organization->id === $activeId,
            ];
        });
    }

    /**
     * Whether they may leave, and if they would be the last one out.
     *
     * @return array{allowed: bool, reason: string|null, alone: bool}
     */
    private function leaving(Organization $organization, User $user): array
    {
        $reason = Workspaces::whyNotLeave($user, $organization);

        return [
            'allowed' => $reason === null,
            'reason' => $reason,
            'alone' => Workspaces::isAlone($user, $organization),
        ];
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
