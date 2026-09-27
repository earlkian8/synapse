<?php

namespace App\Queries;

use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class OnboardingStatistics
{
    /**
     * Aggregate headline metrics for the onboarding overview — across every case,
     * or only those `$scope` narrows the case query to (one program's).
     *
     * @param  (Closure(Builder<OnboardingCase>): void)|null  $scope
     * @return array<string, int>
     */
    public function toArray(?Closure $scope = null): array
    {
        $today = now()->toDateString();
        $cases = fn (): Builder => OnboardingCase::query()->when($scope !== null, $scope);

        return [
            'active' => $cases()->active()->count(),
            'overdue_tasks' => OnboardingTask::query()->overdue()->onActiveCase()
                ->when($scope !== null, fn (Builder $query) => $query->whereHas('case', $scope))
                ->count(),
            'completing_soon' => $cases()->active()
                ->whereNotNull('target_end_date')
                ->whereDate('target_end_date', '>=', $today)
                ->whereDate('target_end_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
            'completed_this_month' => $cases()->where('status', 'completed')
                ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }
}
