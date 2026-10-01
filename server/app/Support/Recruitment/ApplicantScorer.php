<?php

namespace App\Support\Recruitment;

use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Support\Str;

/**
 * The single source of truth for the recruitment **fit score** and the decision
 * support it powers. Pure, deterministic math — given an application (and its
 * posting's optional screening criteria) it returns a 0–100 fit score with a
 * transparent breakdown, plus the recommended next pipeline step. Controllers and
 * the assistant reuse it rather than re-deriving the formula.
 *
 * The model is position-aware but config-free: when a posting leaves its criteria
 * blank, the relevant components simply don't apply and the score is normalised
 * over the components that do — so ranking works the moment a candidate applies
 * and grows sharper as recruiters rate, interview, and set criteria.
 */
class ApplicantScorer
{
    /** Maximum points each component can contribute, before normalisation. */
    private const WEIGHTS = [
        'rating' => 30,
        'experience' => 25,
        'skills' => 20,
        'interview' => 15,
        'documents' => 10,
    ];

    /** Years of experience that earn full marks when a posting sets no minimum. */
    private const EXPERIENCE_RUNWAY = 8;

    /**
     * Score a single application against its posting's criteria.
     *
     * @return array{
     *     value: int,
     *     band: string,
     *     breakdown: list<array{key: string, label: string, points: int, max: int, detail: string}>
     * }
     */
    public function score(JobApplication $application, ?JobPosting $posting = null): array
    {
        $posting ??= $application->relationLoaded('jobPosting') ? $application->jobPosting : null;

        $components = array_filter([
            $this->rating($application),
            $this->experience($application, $posting),
            $this->skills($application, $posting),
            $this->interview($application),
            $this->documents($application, $posting),
        ]);

        $earned = array_sum(array_column($components, 'points'));
        $available = array_sum(array_column($components, 'max'));

        $value = $available > 0 ? (int) round($earned / $available * 100) : 0;
        $value = max(0, min(100, $value));

        return [
            'value' => $value,
            'band' => $this->band($value),
            'breakdown' => array_values($components),
        ];
    }

    /**
     * The recommended next step for HR, given the application and its fit score.
     * Driven by the stage's `kind` and its position in the posting's own pipeline
     * — never by a hardcoded stage name — so this works the same for any custom
     * pipeline. `action` is a verb (`advance`/`hire`/`reject`/null); `stage_id`
     * only carries a value when `action` is `advance`, naming exactly which stage.
     *
     * @param  array{value: int, band: string, breakdown: mixed}  $score
     * @return array{action: string|null, stage_id: int|null, label: string, tone: string, hint: string}
     */
    public function recommendation(JobApplication $application, array $score): array
    {
        $stage = $application->pipelineStage;
        $pipeline = $application->jobPosting->pipeline;
        $fit = $score['value'];
        $interview = $this->interviewVerdict($application);

        if ($stage->kind === 'won') {
            return $this->rec(null, null, 'Hired', 'positive', 'This candidate has joined the workforce.');
        }

        if ($stage->kind === 'lost') {
            return $this->rec(null, null, 'Rejected', 'neutral', 'No further action.');
        }

        if ($interview === 'failed') {
            return $this->rec('reject', null, 'Consider rejecting', 'caution', 'Did not pass the interview.');
        }

        $isEntryStage = $pipeline->entryStage()?->id === $stage->id;
        $isLastOpenStage = $pipeline->isLastOpenStage($stage);
        $hasInterviews = $application->relationLoaded('interviews') && $application->interviews->isNotEmpty();

        if (! $isEntryStage && $interview === 'pending' && $hasInterviews) {
            return $this->rec(null, null, 'Awaiting interview result', 'neutral', 'Record the interview outcome to proceed.');
        }

        if ($isLastOpenStage) {
            return $fit >= 55
                ? $this->rec('hire', null, 'Hire candidate', 'positive', 'Ready to convert into an employee.')
                : $this->rec('reject', null, 'Consider rejecting', 'caution', 'Below the bar for this role on current signals.');
        }

        $next = $pipeline->nextOpenStageAfter($stage);

        if ($isEntryStage) {
            return $fit >= 55
                ? $this->rec('advance', $next->id, "Advance to {$next->name}", 'positive', 'Strong on-paper fit — move them forward to review.')
                : $this->rec('advance', $next->id, 'Screen this candidate', 'neutral', 'Review their profile before deciding.');
        }

        return $fit >= 55
            ? $this->rec('advance', $next->id, "Advance to {$next->name}", 'positive', 'Strong on-paper fit — move them forward to review.')
            : $this->rec('reject', null, 'Consider rejecting', 'caution', 'Below the bar for this role on current signals.');
    }

