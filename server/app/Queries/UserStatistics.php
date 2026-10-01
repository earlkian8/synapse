<?php

namespace App\Queries;

use App\Models\User;

class UserStatistics
{
    /**
     * Aggregate headline metrics for the user management dashboard.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        // Scoped to the active tenant's members — users are global identities now.
        return [
            'total' => User::query()->inCurrentOrganization()->count(),
            'active' => User::query()->inCurrentOrganization()->where('is_active', true)->count(),
            'inactive' => User::query()->inCurrentOrganization()->where('is_active', false)->count(),
            'unverified' => User::query()->inCurrentOrganization()->whereNull('email_verified_at')->count(),
            'new_this_month' => User::query()->inCurrentOrganization()->where('created_at', '>=', now()->startOfMonth())->count(),
            'archived' => User::query()->inCurrentOrganization()->onlyTrashed()->count(),
        ];
    }
}
