<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Support\Performance\EvaluationOpener;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\RatingModel;
use App\Support\Performance\RatingScales;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo performance programme: the tenant's rating-scale library, a criteria
 * catalogue, two appraisal frameworks that measure genuinely different things on
 * genuinely different scales — a three-section framework for individual
 * contributors and a leadership one for managers — a closed annual cycle and an
 * open mid-year cycle, and a spread of appraisals across them.
 *
 * The point of the shape is that the two frameworks do *not* agree: one reports
 * on a five-band model, the other on a four-band one, and their items mix
 * percentage goal attainment with competency levels. That is what the module has
 * to survive. Idempotent.
 */
class PerformanceSeeder extends Seeder
{
    /**
     * The criteria catalogue: name => [weight, scale, description].
     *
     * @var array<string, array{weight: float, scale: string, description: string}>
     */
    private const CRITERIA = [
        'Goal attainment' => ['weight' => 60, 'scale' => 'Goal attainment (%)', 'description' => 'Achievement against the targets agreed for the cycle.'],
        'Quality of work' => ['weight' => 40, 'scale' => 'Competency level', 'description' => 'Accuracy, thoroughness and overall standard of output.'],
        'Job knowledge' => ['weight' => 35, 'scale' => 'Competency level', 'description' => 'Command of the craft the role is built on.'],
        'Problem solving' => ['weight' => 35, 'scale' => 'Competency level', 'description' => 'Works through ambiguity to a workable answer.'],
        'Collaboration' => ['weight' => 30, 'scale' => 'Competency level', 'description' => 'Works well across the team and beyond it.'],
        'Reliability' => ['weight' => 40, 'scale' => 'Expectation rating', 'description' => 'Dependability, punctuality and follow-through.'],
        'Ownership' => ['weight' => 35, 'scale' => 'Expectation rating', 'description' => 'Takes responsibility past the edges of the job description.'],
        'Integrity' => ['weight' => 25, 'scale' => 'Expectation rating', 'description' => 'Does the right thing when it is the harder thing.'],
        'Team delivery' => ['weight' => 55, 'scale' => 'Goal attainment (%)', 'description' => 'What the team shipped against what it committed to.'],
        'Developing people' => ['weight' => 45, 'scale' => 'Competency level', 'description' => 'Grows the capability of the people reporting in.'],
        'Compliance training' => ['weight' => 100, 'scale' => 'Met / not met', 'description' => 'Mandatory training completed within the cycle.'],
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

        $scales = $this->seedScales();
        $criteria = $this->seedCriteria($scales);
        $frameworks = $this->seedFrameworks($scales, $criteria);
        $periods = $this->seedPeriods();

        if (PerformanceEvaluation::count() > 0) {
            return;
        }

        $this->seedEvaluations($frameworks, $periods);
    }

    /**
     * The tenant's rating-scale library. Idempotent.
     *
     * @return Collection<string, RatingScale>
     */
    private function seedScales(): Collection
    {
        return collect(RatingScales::library())
            ->mapWithKeys(fn (array $scale): array => [
                $scale['name'] => RatingScale::firstOrCreate(['name' => $scale['name']], $scale),
            ]);
    }

    /**
     * The criteria catalogue, each on the scale it is actually measured with.
     *
     * @param  Collection<string, RatingScale>  $scales
     * @return Collection<string, KpiCriterion>
     */
    private function seedCriteria(Collection $scales): Collection
    {
        $order = 0;

        return collect(self::CRITERIA)->mapWithKeys(function (array $config, string $name) use ($scales, &$order): array {
            return [$name => KpiCriterion::firstOrCreate(
                ['name' => $name],
                [
                    'description' => $config['description'],
                    'weight' => $config['weight'],
                    'rating_scale_id' => $scales[$config['scale']]->id,
                    'is_active' => true,
                    'sort_order' => $order++,
                ],
            )];
        });
    }

