<?php

namespace App\Queries;

use App\Models\ClearanceItem;
use App\Models\OffboardingCase;
use Illuminate\Database\Eloquent\Builder;

class OffboardingStatistics
{
    /**
     * Aggregate headline metrics for the offboarding overview.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        $today = now()->toDateString();

        return [
            'active' => OffboardingCase::whereIn('status', ['initiated', 'clearance'])->count(),
            'flagged_items' => ClearanceItem::where('status', 'flagged')
                ->whereHas('case', fn (Builder $query) => $query->whereIn('status', ['initiated', 'clearance']))
                ->count(),
            'leaving_soon' => OffboardingCase::whereIn('status', ['initiated', 'clearance'])
                ->whereNotNull('last_working_day')
                ->whereDate('last_working_day', '>=', $today)
                ->whereDate('last_working_day', '<=', now()->addDays(14)->toDateString())
                ->count(),
            'completed_this_month' => OffboardingCase::where('status', 'completed')
                ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }
}
