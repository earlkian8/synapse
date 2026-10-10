<?php

namespace App\Support\Performance;

use App\Models\AppraisalReview;
use App\Models\AppraisalReviewScore;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceScore;
use Illuminate\Support\Collection;

/**
 * What the reviews of one appraisal add up to, as the people running it may read
 * them (ADR 0072).
 *
 * **Self and manager answers are attributed** — one person each, and both know
 * their answer is read. **Peers and direct reports are pooled**: their ratings
 * are averaged and their words listed without names, and a pool is only shown
 * once at least {@see POOL_MINIMUM} of them have answered, so no single answer
 * can be singled out. The employee never sees this read; they see their own
 * self-review.
 *
 * Every rating is read on the appraisal line's own frozen scale, so a pooled
 * average of "Proficient" and "Advanced" is reported in that scale's terms.
 */
class FeedbackSummary
{
    /** How many in a pool must answer before the pool is shown. */
    public const POOL_MINIMUM = 2;

    /** Relationships whose answers are pooled rather than attributed. */
    public const POOLED = ['peer', 'direct_report'];

    /** The columns of the comparison, in reading order. */
    private const ORDER = ['self', 'manager', 'peer', 'direct_report'];

    /**
     * The whole read: who was asked and where each request stands, the
     * comparison by criterion, and the written answers.
     *
     * @return array{
     *     requests: list<array<string, mixed>>,
     *     counts: array{asked: int, submitted: int, pending: int},
     *     columns: list<array{key: string, label: string, answered: int, asked: int, shown: bool}>,
     *     lines: list<array{id: int, values: array<string, array{score: float, formatted: string, fraction: float|null, count: int}|null>, remarks: list<array{relationship: string, by: string|null, text: string}>}>,
     *     comments: list<array{relationship: string, by: string|null, strengths: string|null, improvements: string|null}>
     * }
     */
    public function for(PerformanceEvaluation $evaluation): array
    {
        $reviews = $evaluation->reviews()
            ->with(['reviewer:id,first_name,middle_name,last_name,suffix,photo,user_id', 'scores'])
            ->get()
            ->sortBy(fn (AppraisalReview $r): array => [array_search($r->relationship, self::ORDER, true), $r->id])
            ->values();

        $live = $reviews->where('status', '!=', 'cancelled');
        $submitted = $reviews->where('status', 'submitted');

        $columns = collect(self::ORDER)
            ->map(function (string $key) use ($live, $submitted): ?array {
                $asked = $live->where('relationship', $key)->count();

                if ($asked === 0) {
                    return null;
                }

                $answered = $submitted->where('relationship', $key)->count();

                return [
                    'key' => $key,
                    'label' => $this->columnLabel($key),
                    'answered' => $answered,
                    'asked' => $asked,
                    'shown' => $answered > 0 && (! in_array($key, self::POOLED, true) || $answered >= self::POOL_MINIMUM),
                ];
            })
            ->filter()
            ->values();

        $shown = $columns->where('shown', true)->pluck('key')->all();
        $visible = $submitted->filter(fn (AppraisalReview $r): bool => in_array($r->relationship, $shown, true));

        return [
            'requests' => $reviews->map(fn (AppraisalReview $r): array => $this->request($r))->all(),
            'counts' => [
                'asked' => $live->count(),
                'submitted' => $submitted->count(),
                'pending' => $reviews->where('status', 'pending')->count(),
            ],
            'columns' => $columns->all(),
            'lines' => $this->lines($evaluation, $visible),
            'comments' => $visible
                ->filter(fn (AppraisalReview $r): bool => $r->strengths !== null || $r->improvements !== null)
                ->map(fn (AppraisalReview $r): array => [
                    'relationship' => $r->relationship,
                    'by' => $this->attribution($r),
                    'strengths' => $r->strengths,
                    'improvements' => $r->improvements,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The comparison, line by line: for each shown relationship, the (average)
     * rating on the line's own scale, and the evidence written against it.
     *
     * @param  Collection<int, AppraisalReview>  $visible
     * @return list<array<string, mixed>>
     */
    private function lines(PerformanceEvaluation $evaluation, Collection $visible): array
    {
        $byRelationship = $visible->groupBy('relationship');

        return $evaluation->scorecard()->get()
            ->map(function (PerformanceScore $line) use ($byRelationship): array {
                $values = [];
                $remarks = [];

                foreach ($byRelationship as $relationship => $group) {
                    $answers = $group
                        ->map(fn (AppraisalReview $r): ?AppraisalReviewScore => $r->scores->firstWhere('performance_score_id', $line->id))
                        ->filter();

                    $rated = $answers->filter(fn (AppraisalReviewScore $s): bool => $s->score !== null);

                    $values[$relationship] = $rated->isEmpty() ? null : $this->value($line, (float) $rated->avg(fn (AppraisalReviewScore $s): float => (float) $s->score), $rated->count());

                    foreach ($answers as $answer) {
                        if (trim((string) $answer->remarks) !== '') {
                            $remarks[] = [
                                'relationship' => (string) $relationship,
                                'by' => $this->attribution($group->firstWhere('id', $answer->appraisal_review_id)),
                                'text' => (string) $answer->remarks,
                            ];
                        }
                    }
                }

                return ['id' => $line->id, 'values' => $values, 'remarks' => $remarks];
            })
            ->values()
            ->all();
    }

    /**
     * A rating (or a pool's average) in the line's own terms. An average that
     * lands between two named levels is shown as the number, since no level is
     * honestly its name.
     *
     * @return array{score: float, formatted: string, fraction: float|null, count: int}
     */
    private function value(PerformanceScore $line, float $score, int $count): array
    {
        $scale = $line->scale();
        $score = round($score, 2);

        return [
            'score' => $score,
            'formatted' => RatingScales::format($score, $scale),
            'fraction' => RatingScales::fraction($score, $scale['min'], $scale['max']),
            'count' => $count,
        ];
    }

    /**
     * One request as HR sees it in the list of who was asked.
     *
     * @return array<string, mixed>
     */
    private function request(AppraisalReview $review): array
    {
        return [
            'id' => $review->id,
            'hashid' => $review->hashid,
            'relationship' => $review->relationship,
            'status' => $review->status,
            'due_on' => $review->due_on?->toDateString(),
            'overdue' => $review->isOverdue(),
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'declined_at' => $review->declined_at?->toIso8601String(),
            'decline_reason' => $review->decline_reason,
            'reminded_at' => $review->reminded_at?->toIso8601String(),
            'can_remind' => $review->isPending()
                && ($review->reminded_at === null || $review->reminded_at->lt(now()->subDay())),
            'reviewer' => $review->reviewer ? [
                'id' => $review->reviewer->id,
                'name' => $review->reviewer->full_name,
                'initials' => $review->reviewer->initials(),
                'photo' => $review->reviewer->photo_url,
            ] : null,
        ];
    }

    /**
     * Whose words these are — named for self and manager, never for a pool.
     */
    private function attribution(?AppraisalReview $review): ?string
    {
        if ($review === null || in_array($review->relationship, self::POOLED, true)) {
            return null;
        }

        return $review->reviewer?->full_name;
    }

    private function columnLabel(string $key): string
    {
        return match ($key) {
            'self' => 'Self',
            'manager' => 'Manager',
            'peer' => 'Peers',
            default => 'Direct reports',
        };
    }
}
