<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use Carbon\CarbonInterface;

/**
 * An employee's appraisal record as the performance and promotion models read it
 * (ADR 0045): **completed** appraisals only (submitted or acknowledged — a draft can
 * still change), in the order their review periods **ended**, each as attainment on
 * 0–100 (`overall_percent`, the one figure ADR 0028 makes comparable across
 * appraisal frameworks).
 *
 * Built from the employee's loaded `performanceEvaluations` (with `period`), which
 * {@see PromotionReadinessAssessor} and {@see PerformanceForecaster} eager-load. It
 * also answers why a record cannot be assessed, so HR is told what to do rather
 * than shown a guess.
 */
final class AppraisalHistory
{
    /** Why a record could not be assessed. */
    public const NO_APPRAISAL = 'no_appraisal';

    public const APPRAISAL_IN_PROGRESS = 'appraisal_in_progress';

    public const NONE_BEFORE_PERIOD = 'none_before_period';

    /**
     * @param  list<array{percent: float, label: ?string, ends: ?CarbonInterface}>  $completed  oldest first
     */
    private function __construct(
        public readonly array $completed,
        private readonly int $unfinished,
    ) {}

    public static function of(Employee $employee): self
    {
        $evaluations = $employee->relationLoaded('performanceEvaluations')
            ? $employee->performanceEvaluations
            : collect();

        $completed = $evaluations
            ->filter(fn (PerformanceEvaluation $e): bool => in_array($e->status, ['submitted', 'acknowledged'], true)
                && $e->overall_percent !== null)
            ->map(fn (PerformanceEvaluation $e): array => [
                'percent' => round((float) $e->overall_percent, 2),
                'label' => $e->relationLoaded('period') ? $e->period?->name : null,
                'ends' => self::endOf($e),
                'id' => $e->id,
            ])
            ->sort(fn (array $a, array $b): int => [$a['ends']?->getTimestamp() ?? PHP_INT_MAX, $a['id']]
                <=> [$b['ends']?->getTimestamp() ?? PHP_INT_MAX, $b['id']])
            ->map(fn (array $row): array => ['percent' => $row['percent'], 'label' => $row['label'], 'ends' => $row['ends']])
            ->values()
            ->all();

        return new self($completed, $evaluations->count() - count($completed));
    }

    /**
     * Only the appraisals whose period ended before `$period` began — what a
     * forecast of `$period` can know. With no period, the whole record.
     */
    public function before(?EvaluationPeriod $period): self
    {
        $start = $period?->start_date;

        if ($start === null) {
            return $this;
        }

        return new self(
            array_values(array_filter($this->completed, fn (array $row): bool => $row['ends'] !== null && $row['ends']->lt($start))),
            $this->unfinished,
        );
    }

    /** @return array{percent: float, label: ?string, ends: ?CarbonInterface}|null */
    public function latest(): ?array
    {
        return $this->completed === [] ? null : $this->completed[array_key_last($this->completed)];
    }

    /** @return array{percent: float, label: ?string, ends: ?CarbonInterface}|null */
    public function previous(): ?array
    {
        return count($this->completed) < 2 ? null : $this->completed[count($this->completed) - 2];
    }

    /**
     * The most recent `$limit` appraisals, oldest first, for a trajectory chart.
     *
     * @return list<array{label: ?string, rating: float}>
     */
    public function trajectory(int $limit = 6): array
    {
        return array_map(
            fn (array $row): array => ['label' => $row['label'], 'rating' => $row['percent']],
            array_slice($this->completed, -$limit),
        );
    }

    /**
     * Why this record has nothing to assess, given the full record it was cut from.
     */
    public function reasonUnassessed(self $whole): string
    {
        return match (true) {
            $whole->completed !== [] => self::NONE_BEFORE_PERIOD,
            $whole->unfinished > 0 => self::APPRAISAL_IN_PROGRESS,
            default => self::NO_APPRAISAL,
        };
    }

    /**
     * When an appraisal's period ended, or — for one without a period — when it was
     * submitted.
     */
    private static function endOf(PerformanceEvaluation $evaluation): ?CarbonInterface
    {
        $period = $evaluation->relationLoaded('period') ? $evaluation->period : null;

        return $period?->end_date ?? $evaluation->submitted_at ?? $evaluation->created_at;
    }
}
