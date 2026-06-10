<?php

namespace App\Queries;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\DB;

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
            'assigned_users' => DB::table('role_user')->distinct()->count('user_id'),
            'unassigned_users' => User::whereDoesntHave('roles')->count(),
        ];
    }
}
