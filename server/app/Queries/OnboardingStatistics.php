<?php

namespace App\Queries;

use App\Models\OnboardingCase;
use App\Models\OnboardingTask;

class OnboardingStatistics
{
    /**
     * Aggregate headline metrics for the onboarding overview.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        $today = now()->toDateString();

        return [
            'active' => OnboardingCase::query()->active()->count(),
            'overdue_tasks' => OnboardingTask::query()->overdue()->onActiveCase()->count(),
            'completing_soon' => OnboardingCase::query()->active()
                ->whereNotNull('target_end_date')
                ->whereDate('target_end_date', '>=', $today)
                ->whereDate('target_end_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
            'completed_this_month' => OnboardingCase::where('status', 'completed')
                ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }
}
