<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\Performance\PerformanceScorer;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo performance program: a weighted KPI criteria set, a closed annual cycle
 * and an open mid-year cycle, and a spread of evaluations (acknowledged in the
 * closed period, in-progress drafts in the open one) so the Performance module
 * has real scorecards and metrics. Idempotent.
 */
class PerformanceSeeder extends Seeder
{
    /**
     * The starter KPI criteria (name => [weight, description]). Weights sum to 100.
     *
     * @var array<string, array{weight: float, description: string}>
     */
    private const CRITERIA = [
        'Quality of Work' => ['weight' => 25, 'description' => 'Accuracy, thoroughness and overall standard of output.'],
        'Productivity' => ['weight' => 20, 'description' => 'Volume of work completed against expectations.'],
        'Teamwork & Collaboration' => ['weight' => 15, 'description' => 'Works well with others and supports the team.'],
        'Communication' => ['weight' => 15, 'description' => 'Clear, timely and professional communication.'],
        'Reliability & Attendance' => ['weight' => 15, 'description' => 'Dependability, punctuality and follow-through.'],
        'Initiative' => ['weight' => 10, 'description' => 'Proactiveness and ownership beyond what is assigned.'],
    ];

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        $criteria = $this->seedCriteria();
        $periods = $this->seedPeriods();

        if (PerformanceEvaluation::count() > 0) {
            return;
        }

        $this->seedEvaluations($criteria, $periods);
    }

    /**
     * Seed the KPI criteria catalogue. Idempotent.
     *
     * @return Collection<int, KpiCriterion>
     */
    private function seedCriteria(): Collection
    {
        $criteria = collect();

        foreach (self::CRITERIA as $name => $config) {
            $criteria->push(KpiCriterion::firstOrCreate(
                ['name' => $name],
                [
                    'description' => $config['description'],
                    'weight' => $config['weight'],
                    'is_active' => true,
                ],
            ));
        }

        return $criteria;
    }

    /**
     * Seed a closed annual cycle and an open mid-year cycle. Idempotent.
     *
     * @return array{closed: EvaluationPeriod, open: EvaluationPeriod}
     */
    private function seedPeriods(): array
    {
        $closed = EvaluationPeriod::firstOrCreate(
            ['name' => 'FY 2025 Annual Review'],
            ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'closed'],
        );

        $open = EvaluationPeriod::firstOrCreate(
            ['name' => 'H1 2026 Review'],
            ['start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'status' => 'open'],
        );

        return ['closed' => $closed, 'open' => $open];
    }

    /**
     * Evaluate a spread of employees: acknowledged appraisals for the closed
     * cycle, in-progress drafts for the open one.
     *
     * @param  Collection<int, KpiCriterion>  $criteria
     * @param  array{closed: EvaluationPeriod, open: EvaluationPeriod}  $periods
     */
    private function seedEvaluations(Collection $criteria, array $periods): void
    {
        $evaluator = User::query()->orderBy('id')->first();
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();
        $scorer = new PerformanceScorer;

        foreach ($employees as $i => $employee) {
            // Closed cycle: a finished, acknowledged appraisal for most staff.
            if ($i % 4 !== 3) {
                $this->makeEvaluation($employee, $periods['closed'], $criteria, $evaluator, $scorer, 'acknowledged', $i);
            }

            // Open cycle: an in-progress draft for a subset (every third employee).
            if ($i % 3 === 0) {
                $this->makeEvaluation($employee, $periods['open'], $criteria, $evaluator, $scorer, 'draft', $i);
            }
        }
    }

    /**
     * Build one evaluation with a scored line per criterion and a derived overall.
     *
     * @param  Collection<int, KpiCriterion>  $criteria
     */
    private function makeEvaluation(
        Employee $employee,
        EvaluationPeriod $period,
        Collection $criteria,
        ?User $evaluator,
        PerformanceScorer $scorer,
        string $status,
        int $seed,
    ): void {
        $isDraft = $status === 'draft';

        $lines = $criteria->values()->map(function (KpiCriterion $criterion, int $j) use ($seed, $isDraft): array {
            // Deterministic, believable ratings in the 3–5 band.
            $rating = 3 + (($seed + $j) % 3);

            // Drafts are only partially filled in.
            $score = $isDraft && $j >= 3 ? null : (float) $rating;

            return [
                'kpi_criterion_id' => $criterion->id,
                'label' => $criterion->name,
                'weight' => $criterion->weight,
                'score' => $score,
            ];
        });

        $evaluation = PerformanceEvaluation::create([
            'employee_id' => $employee->id,
            'evaluation_period_id' => $period->id,
            'evaluator_id' => $evaluator?->id,
            'status' => $status,
            'overall_score' => $scorer->overall($lines->all()),
            'submitted_at' => $isDraft ? null : $period->end_date,
            'acknowledged_at' => $status === 'acknowledged' ? $period->end_date : null,
            'remarks' => $isDraft ? null : 'Solid contributions through the cycle; keep building on strengths.',
        ]);

        $evaluation->scores()->createMany($lines->all());
    }
}