    // ── Components ───────────────────────────────────────────────────────────

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}
     */
    private function rating(JobApplication $application): array
    {
        $max = self::WEIGHTS['rating'];
        $rating = $application->rating;
        $points = $rating ? (int) round($rating / 5 * $max) : 0;

        return $this->component('rating', 'Recruiter rating', $points, $max,
            $rating ? "{$rating}/5 stars" : 'Not yet rated');
    }

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}|null
     */
    private function experience(JobApplication $application, ?JobPosting $posting): ?array
    {
        $required = $posting?->min_years_experience;
        $years = $application->applicant?->years_experience;

        // Neither a requirement nor a stated value — nothing to assess.
        if ($required === null && $years === null) {
            return null;
        }

        $max = self::WEIGHTS['experience'];
        $years ??= 0;

        if ($required !== null && $required > 0) {
            $ratio = min($years / $required, 1);
            $detail = "{$years} yr".($years === 1 ? '' : 's')." (needs {$required})";
        } else {
            $ratio = min($years / self::EXPERIENCE_RUNWAY, 1);
            $detail = $years > 0 ? "{$years} yr".($years === 1 ? '' : 's').' experience' : 'No experience listed';
        }

        return $this->component('experience', 'Experience', (int) round($ratio * $max), $max, $detail);
    }

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}|null
     */
    private function skills(JobApplication $application, ?JobPosting $posting): ?array
    {
        $required = array_values(array_filter(array_map('trim', $posting?->skills ?? [])));

        if ($required === []) {
            return null;
        }

        $haystack = Str::lower(implode(' ', array_filter([
            $application->applicant?->headline,
            $application->applicant?->notes,
            $application->cover_note,
        ])));

        $matched = 0;
        foreach ($required as $skill) {
            if ($skill !== '' && str_contains($haystack, Str::lower($skill))) {
                $matched++;
            }
        }

        $total = count($required);
        $max = self::WEIGHTS['skills'];

        return $this->component('skills', 'Skill match', (int) round($matched / $total * $max), $max,
            "{$matched}/{$total} skills matched");
    }

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}|null
     */
    private function interview(JobApplication $application): ?array
    {
        $hasInterviews = $application->relationLoaded('interviews') && $application->interviews->isNotEmpty();

        if (! $hasInterviews) {
            return null;
        }

        $max = self::WEIGHTS['interview'];

        [$points, $detail] = match ($this->interviewVerdict($application)) {
            'passed' => [$max, 'Passed interview'],
            'failed' => [0, 'Did not pass'],
            default => [(int) round($max * 0.5), 'Interview pending'],
        };

        return $this->component('interview', 'Interview', $points, $max, $detail);
    }

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}
     */
    private function documents(JobApplication $application, ?JobPosting $posting): array
    {
        $max = self::WEIGHTS['documents'];
        $applicant = $application->applicant;

        // A posting that doesn't ask for a résumé shouldn't penalise a candidate
        // for not having one — that portion counts as satisfied either way.
        $resumeRequired = $posting?->requires_resume ?? true;
        $hasResume = ! $resumeRequired || (bool) ($applicant?->resume);

        $supporting = (int) ($applicant?->documents_count
            ?? ($applicant?->relationLoaded('documents') ? $applicant->documents->count() : 0));

        $points = ($hasResume ? 6 : 0) + min($supporting, 4);
        $points = min($points, $max);

        $detail = match (true) {
            (bool) ($applicant?->resume) => 'Résumé'.($supporting > 0 ? " + {$supporting} doc".($supporting === 1 ? '' : 's') : ''),
            ! $resumeRequired => 'No résumé required'.($supporting > 0 ? " · {$supporting} doc".($supporting === 1 ? '' : 's') : ''),
            default => 'No résumé on file',
        };

        return $this->component('documents', 'Documents', $points, $max, $detail);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The overall interview verdict: a single 'passed'/'failed' once any
     * interview has a result, otherwise 'pending'. A pass anywhere wins; a fail
     * only counts when nothing has passed.
     */
    private function interviewVerdict(JobApplication $application): string
    {
        if (! $application->relationLoaded('interviews')) {
            return 'pending';
        }

        $results = $application->interviews->pluck('result');

        if ($results->contains('passed')) {
            return 'passed';
        }

        if ($results->contains('failed')) {
            return 'failed';
        }

        return 'pending';
    }

    private function band(int $value): string
    {
        return match (true) {
            $value >= 75 => 'strong',
            $value >= 55 => 'promising',
            $value >= 35 => 'fair',
            default => 'weak',
        };
    }

    /**
     * @return array{key: string, label: string, points: int, max: int, detail: string}
     */
    private function component(string $key, string $label, int $points, int $max, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'points' => $points, 'max' => $max, 'detail' => $detail];
    }

    /**
     * @return array{action: string|null, stage_id: int|null, label: string, tone: string, hint: string}
     */
    private function rec(?string $action, ?int $stageId, string $label, string $tone, string $hint): array
    {
        return ['action' => $action, 'stage_id' => $stageId, 'label' => $label, 'tone' => $tone, 'hint' => $hint];
    }
}