    /**
     * Two frameworks that disagree about how performance is measured — which is
     * the whole point of frameworks. Idempotent.
     *
     * @param  Collection<string, RatingScale>  $scales
     * @param  Collection<string, KpiCriterion>  $criteria
     * @return array{staff: ReviewTemplate, leadership: ReviewTemplate}
     */
    private function seedFrameworks(Collection $scales, Collection $criteria): array
    {
        $staff = $this->framework(
            name: 'Individual Contributor Review',
            description: 'Goals, capability and how the work gets done — the review most of the company runs on.',
            scale: $scales['Competency level'],
            sections: [
                ['key' => 'goals', 'name' => 'Goals & delivery', 'description' => 'What was committed to for this cycle, and what landed.', 'weight' => 50],
                ['key' => 'competencies', 'name' => 'Capability', 'description' => 'The craft the role is built on.', 'weight' => 30],
                ['key' => 'values', 'name' => 'How we work', 'description' => 'The behaviours the company holds everyone to.', 'weight' => 20],
            ],
            bands: RatingModel::defaultBands(),
            items: [
                ['goals', 'Goal attainment'],
                ['goals', 'Quality of work'],
                ['competencies', 'Job knowledge'],
                ['competencies', 'Problem solving'],
                ['competencies', 'Collaboration'],
                ['values', 'Reliability'],
                ['values', 'Ownership'],
                ['values', 'Integrity'],
            ],
            criteria: $criteria,
            isDefault: true,
        );

        $leadership = $this->framework(
            name: 'People Leader Review',
            description: 'For anyone with reports: what the team delivered, and whether the people grew.',
            scale: $scales['Competency level'],
            sections: [
                ['key' => 'team', 'name' => 'Team outcomes', 'description' => 'What the team delivered against its commitments.', 'weight' => 55],
                ['key' => 'leadership', 'name' => 'Leadership', 'description' => 'Growing the people, not just the output.', 'weight' => 35],
                ['key' => 'mandatory', 'name' => 'Mandatory', 'description' => 'Non-negotiables for the cycle.', 'weight' => 10],
            ],
            // A deliberately different rating model: four bands, different words.
            bands: [
                ['key' => 'exceptional', 'label' => 'Exceptional Leader', 'min_percent' => 85, 'description' => 'Sets the standard other leaders are measured against.', 'tone' => 'positive'],
                ['key' => 'effective', 'label' => 'Effective Leader', 'min_percent' => 65, 'description' => 'The team delivers and the people grow.', 'tone' => 'good'],
                ['key' => 'developing', 'label' => 'Developing Leader', 'min_percent' => 45, 'description' => 'Delivering, with gaps in how the team is led.', 'tone' => 'caution'],
                ['key' => 'not_ready', 'label' => 'Not Yet Ready', 'min_percent' => 0, 'description' => 'The leadership responsibilities are not being met.', 'tone' => 'critical'],
            ],
            items: [
                ['team', 'Team delivery'],
                ['team', 'Goal attainment'],
                ['leadership', 'Developing people'],
                ['leadership', 'Ownership'],
                ['mandatory', 'Compliance training'],
            ],
            criteria: $criteria,
            isDefault: false,
        );

        return ['staff' => $staff, 'leadership' => $leadership];
    }

