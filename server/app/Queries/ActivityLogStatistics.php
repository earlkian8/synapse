<?php

namespace App\Queries;

use App\Models\ActivityLog;

class ActivityLogStatistics
{
    /**
     * Aggregate headline metrics for the activity log dashboard.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'total' => ActivityLog::count(),
            'today' => ActivityLog::where('created_at', '>=', now()->startOfDay())->count(),
            'this_week' => ActivityLog::where('created_at', '>=', now()->startOfWeek())->count(),
            'this_month' => ActivityLog::where('created_at', '>=', now()->startOfMonth())->count(),
            'creates' => ActivityLog::where('event', 'created')->count(),
            'deletions' => ActivityLog::whereIn('event', ['deleted', 'archived'])->count(),
        ];
    }
}
