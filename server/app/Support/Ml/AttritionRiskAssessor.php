<?php

namespace App\Support\Ml;

use App\Models\AttritionRiskRun;
use App\Models\Employee;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The canonical operation behind the Attrition Risk module: score every active
 * employee through the Random-Forest attrition model and persist the result as a
 * run.
 *
 * It is the single source of truth for "assess attrition risk" — the controller
 * (and any future scheduled job or assistant tool) calls this rather than
 * re-deriving the flow. It gathers the employees, maps them to features
 * ({@see AttritionFeatureMapper}), asks the inference service ({@see MlClient}),
 * then writes an {@see AttritionRiskRun} with one {@see AttritionRiskScore} per
 * employee.
 *
 * Like the performance regressor (and unlike the linear promotion classifier),
 * the attrition model returns no per-instance factor contributions. So the
 * **confidence** is derived here — the share of the model's key inputs grounded
 * in the employee's own recorded HR data (vs. imputed) — and stands in, with the
 * feature snapshot, for the per-employee "why".
 */
class AttritionRiskAssessor
{
    public function __construct(
        private readonly MlClient $ml,
        private readonly AttritionFeatureMapper $mapper,
    ) {}

    /**
     * Run an assessment across all active employees.
     *
     * @throws MlException when there is nobody to assess or the service fails.
     */
    public function run(?User $actor): AttritionRiskRun
    {
        $overtimeSince = now()->subDays(90);
        $trainingSince = now()->subYear();

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->with([
                'department:id,name',
                'performanceEvaluations' => fn ($query) => $query->whereNotNull('overall_score')->latest('id'),
                'promotions' => fn ($query) => $query->orderByDesc('effective_date'),
            ])
            // Recent overtime (minutes) and last-year training count power two of
            // the model's key features without loading the underlying rows.
            ->withSum(
                ['attendanceRecords as recent_overtime_minutes' => fn ($query) => $query->where('work_date', '>=', $overtimeSince)],
                'overtime_minutes',
            )
            ->withCount(
                ['trainingEnrollments as training_last_year_count' => fn ($query) => $query->where('created_at', '>=', $trainingSince)],
            )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        if ($employees->isEmpty()) {
            throw new MlException('There are no active employees to assess.');
        }

        // Build the inference batch, keeping each employee's feature snapshot.
        $snapshots = [];
        $instances = [];

        foreach ($employees as $employee) {
            $features = $this->mapper->features($employee);
            $snapshots[$employee->id] = $features;
            $instances[] = ['ref' => (string) $employee->id, 'features' => $features];
        }

        $response = $this->ml->predict('attrition', $instances);

        /** @var Collection<string, array<string, mixed>> $results */
        $results = collect($response['results'] ?? [])->keyBy('ref');

        $run = DB::transaction(function () use ($employees, $results, $snapshots, $response, $actor): AttritionRiskRun {
            $rows = [];
            $tiers = ['low' => 0, 'medium' => 0, 'high' => 0];
            $scoreSum = 0.0;
            $confidenceSum = 0.0;

            foreach ($employees as $employee) {
                $result = $results->get((string) $employee->id);

                if ($result === null) {
                    continue;
                }

                $features = $snapshots[$employee->id] ?: [];
                $tier = $result['tier'] ?? 'low';
                $confidence = $this->confidence($features);

                $tiers[$tier] = ($tiers[$tier] ?? 0) + 1;
                $scoreSum += (float) ($result['score'] ?? 0);
                $confidenceSum += $confidence;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'probability' => (float) ($result['probability'] ?? 0),
                    'score' => (float) ($result['score'] ?? 0),
                    'tier' => $tier,
                    'confidence' => $confidence,
                    'features' => $features ?: null,
                ];
            }

            $scored = count($rows);

            $run = AttritionRiskRun::create([
                'generated_by' => $actor?->id,
                'status' => 'completed',
                'model_version' => $response['model_version'] ?? null,
                'employees_scored' => $scored,
                'high_count' => $tiers['high'],
                'medium_count' => $tiers['medium'],
                'low_count' => $tiers['low'],
                'average_score' => $scored > 0 ? round($scoreSum / $scored, 2) : null,
                'average_confidence' => $scored > 0 ? round($confidenceSum / $scored, 3) : null,
            ]);

            $run->scores()->createMany($rows);

            return $run;
        });

        ActivityLogger::log(
            event: 'generated',
            description: "Ran an attrition-risk assessment ({$run->employees_scored} employees, {$run->high_count} high risk)",
            subject: $run,
            logName: 'attrition-risk',
        );

        return $run;
    }

    /**
     * Confidence (0–1): the share of the model's key inputs we could ground in
     * this employee's real HR data. The rest are imputed by the pipeline, so a
     * thinner record yields a less certain risk score.
     *
     * @param  array<string, mixed>  $features
     */
    private function confidence(array $features): float
    {
        $present = collect(AttritionFeatureMapper::KEY_FEATURES)
            ->filter(fn (string $key): bool => array_key_exists($key, $features))
            ->count();

        return round($present / count(AttritionFeatureMapper::KEY_FEATURES), 3);
    }
}
