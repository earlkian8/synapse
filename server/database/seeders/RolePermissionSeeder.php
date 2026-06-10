<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\PermissionSyncer;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the permission catalogue, the built-in roles, and grant the seeded
     * developer account the Super Admin role.
     */
    public function run(): void
    {
        // 1. Sync the permissions table to the code registry.
        PermissionSyncer::sync();

        $all = PermissionRegistry::names();

        // 2. Built-in roles and the permissions they grant.
        $roles = [
            [
                'name' => Role::SUPER_ADMIN,
                'label' => 'Super Admin',
                'description' => 'Unrestricted access to every part of the system.',
                'is_system' => true,
                'permissions' => $all, // also bypasses all gates at runtime
            ],
            [
                'name' => 'administrator',
                'label' => 'Administrator',
                'description' => 'Full operational access across all modules.',
                'is_system' => true,
                'permissions' => $all,
            ],
            [
                'name' => 'hr-manager',
                'label' => 'HR Manager',
                'description' => 'Manages people and reviews the audit trail.',
                'is_system' => false,
                'permissions' => [
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
                'permissions' => [],
            ],
        ];

        foreach ($roles as $definition) {
            $role = Role::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'is_system' => $definition['is_system'],
                ],
            );

            $permissionIds = Permission::whereIn('name', $definition['permissions'])->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        // 3. Grant the seeded developer account Super Admin.
        $superAdmin = Role::where('name', Role::SUPER_ADMIN)->first();
        $dev = User::where('email', 'dev@staffa.com')->first();

        if ($superAdmin && $dev) {
            $dev->roles()->syncWithoutDetaching([$superAdmin->id]);
        }
    }
}
