<?php

namespace App\Support\Ml;

use App\Models\Employee;
use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The canonical operation behind the Promotion Readiness module: score every
 * active employee through the promotion model and persist the result as a run.
 *
 * It is the single source of truth for "assess promotion readiness" — the
 * controller (and any future scheduled job or assistant tool) calls this rather
 * than re-deriving the flow. It gathers the employees, maps them to features
 * ({@see PromotionFeatureMapper}), asks the inference service ({@see MlClient}),
 * then writes a {@see PromotionReadinessRun} with one
 * {@see PromotionReadinessScore} per employee the model scored.
 *
 * The model declines an employee with no completed appraisal (ADR 0045) rather
 * than scoring them from a guess. They are recorded on the run, with the reason,
 * so HR sees who is missing and what would include them — and every persisted
 * score is a real one.
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
            ->with(['performanceEvaluations' => fn ($query) => $query->with('period:id,name,start_date,end_date')])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        if ($employees->isEmpty()) {
            throw new MlException('There are no active employees to assess.');
        }

        // Build the inference batch, keeping each employee's feature snapshot and
        // the appraisals it was drawn from.
        $histories = [];
        $snapshots = [];
        $instances = [];

        foreach ($employees as $employee) {
            $history = AppraisalHistory::of($employee);
            $histories[$employee->id] = $history;
            $snapshots[$employee->id] = $this->mapper->features($employee, $history);
            $instances[] = ['ref' => (string) $employee->id, 'features' => $snapshots[$employee->id]];
        }

        $response = $this->ml->predict('promotion', $instances);

        if (! empty($response['warnings'])) {
            Log::warning('Promotion model reported a contract mismatch.', ['warnings' => $response['warnings']]);
        }

        /** @var Collection<string, array<string, mixed>> $results */
        $results = collect($response['results'] ?? [])->keyBy('ref');

        $run = DB::transaction(function () use ($employees, $results, $snapshots, $histories, $response, $actor): PromotionReadinessRun {
            $rows = [];
            $unassessed = [];
            $tiers = ['low' => 0, 'medium' => 0, 'high' => 0];
            $scoreSum = 0.0;

            foreach ($employees as $employee) {
                $result = $results->get((string) $employee->id);
                $history = $histories[$employee->id];

                if ($result === null || ($result['status'] ?? 'scored') !== 'scored' || ! isset($result['score'])) {
                    $unassessed[] = [
                        'employee_id' => $employee->id,
                        'reason' => $history->reasonUnassessed($history),
                    ];

                    continue;
                }

                $tier = in_array($result['tier'] ?? null, PromotionReadinessScore::TIERS, true) ? $result['tier'] : 'low';
                $tiers[$tier]++;
                $scoreSum += (float) $result['score'];

                $rows[] = [
                    'employee_id' => $employee->id,
                    'probability' => (float) ($result['probability'] ?? 0),
                    'score' => (float) $result['score'],
                    'tier' => $tier,
                    'basis' => $result['basis'] ?? null,
                    'factors' => $result['factors'] ?? null,
                    'features' => $snapshots[$employee->id] ?: null,
                    'history' => $history->trajectory(2) ?: null,
                    'warnings' => ($result['warnings'] ?? []) ?: null,
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
                'unassessed' => $unassessed ?: null,
            ]);

            $run->scores()->createMany($rows);

            return $run;
        });

        $declined = count($run->unassessed ?? []);

        ActivityLogger::log(
            event: 'generated',
            description: "Ran a promotion-readiness assessment ({$run->employees_scored} employees, {$run->high_count} high"
                .($declined > 0 ? ", {$declined} not assessed)" : ')'),
            subject: $run,
            logName: 'promotion-readiness',
        );

        return $run;
    }
}
