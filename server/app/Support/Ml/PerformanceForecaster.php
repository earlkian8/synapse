<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The canonical operation behind the Performance Forecast module: project every
 * active employee's next-period performance rating through the performance model
 * and persist the result as a run.
 *
 * It is the single source of truth for "forecast performance" — the controller
 * (and any future scheduled job or assistant tool) calls this rather than
 * re-deriving the flow. It gathers the employees, maps them to features (reusing
 * {@see PromotionFeatureMapper} — the promotion and performance models share a
 * feature space), asks the inference service ({@see MlClient}), then writes a
 * {@see PerformanceForecastRun} with one {@see PerformanceForecast} per employee.
 *
 * Unlike the promotion classifier, the performance model is a regressor: it
 * returns a predicted rating (0–100), but no probability, tier or factor
 * contributions. So the **confidence** is derived here — the share of the model's
 * key inputs grounded in the employee's own recorded HR data (vs. imputed) — and
 * the **band** is bucketed from the predicted rating.
 */
class PerformanceForecaster
{
    /**
     * The model inputs we can ground in real HR data. Confidence is the share of
     * these present for an employee; the rest are imputed by the pipeline.
     */
    private const KEY_FEATURES = [
        'performance_last_year',
        'performance_two_years_ago',
        'manager_rating',
        'kpi_achievement_percent',
        'years_at_company',
        'years_since_last_promotion',
        'certifications_count',
        'salary',
        'employment_type',
        'department',
    ];

    /** Band cut-offs on the 0–100 rating scale (grounded in the dataset quartiles). */
    private const EXCEEDS_AT = 80.0;

    private const ON_TRACK_AT = 60.0;

    public function __construct(
        private readonly MlClient $ml,
        private readonly PromotionFeatureMapper $mapper,
    ) {}

    /**
     * Run a forecast across all active employees for the next non-closed period.
     *
     * @throws MlException when there is nobody to forecast or the service fails.
     */
    public function run(?User $actor): PerformanceForecastRun
    {
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->with([
                'department:id,name',
                'performanceEvaluations' => fn ($query) => $query
                    ->whereNotNull('overall_score')
                    ->with('period:id,name')
                    ->latest('id'),
                'promotions' => fn ($query) => $query->orderByDesc('effective_date'),
            ])
            ->withCount('certifications')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        if ($employees->isEmpty()) {
            throw new MlException('There are no active employees to forecast.');
        }

        // The period we are forecasting: the soonest cycle that is not yet closed.
        $targetPeriod = EvaluationPeriod::query()
            ->where('status', '!=', 'closed')
            ->orderBy('start_date')
            ->first();

        // Build the inference batch, keeping each employee's feature snapshot and
        // rating history (for the trajectory chart).
        $snapshots = [];
        $histories = [];
        $instances = [];

        foreach ($employees as $employee) {
            $features = $this->mapper->features($employee);
            $snapshots[$employee->id] = $features;
            $histories[$employee->id] = $this->history($employee);
            $instances[] = ['ref' => (string) $employee->id, 'features' => $features];
        }

        $response = $this->ml->predict('performance', $instances);

        /** @var Collection<string, array<string, mixed>> $results */
        $results = collect($response['results'] ?? [])->keyBy('ref');

        $run = DB::transaction(function () use ($employees, $results, $snapshots, $histories, $response, $targetPeriod, $actor): PerformanceForecastRun {
            $rows = [];
            $bands = ['below' => 0, 'on_track' => 0, 'exceeds' => 0];
            $ratingSum = 0.0;
            $confidenceSum = 0.0;

            foreach ($employees as $employee) {
                $result = $results->get((string) $employee->id);

                if ($result === null) {
                    continue;
                }

                $features = $snapshots[$employee->id] ?: [];
                $rating = max(0.0, min(100.0, round((float) ($result['score'] ?? 0), 1)));
                $confidence = $this->confidence($features);
                $band = $this->bandFor($rating);

                $bands[$band]++;
                $ratingSum += $rating;
                $confidenceSum += $confidence;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'predicted_rating' => $rating,
                    'confidence' => $confidence,
                    'band' => $band,
                    'features' => $features ?: null,
                    'history' => $histories[$employee->id] ?: null,
                ];
            }

            $scored = count($rows);

            $run = PerformanceForecastRun::create([
                'generated_by' => $actor?->id,
                'target_period_id' => $targetPeriod?->id,
                'status' => 'completed',
                'model_version' => $response['model_version'] ?? null,
                'employees_scored' => $scored,
                'exceeds_count' => $bands['exceeds'],
                'on_track_count' => $bands['on_track'],
                'below_count' => $bands['below'],
                'average_rating' => $scored > 0 ? round($ratingSum / $scored, 2) : null,
                'average_confidence' => $scored > 0 ? round($confidenceSum / $scored, 3) : null,
            ]);

            $run->forecasts()->createMany($rows);

            return $run;
        });

        ActivityLogger::log(
            event: 'generated',
            description: "Ran a performance forecast ({$run->employees_scored} employees, {$run->exceeds_count} exceeding)",
            subject: $run,
            logName: 'performance-forecast',
        );

        return $run;
    }

    /**
     * Confidence (0–1): the share of the model's key inputs we could ground in
     * this employee's real HR data. The rest are imputed by the pipeline, so a
     * thinner record yields a less certain forecast.
     *
     * @param  array<string, mixed>  $features
     */
    private function confidence(array $features): float
    {
        $present = collect(self::KEY_FEATURES)
            ->filter(fn (string $key): bool => array_key_exists($key, $features))
            ->count();

        return round($present / count(self::KEY_FEATURES), 3);
    }

    /**
     * Bucket a predicted rating into a band.
     */
    private function bandFor(float $rating): string
    {
        return match (true) {
            $rating >= self::EXCEEDS_AT => 'exceeds',
            $rating >= self::ON_TRACK_AT => 'on_track',
            default => 'below',
        };
    }

    /**
     * The employee's recent actual ratings (overall_score 1–5 → 0–100), oldest
     * first, for the trajectory chart. Capped at the six most recent cycles.
     *
     * @return list<array{label: ?string, rating: float}>
     */
    private function history(Employee $employee): array
    {
        if (! $employee->relationLoaded('performanceEvaluations')) {
            return [];
        }

        return $employee->performanceEvaluations
            ->filter(fn (PerformanceEvaluation $e): bool => $e->overall_score !== null)
            ->take(6)
            ->reverse()
            ->map(fn (PerformanceEvaluation $e): array => [
                'label' => $e->relationLoaded('period') && $e->period ? $e->period->name : null,
                'rating' => round((float) $e->overall_score * 20, 1),
            ])
            ->values()
            ->all();
    }
}
