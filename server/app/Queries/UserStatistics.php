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
        return [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'unverified' => User::whereNull('email_verified_at')->count(),
            'new_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'archived' => User::onlyTrashed()->count(),
        ];
    }
}