    /**
     * Build one framework and its items. Idempotent on the framework's name.
     *
     * @param  list<array{key: string, name: string, description: string, weight: int}>  $sections
     * @param  list<array<string, mixed>>  $bands
     * @param  list<array{0: string, 1: string}>  $items
     * @param  Collection<string, KpiCriterion>  $criteria
     */
    private function framework(
        string $name,
        string $description,
        RatingScale $scale,
        array $sections,
        array $bands,
        array $items,
        Collection $criteria,
        bool $isDefault,
    ): ReviewTemplate {
        $template = ReviewTemplate::firstOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'rating_scale_id' => $scale->id,
                'sections' => $sections,
                'bands' => $bands,
                'result_display' => 'band',
                'applies_to' => 'all',
                'is_default' => $isDefault,
                'is_active' => true,
            ],
        );

        if ($template->items()->exists()) {
            return $template;
        }

        $template->items()->createMany(
            collect($items)->map(function (array $item, int $index) use ($criteria): array {
                [$sectionKey, $criterionName] = $item;
                $criterion = $criteria[$criterionName];

                return [
                    'kpi_criterion_id' => $criterion->id,
                    'rating_scale_id' => $criterion->rating_scale_id,
                    'section_key' => $sectionKey,
                    'name' => $criterion->name,
                    'description' => $criterion->description,
                    'weight' => $criterion->weight,
                    'sort_order' => $index,
                ];
            })->all()
        );

        return $template->refresh();
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
     * Appraise a spread of employees: finished, acknowledged appraisals for the
     * closed cycle, in-progress drafts for the open one — every one of them
     * opened through the same {@see EvaluationOpener} the app uses.
     *
     * @param  array{staff: ReviewTemplate, leadership: ReviewTemplate}  $frameworks
     * @param  array{closed: EvaluationPeriod, open: EvaluationPeriod}  $periods
     */
    private function seedEvaluations(array $frameworks, array $periods): void
    {
        $evaluator = User::query()->orderBy('id')->first();
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();
        $opener = app(EvaluationOpener::class);
        $scorer = app(PerformanceScorer::class);

        foreach ($employees as $i => $employee) {
            // Every fifth person is reviewed as a people leader.
            $framework = $i % 5 === 4 ? $frameworks['leadership'] : $frameworks['staff'];

            if ($i % 4 !== 3) {
                $this->conduct($opener, $scorer, $employee, $periods['closed'], $framework, $evaluator, 'acknowledged', $i);
            }

            if ($i % 3 === 0) {
                $this->conduct($opener, $scorer, $employee, $periods['open'], $framework, $evaluator, 'draft', $i);
            }
        }
    }

    /**
     * Open one appraisal and rate it, deterministically but believably — each
     * line filled in at its own scale's own resolution.
     */
    private function conduct(
        EvaluationOpener $opener,
        PerformanceScorer $scorer,
        Employee $employee,
        EvaluationPeriod $period,
        ReviewTemplate $framework,
        ?User $evaluator,
        string $status,
        int $seed,
    ): void {
        // Seeded straight through the opener (the eligibility checks that would
        // refuse a closed cycle live in blockedReason(), which only the HTTP
        // paths call) so demo scorecards are built exactly like real ones.
        $evaluation = $opener->open($employee, $period, $framework, $evaluator);

        $isDraft = $status === 'draft';

        foreach ($evaluation->scores()->orderBy('sort_order')->get() as $index => $score) {
            // Drafts are only partially filled in.
            if ($isDraft && $index >= 3) {
                continue;
            }

            $scale = $score->scale();
            $span = $scale['max'] - $scale['min'];
            // A believable spread in the upper half of whatever scale this is.
            $position = 0.5 + (($seed + $index) % 5) * 0.1;
            $raw = $scale['min'] + $span * $position;

            $score->update([
                'score' => $scale['type'] === 'numeric' || $scale['type'] === 'levels'
                    ? $this->nearestLevel($raw, $scale)
                    : round($raw),
            ]);
        }

        $evaluation->applyResult($scorer->score($evaluation->scores()->get(), $evaluation->bandList()));
        $evaluation->status = $status;
        $evaluation->submitted_at = $isDraft ? null : $period->end_date;
        $evaluation->acknowledged_at = $status === 'acknowledged' ? $period->end_date : null;
        $evaluation->remarks = $isDraft ? null : 'Solid contributions through the cycle; keep building on the strengths.';
        $evaluation->save();
    }

    /**
     * Snap a raw position onto a value the scale can actually take.
     *
     * @param  array{type: string, min: float, max: float, step: float, levels: list<array{value: float, label: string, description: string|null}>|null}  $scale
     */
    private function nearestLevel(float $raw, array $scale): float
    {
        $values = $scale['levels'] !== null
            ? array_column($scale['levels'], 'value')
            : range((int) $scale['min'], (int) $scale['max']);

        usort($values, fn ($a, $b): int => abs($a - $raw) <=> abs($b - $raw));

        return (float) $values[0];
    }
}
