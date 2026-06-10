<?php

namespace App\Support;

use App\Models\Permission;

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
}
