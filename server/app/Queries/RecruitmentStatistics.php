<?php

namespace App\Queries;

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecruitmentPipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            'in_pipeline' => JobApplication::query()->open()->count(),
            'final_stage' => JobApplication::whereIn('recruitment_pipeline_stage_id', $this->finalOpenStageIds())->count(),
            'interviews_upcoming' => Interview::where('result', 'pending')
                ->where('scheduled_at', '>=', now()->startOfDay())
                ->count(),
            'hired_this_month' => JobApplication::whereHas('pipelineStage', fn (Builder $q) => $q->where('kind', 'won'))
                ->where('decided_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }

    /**
     * The last `open` stage of every pipeline in the tenant — the generic
     * equivalent of "offer": whatever a custom pipeline calls the step right
     * before Hire or Reject.
     *
     * @return Collection<int, int>
     */
    private function finalOpenStageIds(): Collection
    {
        return RecruitmentPipelineStage::query()
            ->where('kind', 'open')
            ->get(['id', 'recruitment_pipeline_id', 'position'])
            ->groupBy('recruitment_pipeline_id')
            ->map(fn ($stages) => $stages->sortByDesc('position')->first()?->id)
            ->filter()
            ->values();
    }
}
