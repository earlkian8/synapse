<?php

namespace App\Support;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class PermissionSyncer
{
    /**
     * Reconcile the `permissions` table with the code-defined registry:
     * create missing permissions, refresh labels/groups, and prune any that
     * are no longer declared.
     */
    public static function sync(): void
    {
        $catalog = PermissionRegistry::all();

        foreach ($catalog as $name => $meta) {
            Permission::updateOrCreate(
                ['name' => $name],
                ['label' => $meta['label'], 'group' => $meta['group']],
            );
        }

        Permission::query()
            ->whereNotIn('name', $catalog->keys()->all())
            ->delete();
    }

    /**
     * Give permissions to the built-in roles of that name in **every**
     * organisation — how a migration hands a new permission to the companies that
     * already exist, as {@see OrganizationProvisioner} does for a new one. Roles
     * an organisation made itself are left to it. Idempotent: a role that already
     * holds a permission is not given it twice.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $roles
     */
    public static function grant(array $permissions, array $roles): void
    {
        self::sync();

        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

        foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
}
