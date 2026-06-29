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
            $this->documents($application),
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
     *
     * @param  array{value: int, band: string, breakdown: mixed}  $score
     * @return array{action: string|null, label: string, tone: string, hint: string}
     */
    public function recommendation(JobApplication $application, array $score): array
    {
        $fit = $score['value'];
        $interview = $this->interviewVerdict($application);

        return match ($application->stage) {
            'applied' => $fit >= 55
                ? $this->rec('screening', 'Advance to screening', 'positive', 'Strong on-paper fit — move them forward to review.')
                : $this->rec('screening', 'Screen this candidate', 'neutral', 'Review their profile before deciding.'),
            'screening' => $fit >= 55
                ? $this->rec('interview', 'Schedule an interview', 'positive', 'Looks promising — set up an interview.')
                : $this->rec('reject', 'Consider rejecting', 'caution', 'Below the bar for this role on current signals.'),
            'interview' => match ($interview) {
                'passed' => $this->rec('offer', 'Move to offer', 'positive', 'Cleared interviews — extend an offer.'),
                'failed' => $this->rec('reject', 'Consider rejecting', 'caution', 'Did not pass the interview.'),
                default => $this->rec(null, 'Awaiting interview result', 'neutral', 'Record the interview outcome to proceed.'),
            },
            'offer' => $this->rec('hire', 'Hire candidate', 'positive', 'Ready to convert into an employee.'),
            'hired' => $this->rec(null, 'Hired', 'positive', 'This candidate has joined the workforce.'),
            'rejected' => $this->rec(null, 'Rejected', 'neutral', 'No further action.'),
            default => $this->rec(null, 'Review candidate', 'neutral', ''),
        };
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
        $reached = in_array($application->stage, ['interview', 'offer', 'hired'], true);

        if (! $hasInterviews && ! $reached) {
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
    private function documents(JobApplication $application): array
    {
        $max = self::WEIGHTS['documents'];
        $applicant = $application->applicant;

        $hasResume = (bool) ($applicant?->resume);
        $supporting = (int) ($applicant?->documents_count
            ?? ($applicant?->relationLoaded('documents') ? $applicant->documents->count() : 0));

        $points = ($hasResume ? 6 : 0) + min($supporting, 4);
        $points = min($points, $max);

        $detail = $hasResume
            ? 'Résumé'.($supporting > 0 ? " + {$supporting} doc".($supporting === 1 ? '' : 's') : '')
            : 'No résumé on file';

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
     * @return array{action: string|null, label: string, tone: string, hint: string}
     */
    private function rec(?string $action, string $label, string $tone, string $hint): array
    {
        return ['action' => $action, 'label' => $label, 'tone' => $tone, 'hint' => $hint];
    }
}
