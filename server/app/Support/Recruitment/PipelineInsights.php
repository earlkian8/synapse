<?php

namespace App\Support\Recruitment;

use App\Models\JobApplication;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use Illuminate\Support\Collection;

/**
 * Turns a posting's already-scored applications into pipeline-level decision
 * support: an overall read plus per-stage metrics — average fit, strong
 * matches, candidates ready to advance, stalled cards, and the standout
 * candidate. The recruiter sees a contextual summary for whichever stage
 * they're viewing.
 *
 * It reuses the {@see ApplicantScorer} fit + recommendation the controller has
 * already attached to each application, so no scoring is repeated here. Driven
 * entirely by the posting's own pipeline (its actual stages, in order) rather
 * than a hardcoded list, so it works the same for any custom pipeline.
 */
class PipelineInsights
{
    /**
     * Build the full insights payload for the pipeline board.
     *
     * @param  Collection<int, JobApplication>  $applications  Already scored.
     * @return array<string, mixed>
     */
    public function build(Collection $applications, RecruitmentPipeline $pipeline): array
    {
        $active = $applications->filter(fn (JobApplication $a): bool => $a->pipelineStage->kind === 'open');
        $hired = $applications->filter(fn (JobApplication $a): bool => $a->pipelineStage->kind === 'won');
        $rejected = $applications->filter(fn (JobApplication $a): bool => $a->pipelineStage->kind === 'lost');
        $total = $applications->count();
        $decided = $hired->count() + $rejected->count();

        return [
            'overall' => [
                'total' => $total,
                'active' => $active->count(),
                'avg_fit' => $this->averageFit($active),
                'strong' => $this->strongCount($active),
                'ready' => $this->readyCount($active),
                'stalled' => $this->stalledCount($active),
                'hired' => $hired->count(),
                'rejected' => $rejected->count(),
                'conversion' => $decided > 0 ? (int) round($hired->count() / $decided * 100) : null,
                'top' => $this->topCandidate($active, withStage: true),
            ],
            'stages' => $pipeline->stages
                ->mapWithKeys(fn (RecruitmentPipelineStage $stage): array => [
                    $stage->id => $this->stage(
                        $applications->where('recruitment_pipeline_stage_id', $stage->id),
                        $stage,
                        $pipeline,
                    ),
                ])
                ->all(),
        ];
    }

    /**
     * Metrics for a single stage's applications.
     *
     * @param  Collection<int, JobApplication>  $apps
     * @return array<string, mixed>
     */
    private function stage(Collection $apps, RecruitmentPipelineStage $stage, RecruitmentPipeline $pipeline): array
    {
        $open = $stage->kind === 'open';
        $next = $open ? $pipeline->nextOpenStageAfter($stage) : null;

        return [
            'count' => $apps->count(),
            'avg_fit' => $this->averageFit($apps),
            'strong' => $this->strongCount($apps),
            'ready' => $open ? $this->readyCount($apps) : 0,
            'stalled' => $open ? $this->stalledCount($apps) : 0,
            'next_stage' => $next?->name,
            'top' => $this->topCandidate($apps),
        ];
    }

    /**
     * Average fit across a set of applications, rounded, or null when none carry
     * a score.
     *
     * @param  Collection<int, JobApplication>  $apps
     */
    private function averageFit(Collection $apps): ?int
    {
        $values = $apps
            ->map(fn (JobApplication $a): ?int => $a->fit['value'] ?? null)
            ->filter(fn (?int $v): bool => $v !== null);

        return $values->isNotEmpty() ? (int) round($values->avg()) : null;
    }

    /**
     * How many applications land in the "strong" fit band.
     *
     * @param  Collection<int, JobApplication>  $apps
     */
    private function strongCount(Collection $apps): int
    {
        return $apps->filter(fn (JobApplication $a): bool => ($a->fit['band'] ?? null) === 'strong')->count();
    }

    /**
     * How many applications the scorer flags as ready for their next step (a
     * positive recommendation with an actionable target).
     *
     * @param  Collection<int, JobApplication>  $apps
     */
    private function readyCount(Collection $apps): int
    {
        return $apps->filter(fn (JobApplication $a): bool => ($a->recommendation['tone'] ?? null) === 'positive'
            && ($a->recommendation['action'] ?? null) !== null)->count();
    }

    /**
     * How many open cards have gone quiet — sitting in an open stage past the
     * stall threshold.
     *
     * @param  Collection<int, JobApplication>  $apps
     */
    private function stalledCount(Collection $apps): int
    {
        return $apps->filter(
            fn (JobApplication $a): bool => $a->daysInPipeline() >= JobApplication::STALL_DAYS
        )->count();
    }

    /**
     * The standout candidate in a set — the highest fit, with an optional stage
     * name so the overall banner can point recruiters at the right column.
     *
     * @param  Collection<int, JobApplication>  $apps
     * @return array<string, mixed>|null
     */
    private function topCandidate(Collection $apps, bool $withStage = false): ?array
    {
        $top = $apps
            ->filter(fn (JobApplication $a): bool => ($a->fit['value'] ?? null) !== null)
            ->sortByDesc(fn (JobApplication $a): int => $a->fit['value'])
            ->first();

        if (! $top || ! $top->applicant) {
            return null;
        }

        return array_filter([
            'name' => $top->applicant->full_name,
            'fit' => $top->fit['value'],
            'stage' => $withStage ? $top->pipelineStage->name : null,
        ], fn ($v): bool => $v !== null);
    }
}
