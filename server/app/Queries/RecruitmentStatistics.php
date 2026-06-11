<?php

namespace App\Queries;

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;

class RecruitmentStatistics
{
    /**
     * Aggregate headline metrics for the recruitment dashboard.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'open_postings' => JobPosting::where('status', 'open')->count(),
            'total_applicants' => Applicant::count(),
            'in_pipeline' => JobApplication::whereNotIn('stage', ['hired', 'rejected'])->count(),
            'offers' => JobApplication::where('stage', 'offer')->count(),
            'interviews_upcoming' => Interview::where('result', 'pending')
                ->where('scheduled_at', '>=', now()->startOfDay())
                ->count(),
            'hired_this_month' => JobApplication::where('stage', 'hired')
                ->where('decided_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }
}
