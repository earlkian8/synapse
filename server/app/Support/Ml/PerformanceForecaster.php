<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The canonical operation behind the Performance Forecast module: forecast every
 * active employee's next appraisal through the performance model and persist the
 * result as a run.
 *
 * It is the single source of truth for "forecast performance" — the controller
 * (and any future scheduled job or assistant tool) calls this rather than
 * re-deriving the flow. It gathers the employees, cuts each record at the period
 * being forecast ({@see PerformanceFeatureMapper}), asks the inference service
 * ({@see MlClient}), then writes a {@see PerformanceForecastRun} with one
 * {@see PerformanceForecast} per employee the model forecast.
 *
 * Everything the forecast says comes from the model (ADR 0045): the predicted
 * rating, the range four in five next ratings land in, the band, and the
 * **confidence** — the chance the next rating lands in that band. An employee with
 * no completed appraisal before the period is declined and recorded on the run
 * with the reason, never forecast from a guess.
 */
class PerformanceForecaster
{
    public function __construct(
        private readonly MlClient $ml,
        private readonly PerformanceFeatureMapper $mapper,
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
            ->with(['performanceEvaluations' => fn ($query) => $query->with('period:id,name,start_date,end_date')])
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

        // Build the inference batch from what a forecast of that period may know,
        // keeping each employee's feature snapshot and rating history.
        $windows = [];
        $snapshots = [];
        $instances = [];

        foreach ($employees as $employee) {
            $windows[$employee->id] = $this->mapper->window($employee, $targetPeriod);
            $snapshots[$employee->id] = $this->mapper->features($windows[$employee->id]);
            $instances[] = ['ref' => (string) $employee->id, 'features' => $snapshots[$employee->id]];
        }

        $response = $this->ml->predict('performance', $instances);

        if (! empty($response['warnings'])) {
            Log::warning('Performance model reported a contract mismatch.', ['warnings' => $response['warnings']]);
        }

        /** @var Collection<string, array<string, mixed>> $results */
        $results = collect($response['results'] ?? [])->keyBy('ref');

        $run = DB::transaction(function () use ($employees, $results, $snapshots, $windows, $response, $targetPeriod, $actor): PerformanceForecastRun {
            $rows = [];
            $unassessed = [];
            $bands = ['below' => 0, 'on_track' => 0, 'exceeds' => 0];
            $ratingSum = 0.0;
            $confidenceSum = 0.0;

            foreach ($employees as $employee) {
                $result = $results->get((string) $employee->id);
                $window = $windows[$employee->id];

                if ($result === null || ($result['status'] ?? 'scored') !== 'scored' || ! isset($result['score'])
                    || ! in_array($result['band'] ?? null, PerformanceForecast::BANDS, true)) {
                    $unassessed[] = [
                        'employee_id' => $employee->id,
                        'reason' => $window->reasonUnassessed(AppraisalHistory::of($employee)),
                    ];

                    continue;
                }

                $rating = $this->clamp((float) $result['score']);
                $confidence = round(max(0.0, min(1.0, (float) ($result['confidence'] ?? 0))), 3);
                $interval = $result['interval'] ?? null;

                $bands[$result['band']]++;
                $ratingSum += $rating;
                $confidenceSum += $confidence;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'predicted_rating' => $rating,
                    'predicted_low' => isset($interval['low']) ? $this->clamp((float) $interval['low']) : null,
                    'predicted_high' => isset($interval['high']) ? $this->clamp((float) $interval['high']) : null,
                    'confidence' => $confidence,
                    'band' => $result['band'],
                    'features' => $snapshots[$employee->id] ?: null,
                    'history' => $window->trajectory() ?: null,
                    'warnings' => ($result['warnings'] ?? []) ?: null,
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
                'unassessed' => $unassessed ?: null,
            ]);

            $run->forecasts()->createMany($rows);

            return $run;
        });

        $declined = count($run->unassessed ?? []);

        ActivityLogger::log(
            event: 'generated',
            description: "Ran a performance forecast ({$run->employees_scored} employees, {$run->exceeds_count} exceeding"
                .($declined > 0 ? ", {$declined} not forecast)" : ')'),
            subject: $run,
            logName: 'performance-forecast',
        );

        return $run;
    }

    private function clamp(float $rating): float
    {
        return max(0.0, min(100.0, round($rating, 1)));
    }
}
