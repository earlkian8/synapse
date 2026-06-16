<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
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
            [
                'name' => Role::SUPER_ADMIN,
                'label' => 'Super Admin',
                'description' => 'Unrestricted access to every part of the organisation.',
                'is_system' => true,
                'permissions' => $allPermissions, // also bypasses all gates at runtime
            ],
            [
                'name' => 'administrator',
                'label' => 'Administrator',
                'description' => 'Full operational access across all modules.',
                'is_system' => true,
                'permissions' => $allPermissions,
            ],
            [
                'name' => 'hr-manager',
                'label' => 'HR Manager',
                'description' => 'Manages people and reviews the audit trail.',
                'is_system' => false,
                'permissions' => [
                    'employees.view', 'employees.create', 'employees.update',
                    'employees.delete', 'employees.restore', 'employees.export',
                    'employees.manage-documents',
                    'recruitment.view', 'recruitment.create', 'recruitment.update',
                    'recruitment.delete', 'recruitment.manage-pipeline',
                    'recruitment.schedule-interviews', 'recruitment.hire',
                    'recruitment.export',
                    'onboarding.view', 'onboarding.manage', 'onboarding.manage-programs',
                    'leave.view', 'leave.request', 'leave.manage',
                    'attendance.view', 'attendance.manage', 'attendance.clock',
                    'setup.departments.view', 'setup.departments.manage',
                    'setup.leave-types.view', 'setup.leave-types.manage',
                    'users.view', 'users.create', 'users.update',
                    'users.manage-status', 'users.reset-password', 'users.export',
                    'roles.view',
                    'activity-logs.view', 'activity-logs.export',
                    'notifications.send',
                ],
            ],
            [
                'name' => 'staff',
                'label' => 'Staff',
                'description' => 'Baseline access for regular employees.',
                'is_system' => false,
                'permissions' => [
                    // Self-service: clock in/out from web or the mobile app.
                    'attendance.clock',
                ],
            ],
        ];
    }
}
