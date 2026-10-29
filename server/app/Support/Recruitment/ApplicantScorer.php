<?php

namespace App\Support\Recruitment;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Support\Recruitment\Skills\CandidateEvidence;
use App\Support\Recruitment\Skills\SkillMatcher;

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
 *
 * Skills are matched by meaning, against the résumé as well as the profile
 * ({@see SkillMatcher}, ADR 0097): TypeScript shows JavaScript, "Java" is not
 * found in "JavaScript", and a must-have counts twice a nice-to-have. A
 * candidate who left their experience blank is credited with what their
 * résumé's dates show ({@see ResumeExperience}).
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

    /** A must-have skill counts twice a nice-to-have. */
    private const TIER_WEIGHTS = ['must' => 2, 'nice' => 1];

    private readonly SkillMatcher $matcher;

    private readonly ResumeExperience $resumeExperience;

    public function __construct(?SkillMatcher $matcher = null, ?ResumeExperience $resumeExperience = null)
    {
        $this->matcher = $matcher ?? SkillMatcher::make();
        $this->resumeExperience = $resumeExperience ?? new ResumeExperience;
    }

    /**
     * Score a single application against its posting's criteria.
     *
     * The `skills` component also lists how each skill was shown (`skills`) and
     * whether the résumé could be read (`resume`); the `experience` component
     * says when its years were `estimated` from the résumé.
     *
     * @return array{
     *     value: int,
     *     band: string,
     *     breakdown: list<array<string, mixed>>
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
     * The score without each skill's reading — what a list of many candidates
     * needs; the candidate's own view fetches the full score.
     *
     * @param  array{value: int, band: string, breakdown: list<array<string, mixed>>}  $score
     * @return array{value: int, band: string, breakdown: list<array<string, mixed>>}
     */
    public static function withoutEvidence(array $score): array
    {
        $score['breakdown'] = array_map(fn (array $item): array => array_diff_key($item, ['skills' => true]), $score['breakdown']);

        return $score;
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
     * @return array<string, mixed>|null
     */
    private function experience(JobApplication $application, ?JobPosting $posting): ?array
    {
        $required = $posting?->min_years_experience;
        $years = $application->applicant?->years_experience;
        $estimated = false;

        // Left blank: what the résumé's dates show, if it shows anything (ADR 0097).
        if ($years === null && filled($application->applicant?->resume_text)) {
            $estimate = $this->resumeExperience->estimate($application->applicant->resume_text);
            $years = $estimate['years'] ?? null;
            $estimated = $estimate !== null;
        }

        // Neither a requirement nor a stated value — nothing to assess.
        if ($required === null && $years === null) {
            return null;
        }

        $max = self::WEIGHTS['experience'];
        $years ??= 0;

        // Half years count (ADR 0091): 1.5 of a required 2 is three quarters.
        $years = (float) $years;
        $plural = fn (float $value): string => ($estimated ? '≈' : '').$value.' yr'.($value === 1.0 ? '' : 's').($estimated ? ' from résumé' : '');

        if ($required !== null && $required > 0) {
            $ratio = min($years / $required, 1);
            $detail = $plural($years)." (needs {$required})";
        } else {
            $ratio = min($years / self::EXPERIENCE_RUNWAY, 1);
            $detail = $years > 0 ? $plural($years).($estimated ? '' : ' experience') : 'No experience listed';
        }

        return [
            ...$this->component('experience', 'Experience', (int) round($ratio * $max), $max, $detail),
            'years' => $years,
            'estimated' => $estimated,
        ];
    }

    /**
     * Must-haves and nice-to-haves matched by meaning (ADR 0097): each earns
     * its credit (1 shown or proven, less when only something related is), a
     * must-have weighing twice a nice-to-have.
     *
     * @return array<string, mixed>|null
     */
    private function skills(JobApplication $application, ?JobPosting $posting): ?array
    {
        $must = self::skillList($posting?->skills);
        $nice = array_values(array_udiff(self::skillList($posting?->preferred_skills), $must, 'strcasecmp'));

        if ($must === [] && $nice === []) {
            return null;
        }

        $matches = $this->matcher->match($must, $nice, CandidateEvidence::forApplication($application));

        $earned = 0.0;
        $possible = 0;

        foreach ($matches as $match) {
            $earned += $match['credit'] * self::TIER_WEIGHTS[$match['tier']];
            $possible += self::TIER_WEIGHTS[$match['tier']];
        }

        $max = self::WEIGHTS['skills'];

        return [
            ...$this->component('skills', 'Skill match', (int) round($earned / $possible * $max), $max, $this->skillDetail($matches)),
            'skills' => $matches,
            'resume' => $this->resumeState($application->applicant),
        ];
    }

    /**
     * A posting's skill list, trimmed and without repeats.
     *
     * @return list<string>
     */
    public static function skillList(mixed $skills): array
    {
        $list = [];

        foreach (is_array($skills) ? $skills : [] as $skill) {
            $skill = trim((string) $skill);

            if ($skill !== '') {
                $list[mb_strtolower($skill)] ??= $skill;
            }
        }

        return array_values($list);
    }

    /**
     * "3/4 skills matched", or with nice-to-haves "3/4 must-haves · 1/2
     * nice-to-haves"; partly-shown skills are counted after a comma.
     *
     * @param  list<array{tier: string, credit: float}>  $matches
     */
    private function skillDetail(array $matches): string
    {
        $tally = function (string $tier) use ($matches): array {
            $ofTier = array_filter($matches, fn (array $match): bool => $match['tier'] === $tier);

            return [
                count(array_filter($ofTier, fn (array $match): bool => $match['credit'] >= 1)),
                count($ofTier),
            ];
        };

        [$mustShown, $mustTotal] = $tally('must');
        [$niceShown, $niceTotal] = $tally('nice');
        $partly = count(array_filter($matches, fn (array $match): bool => $match['credit'] > 0 && $match['credit'] < 1));

        $detail = match (true) {
            $niceTotal === 0 => "{$mustShown}/{$mustTotal} skills matched",
            $mustTotal === 0 => "{$niceShown}/{$niceTotal} nice-to-haves",
            default => "{$mustShown}/{$mustTotal} must-haves · {$niceShown}/{$niceTotal} nice-to-haves",
        };

        return $detail.($partly > 0 ? ", {$partly} related" : '');
    }

    /**
     * Whether the skills could be read from a résumé: `read`, `pending`,
     * `unreadable` (a scan without OCR, an unusual format), or `none`.
     */
    private function resumeState(?Applicant $applicant): string
    {
        if (blank($applicant?->resume)) {
            return 'none';
        }

        return $applicant->resume_read_status ?? Applicant::READ_PENDING;
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
