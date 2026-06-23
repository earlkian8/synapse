<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The canonical operation behind the Promotion Readiness module: score every
 * active employee through the promotion model and persist the result as a run.
 *
 * It is the single source of truth for "assess promotion readiness" — the
 * controller (and any future scheduled job or assistant tool) calls this rather
 * than re-deriving the flow. It gathers the employees, maps them to features
 * ({@see PromotionFeatureMapper}), asks the inference service ({@see MlClient}),
 * then writes a {@see PromotionReadinessRun} with one
 * {@see PromotionReadinessScore} per employee.
 */
class PromotionReadinessAssessor
{
    public function __construct(
        private readonly MlClient $ml,
        private readonly PromotionFeatureMapper $mapper,
    ) {}

    /**
     * Run an assessment across all active employees.
     *
     * @throws MlException when there is nobody to assess or the service fails.
     */
    public function run(?User $actor): PromotionReadinessRun
    {
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->with([
                'department:id,name',
                'performanceEvaluations' => fn ($query) => $query->whereNotNull('overall_score')->latest('id'),
                'promotions' => fn ($query) => $query->orderByDesc('effective_date'),
            ])
            ->withCount('certifications')
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

        $response = $this->ml->predict('promotion', $instances);

        /** @var Collection<string, array<string, mixed>> $results */
        $results = collect($response['results'] ?? [])->keyBy('ref');

        $run = DB::transaction(function () use ($employees, $results, $snapshots, $response, $actor): PromotionReadinessRun {
            $rows = [];
            $tiers = ['low' => 0, 'medium' => 0, 'high' => 0];
            $scoreSum = 0.0;

            foreach ($employees as $employee) {
                $result = $results->get((string) $employee->id);

                if ($result === null) {
                    continue;
                }

                $tier = $result['tier'] ?? 'low';
                $tiers[$tier] = ($tiers[$tier] ?? 0) + 1;
                $scoreSum += (float) ($result['score'] ?? 0);

                $rows[] = [
                    'employee_id' => $employee->id,
                    'probability' => (float) ($result['probability'] ?? 0),
                    'score' => (float) ($result['score'] ?? 0),
                    'tier' => $tier,
                    'factors' => $result['factors'] ?? null,
                    'features' => $snapshots[$employee->id] ?: null,
                ];
            }

            $scored = count($rows);

            $run = PromotionReadinessRun::create([
                'generated_by' => $actor?->id,
                'status' => 'completed',
                'model_version' => $response['model_version'] ?? null,
                'employees_scored' => $scored,
                'high_count' => $tiers['high'],
                'medium_count' => $tiers['medium'],
                'low_count' => $tiers['low'],
                'average_score' => $scored > 0 ? round($scoreSum / $scored, 2) : null,
            ]);

            $run->scores()->createMany($rows);

            return $run;
        });

        ActivityLogger::log(
            event: 'generated',
            description: "Ran a promotion-readiness assessment ({$run->employees_scored} employees, {$run->high_count} high)",
            subject: $run,
            logName: 'promotion-readiness',
        );

        return $run;
    }
}
