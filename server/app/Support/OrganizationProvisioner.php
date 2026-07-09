<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Stands up a new organisation's baseline: its set of built-in roles, each wired to
 * the permissions it should grant.
 *
 * Used both at registration (every new tenant gets its own role set) and by the
 * seeders (the demo tenant). Permissions themselves are global and code-defined
 * (see {@see PermissionRegistry}); only roles and their grants are per-organisation.
 */
class OrganizationProvisioner
{
    /**
     * Build the standard role set for an organisation and return its super-admin
     * role (the one granted to the owner). Idempotent — safe to re-run.
     */
    public static function provisionRoles(Organization $organization): Role
    {
        $all = PermissionRegistry::names();

        $superAdmin = null;

        foreach (self::roleBlueprints($all) as $blueprint) {
            $role = Role::updateOrCreate(
                ['organization_id' => $organization->id, 'name' => $blueprint['name']],
                [
                    'label' => $blueprint['label'],
                    'description' => $blueprint['description'],
                    'is_system' => $blueprint['is_system'],
                ],
            );

            $role->permissions()->sync(
                Permission::whereIn('name', $blueprint['permissions'])->pluck('id'),
            );

            if ($role->isSuperAdmin()) {
                $superAdmin = $role;
            }
        }

        return $superAdmin;
    }

    /**
     * Add a user to an organisation (idempotent), recording the membership that
     * lets them act in that tenant (ADR 0023). `$default` marks the org a fresh
     * login should land in — used for the first organisation a user joins.
     */
    public static function addMember(Organization $organization, User $user, bool $default = false): void
    {
        if ($user->isMemberOf($organization)) {
            if ($default) {
                $user->memberships()->updateExistingPivot($organization->id, ['is_default' => true]);
            }

            return;
        }

        $user->memberships()->attach($organization->id, [
            'is_default' => $default,
            'joined_at' => now(),
        ]);
    }

    /**
     * Provision a brand-new organisation from a display name: unique slug, default
     * roles. Returns the organisation and its super-admin role.
     *
     * @return array{0: Organization, 1: Role}
     */
    public static function create(string $name): array
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => self::uniqueSlug($name),
        ]);

        return [$organization, self::provisionRoles($organization)];
    }

    /**
     * Derive an organisation slug that is unique across all tenants.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    /**
     * The standard built-in roles every organisation starts with.
     *
     * @param  list<string>  $allPermissions
     * @return list<array{name: string, label: string, description: string, is_system: bool, permissions: list<string>}>
     */
    private static function roleBlueprints(array $allPermissions): array
    {
        return [
            // 1. HR Manager — the organisation owner. Full access to every module,
            //    predictive analytics, the assistant, setup, and administration.
            //    Granted every permission and bypasses all gates at runtime.
            [
                'name' => Role::HR_MANAGER,
                'label' => 'HR Manager',
                'description' => 'Organisation owner with full access to every module, analytics, the assistant, setup, and administration.',
                'is_system' => true,
                'permissions' => $allPermissions,
            ],

            // 2. Department Head — the supervisor. Approves leave, conducts
            //    performance evaluations, and has read visibility into the records
            //    and predictive signals of the workforce they oversee.
            [
                'name' => Role::DEPARTMENT_HEAD,
                'label' => 'Department Head',
                'description' => 'Supervisor who approves leave, conducts performance evaluations, and reviews team records and predictive signals.',
                'is_system' => true,
                'permissions' => [
                    // Self-service (as an employee themselves)
                    'attendance.clock', 'leave.request',
                    // Team visibility
                    'employees.view',
                    'attendance.view',
                    'onboarding.view', 'offboarding.view',
                    'training.view', 'awards.view', 'events.view',
                    // Supervisory actions
                    'leave.view', 'leave.manage',
                    'performance.view', 'performance.manage',
                    // Decision support (view-only) for their people
                    'analytics.attrition.view',
                    'analytics.performance.view',
                    'analytics.promotion.view',
                ],
            ],

            // 3. Staff — the regular employee. Self-service only: record attendance
            //    and file/cancel their own leave (web or the mobile DTR app).
            [
                'name' => Role::STAFF,
                'label' => 'Staff',
                'description' => 'Regular employee with self-service access: record attendance and file leave.',
                'is_system' => true,
                'permissions' => [
                    'attendance.clock',
                    'leave.request',
                ],
            ],
        ];
    }
}
