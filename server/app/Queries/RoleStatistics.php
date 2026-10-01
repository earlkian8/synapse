<?php

namespace App\Queries;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;

class RoleStatistics
{
    /**
     * Aggregate headline metrics for the roles dashboard.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'total' => Role::count(),
            'system' => Role::where('is_system', true)->count(),
            'custom' => Role::where('is_system', false)->count(),
            'permissions' => count(PermissionRegistry::names()),
            // Members of this workspace only: `role_user` and `users` span every
            // company on the instance (ADR 0023), and `roles` here is this one's.
            'assigned_users' => User::query()->inCurrentOrganization()->whereHas('roles')->count(),
            'unassigned_users' => User::query()->inCurrentOrganization()->whereDoesntHave('roles')->count(),
        ];
    }
}
