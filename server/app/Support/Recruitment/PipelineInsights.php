<?php

namespace App\Support\Recruitment;

use App\Models\JobApplication;
use Illuminate\Support\Collection;

/**
 * Turns a posting's already-scored applications into pipeline-level decision
 * support: an overall read plus per-stage metrics — average fit, strong
 * matches, candidates ready to advance, stalled cards, and the standout
 * candidate. The recruiter sees a contextual summary for whichever stage tab
 * they're viewing.
 *
 * It reuses the {@see ApplicantScorer} fit + recommendation the controller has
 * already attached to each application, so no scoring is repeated here.
 */
class PipelineInsights
{
    /** A card counts as "stalled" once it sits in an open stage this many days. */
    private const STALL_DAYS = 14;

    /** Non-terminal stages a candidate can still advance through. */
    private const OPEN_STAGES = ['applied', 'screening', 'interview', 'offer'];

    /** The stage each open stage advances into (for "ready to advance" copy). */
    private const NEXT_STAGE = [
        'applied' => 'screening',
        'screening' => 'interview',
        'interview' => 'offer',
        'offer' => 'hired',
    ];

    /**
     * Build the full insights payload for the pipeline board.
     *
     * @param  Collection<int, JobApplication>  $applications  Already scored.
     * @return array<string, mixed>
     */
    public function build($applications): array
    {
        $active = $applications->filter(
            fn (JobApplication $a): bool => in_array($a->stage, self::OPEN_STAGES, true)
        );

        $hired = $applications->where('stage', 'hired');
        $rejected = $applications->where('stage', 'rejected');
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
            'stages' => collect(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'])
                ->mapWithKeys(fn (string $stage): array => [
                    $stage => $this->stage($applications->where('stage', $stage), $stage),
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
    private function stage($apps, string $stage): array
    {
        $open = in_array($stage, self::OPEN_STAGES, true);

        return [
            'count' => $apps->count(),
            'avg_fit' => $this->averageFit($apps),
            'strong' => $this->strongCount($apps),
            'ready' => $open ? $this->readyCount($apps) : 0,
            'stalled' => $open ? $this->stalledCount($apps) : 0,
            'next_stage' => self::NEXT_STAGE[$stage] ?? null,
            'top' => $this->topCandidate($apps),
        ];
    }

    /**
     * Average fit across a set of applications, rounded, or null when none carry
     * a score.
     *
     * @param  Collection<int, JobApplication>  $apps
     */
    private function averageFit($apps): ?int
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
    private function strongCount($apps): int
    {
        return $apps->filter(fn (JobApplication $a): bool => ($a->fit['band'] ?? null) === 'strong')->count();
    }

    /**
     * How many applications the scorer flags as ready for their next step (a
     * positive recommendation with an actionable target stage).
     *
     * @param  Collection<int, JobApplication>  $apps
     */
    private function readyCount($apps): int
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
    private function stalledCount($apps): int
    {
        return $apps->filter(function (JobApplication $a): bool {
            $days = $a->applied_at ? (int) $a->applied_at->diffInDays() : 0;

            return $days >= self::STALL_DAYS;
        })->count();
    }

    /**
     * The standout candidate in a set — the highest fit, with an optional stage
     * so the overall banner can point recruiters at the right column.
     *
     * @param  Collection<int, JobApplication>  $apps
     * @return array<string, mixed>|null
     */
    private function topCandidate($apps, bool $withStage = false): ?array
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
            'stage' => $withStage ? $top->stage : null,
        ], fn ($v): bool => $v !== null);
    }
}
