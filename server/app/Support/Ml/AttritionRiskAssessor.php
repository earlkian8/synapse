<?php

namespace App\Support\Ml;

use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\Employee;
use App\Models\LocalModel;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The canonical operation behind the Attrition Risk module: score every active
 * employee through the attrition model and persist the result as a run.
 *
 * It is the single source of truth for "assess attrition risk" — the controller
 * (and any future scheduled job or assistant tool) calls this rather than
 * re-deriving the flow. It gathers the employees with their promotion history and
 * 90-day attendance aggregates, maps them to features
 * ({@see AttritionFeatureMapper}), asks the inference service ({@see MlClient}),
 * then writes an {@see AttritionRiskRun} with one {@see AttritionRiskScore} per
 * employee.
 *
 * The **confidence** is derived here, as for the performance forecast: the share of
 * the model's inputs grounded in the employee's own record rather than imputed. An
 * employee whose attendance is not tracked is scored on five inputs, not eight, and
 * the page says so.
 */
class AttritionRiskAssessor
{
    /** Every input the attrition model takes. Confidence is the share present. */
    public const KEY_FEATURES = [
        'employment_type',
        'tenure_years',
        'monthly_salary',
        'ever_promoted',
        'years_since_promotion',
        'overtime_hours_90d',
        'absences_90d',
        'lates_90d',
    ];

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
        $since = today()->subDays(AttritionFeatureMapper::WINDOW_DAYS)->toDateString();
        $until = today()->toDateString();
        $window = fn ($query) => $query->whereBetween('work_date', [$since, $until]);

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->with(['promotions' => fn ($query) => $query->orderByDesc('effective_date')])
            ->withCount([
                'attendanceRecords as attendance_days_90d' => $window,
                'attendanceRecords as absences_90d' => fn ($query) => $window($query)->where('status', 'absent'),
                'attendanceRecords as lates_90d' => fn ($query) => $window($query)->where('late_minutes', '>', 0),
            ])
            ->withSum(['attendanceRecords as overtime_minutes_90d' => $window], 'overtime_minutes')
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

        // The organisation's own model when it has graduated this surface (ADR 0046),
        // else the general one.
        $local = LocalModel::activeFor('attrition');
        $response = $this->ml->predict('attrition', $instances, $local?->variant());

        /** @var Collection<string, array<string, mixed>> $results */
        $results = collect($response['results'] ?? [])->keyBy('ref');

        $run = DB::transaction(function () use ($employees, $results, $snapshots, $response, $actor, $local): AttritionRiskRun {
            $rows = [];
            $tiers = ['low' => 0, 'medium' => 0, 'high' => 0];
            $scoreSum = 0.0;
            $confidenceSum = 0.0;

            foreach ($employees as $employee) {
                $result = $results->get((string) $employee->id);

                if ($result === null) {
                    continue;
                }

                $tier = in_array($result['tier'] ?? null, AttritionRiskScore::TIERS, true) ? $result['tier'] : 'low';
                $score = max(0.0, min(100.0, round((float) ($result['score'] ?? 0), 2)));
                $features = $snapshots[$employee->id] ?: [];
                $confidence = $this->confidence($features);

                $tiers[$tier]++;
                $scoreSum += $score;
                $confidenceSum += $confidence;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'probability' => (float) ($result['probability'] ?? 0),
                    'score' => $score,
                    'tier' => $tier,
                    'confidence' => $confidence,
                    'factors' => $result['factors'] ?? null,
                    'features' => $features ?: null,
                ];
            }

            $scored = count($rows);

            $run = AttritionRiskRun::create([
                'generated_by' => $actor?->id,
                'status' => 'completed',
                'model_version' => $response['model_version'] ?? null,
                'local_model_id' => $local?->id,
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
     * Confidence (0–1): the share of the model's inputs grounded in this employee's
     * real record. The rest are imputed by the pipeline.
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
}
