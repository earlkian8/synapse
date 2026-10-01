<?php

namespace App\Queries;

use App\Models\LeaveRequest;

class LeaveStatistics
{
    /**
     * Headline metrics for the leave inbox.
     *
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        $today = now()->toDateString();

        return [
            'pending' => LeaveRequest::where('status', 'pending')->count(),
            'on_leave_today' => LeaveRequest::where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),
            'upcoming' => LeaveRequest::where('status', 'approved')
                ->whereDate('start_date', '>', $today)
                ->count(),
            'days_this_month' => (float) LeaveRequest::where('status', 'approved')
                ->whereYear('start_date', now()->year)
                ->whereMonth('start_date', now()->month)
                ->sum('days'),
        ];
    }
}
