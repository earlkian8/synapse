<?php

namespace Database\Seeders;

use App\Models\AppraisalReview;
use App\Models\CalibrationAdjustment;
use App\Models\CalibrationSession;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\GoalTemplate;
use App\Models\KpiCriterion;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceGoal;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Support\Performance\EvaluationOpener;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\RatingModel;
use App\Support\Performance\RatingScales;
use App\Support\Performance\ReviewWorkflow;
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
 * to survive.
 *
 * On top of that, the taking part (ADRs 0072, 0073): a goal library, goals with
 * check-ins in the open cycle, reviews from the people around each appraisal,
 * and an open calibration session holding the cycle's first submitted results.
 * The mobile staff login ({@see DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL}) gets a
 * shared FY 2025 appraisal waiting for their acknowledgement, a self-review to
 * write, a colleague's review to answer and goals of their own. Idempotent.
 */
class PerformanceSeeder extends Seeder
{
    /**
     * The criteria catalogue: name => [weight, scale, description].
     *
     * @var array<string, array{weight: float, scale: string, description: string}>
     */
    private const GOAL_LIBRARY = [
        'Reduce ticket backlog' => ['measure' => 'number', 'start_value' => 120, 'target_value' => 20, 'unit' => 'tickets', 'description' => 'Bring the open queue down to what the team can clear in a week.'],
        'Ship the quarterly roadmap' => ['measure' => 'percent', 'start_value' => 0, 'target_value' => 100, 'unit' => null, 'description' => 'Every committed roadmap item for the cycle shipped and in use.'],
        'Complete a certification' => ['measure' => 'percent', 'start_value' => 0, 'target_value' => 100, 'unit' => null, 'description' => 'A certification agreed with your manager, finished within the cycle.'],
        'Raise customer satisfaction' => ['measure' => 'number', 'start_value' => 78, 'target_value' => 90, 'unit' => 'CSAT', 'description' => 'Lift the satisfaction score on the surveys your work touches.'],
        'Document a core process' => ['measure' => 'percent', 'start_value' => 0, 'target_value' => 100, 'unit' => null, 'description' => 'Write down one process only you know, so someone else can run it.'],
    ];

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
        $library = $this->seedGoalLibrary();

        if (PerformanceEvaluation::count() > 0) {
            return;
        }

        $this->seedEvaluations($frameworks, $periods);
        $this->seedGoals($periods['open'], $library);
        $this->seedReviews($periods['open']);
        $this->seedCalibration($periods['open']);
    }

    /**
     * The goal library under the performance framework (ADR 0073). Idempotent.
     *
     * @return Collection<string, GoalTemplate>
     */
    private function seedGoalLibrary(): Collection
    {
        return collect(self::GOAL_LIBRARY)->mapWithKeys(fn (array $entry, string $name): array => [
            $name => GoalTemplate::firstOrCreate(['name' => $name], [...$entry, 'is_active' => true]),
        ]);
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

            // The mobile staff login: last year's result is shared and waits for
            // them to acknowledge it; this year's is in progress.
            if ($employee->email === DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL) {
                $this->conduct($opener, $scorer, $employee, $periods['closed'], $frameworks['staff'], $evaluator, 'submitted', $i);
                $this->conduct($opener, $scorer, $employee, $periods['open'], $frameworks['staff'], $evaluator, 'draft', $i);

                continue;
            }

            if ($i % 4 !== 3) {
                $this->conduct($opener, $scorer, $employee, $periods['closed'], $framework, $evaluator, 'acknowledged', $i);
            }

            // Half of this cycle's appraisals are already submitted — held back
            // from their employees by the open calibration session.
            if ($i % 3 === 0) {
                $this->conduct($opener, $scorer, $employee, $periods['open'], $framework, $evaluator, $i % 2 === 0 ? 'submitted' : 'draft', $i);
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
        // A closed cycle's results reached their employees when submitted; an
        // open one's are held by the calibration session (seedCalibration).
        $evaluation->shared_at = ! $isDraft && $period->status === 'closed' ? $period->end_date : null;
        $evaluation->acknowledged_at = $status === 'acknowledged' ? $period->end_date : null;
        // Last year's sign-offs were collected on paper and recorded by HR.
        $evaluation->acknowledged_by = $status === 'acknowledged' ? $evaluator?->id : null;
        $evaluation->remarks = $isDraft ? null : 'Solid contributions through the cycle; keep building on the strengths.';
        $evaluation->save();
    }

    /**
     * Goals for everyone appraised in the open cycle, two or three each — some
     * from the library, some written for the person — with a believable run of
     * check-ins: most recent, a few gone quiet past the stale mark.
     *
     * @param  Collection<string, GoalTemplate>  $library
     */
    private function seedGoals(EvaluationPeriod $period, Collection $library): void
    {
        $hr = User::query()->orderBy('id')->first();
        $templates = $library->values();
        $evaluations = PerformanceEvaluation::query()
            ->where('evaluation_period_id', $period->id)
            ->with('employee.user')
            ->orderBy('id')
            ->get();

        foreach ($evaluations as $i => $evaluation) {
            $employee = $evaluation->employee;
            $mobile = $employee->email === DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL;

            $goals = $mobile
                ? [
                    ['template' => $library['Ship the quarterly roadmap'], 'weight' => 2, 'path' => [20, 45, 70], 'health' => 'on_track', 'days' => 6],
                    ['template' => $library['Reduce ticket backlog'], 'weight' => 1, 'path' => [104, 88], 'health' => 'at_risk', 'days' => 12, 'note' => 'Two people out in September; the queue grew back.'],
                    ['title' => 'Mentor the new hire through onboarding', 'weight' => 1, 'path' => [25], 'health' => 'on_track', 'days' => 41, 'own' => true],
                ]
                : collect(range(0, 1 + $i % 2))->map(fn (int $n): array => [
                    'template' => $templates[($i + $n) % $templates->count()],
                    'weight' => $n === 0 ? 2 : 1,
                    'path' => $this->goalPath($templates[($i + $n) % $templates->count()], $i + $n),
                    'health' => ['on_track', 'on_track', 'at_risk', 'off_track'][($i + $n) % 4],
                    // Every seventh goal has gone quiet past the stale mark.
                    'days' => ($i + $n) % 7 === 6 ? 45 : 3 + ($i * 5 + $n * 3) % 20,
                ])->all();

            foreach ($goals as $spec) {
                $this->goal($employee, $period, $spec, $hr);
            }
        }
    }

    /**
     * A plausible run of check-in values for a library goal: a share of the way
     * from its start toward its target, a step at a time.
     *
     * @return list<float>
     */
    private function goalPath(GoalTemplate $template, int $seed): array
    {
        $start = (float) $template->start_value;
        $span = (float) $template->target_value - $start;
        $steps = 1 + $seed % 3;
        $reach = 0.3 + ($seed % 6) * 0.12;

        return collect(range(1, $steps))
            ->map(fn (int $step): float => round($start + $span * $reach * $step / $steps))
            ->all();
    }

    /**
     * One goal and its check-ins, the last of them `days` ago.
     *
     * @param  array{template?: GoalTemplate, title?: string, weight: int, path: list<float>, health: string, days: int, note?: string, own?: bool}  $spec
     */
    private function goal(Employee $employee, EvaluationPeriod $period, array $spec, ?User $hr): void
    {
        $template = $spec['template'] ?? null;
        $owner = $employee->user;
        $setBy = ($spec['own'] ?? false) ? $owner : $hr;
        $values = $spec['path'];
        $last = end($values);

        $goal = PerformanceGoal::create([
            'employee_id' => $employee->id,
            'evaluation_period_id' => $period->id,
            'goal_template_id' => $template?->id,
            'title' => $template?->name ?? $spec['title'],
            'description' => $template?->description,
            'measure' => $template?->measure ?? 'percent',
            'start_value' => $template?->start_value ?? 0,
            'target_value' => $template?->target_value ?? 100,
            'current_value' => $last,
            'unit' => $template?->unit,
            'weight' => $spec['weight'],
            'due_on' => $period->end_date,
            'status' => 'active',
            'health' => $spec['health'],
            'last_check_in_at' => now()->subDays($spec['days']),
            'created_by' => $setBy?->id,
        ]);

        $goal->forceFill(['created_at' => now()->subDays($spec['days'] + 14 * count($values))])->save();

        foreach ($values as $n => $value) {
            $isLast = $n === count($values) - 1;
            $at = now()->subDays($spec['days'] + 14 * (count($values) - 1 - $n));

            $checkIn = $goal->checkIns()->create([
                // Their own check-ins when they can sign in; their manager's otherwise.
                'author_id' => $owner?->id ?? $hr?->id,
                'value' => $value,
                'health' => $isLast ? $spec['health'] : 'on_track',
                'note' => $isLast
                    ? ($spec['note'] ?? 'At '.GoalProgress::format((float) $value, $goal->measure, $goal->unit).' now.')
                    : null,
            ]);

            $checkIn->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    /**
     * Reviews of the open cycle's appraisals from the people around each one —
     * their manager and two colleagues from the same department — answered on
     * the scorecard's own lines, so the pooled view has something to show. The
     * mobile login has a self-review to write and a colleague's review to answer.
     *
     * Most demo colleagues have no sign-in, so their answers are written straight
     * to the table as if given. Asking someone through the app still needs an
     * account that can answer (ADR 0072).
     */
    private function seedReviews(EvaluationPeriod $period): void
    {
        $hr = User::query()->orderBy('id')->first();
        $evaluations = PerformanceEvaluation::query()
            ->where('evaluation_period_id', $period->id)
            ->with(['employee', 'scores'])
            ->orderBy('id')
            ->get();
        $roster = Employee::query()->where('employment_status', 'active')->orderBy('id')->get();
        $mobile = $roster->firstWhere('email', DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL);

        foreach ($evaluations as $i => $evaluation) {
            $subject = $evaluation->employee;
            $colleagues = $roster
                ->where('department_id', $subject->department_id)
                ->reject(fn (Employee $e): bool => $e->id === $subject->id || $e->id === $subject->manager_id || $e->id === $mobile?->id)
                ->values();

            $reviewers = collect([$roster->firstWhere('id', $subject->manager_id)])
                ->merge($colleagues->slice($i % max(1, $colleagues->count() - 1), 2))
                ->filter()
                ->unique('id');

            foreach ($reviewers as $n => $reviewer) {
                $this->review($evaluation, $reviewer, 'submitted', $hr, $i + $n);
            }

            if ($subject->id === $mobile?->id) {
                $this->review($evaluation, $subject, 'pending', $hr, $i);
            }
        }

        // A colleague's appraisal waiting on the mobile login's answer — a peer's
        // (not their manager's) in their own department when there is one.
        $open = $evaluations->filter(fn (PerformanceEvaluation $e): bool => $e->status === 'draft'
            && $e->employee_id !== $mobile?->id
            && $e->employee_id !== $mobile?->manager_id);
        $colleague = $open->first(fn (PerformanceEvaluation $e): bool => $e->employee->department_id === $mobile?->department_id)
            ?? $open->first();

        if ($mobile !== null && $colleague !== null
            && ! AppraisalReview::where('performance_evaluation_id', $colleague->id)->where('reviewer_id', $mobile->id)->exists()) {
            $this->review($colleague, $mobile, 'pending', $hr, 0);
        }
    }

    /**
     * One review: waiting with a due date, or answered on every rated line of
     * the scorecard — near the evaluator's own score, each at its scale's own
     * resolution.
     */
    private function review(PerformanceEvaluation $evaluation, Employee $reviewer, string $status, ?User $hr, int $seed): void
    {
        $review = AppraisalReview::create([
            'performance_evaluation_id' => $evaluation->id,
            'reviewer_id' => $reviewer->id,
            'relationship' => ReviewWorkflow::relationshipOf($reviewer, $evaluation->employee),
            'status' => $status,
            'requested_by' => $hr?->id,
            'due_on' => now()->addDays(7 + $seed % 7)->toDateString(),
            'strengths' => $status === 'submitted' ? ['Calm under pressure and generous with their time.', 'Follows through on every commitment, without being chased.', 'Explains hard things simply; the team learns from them.'][$seed % 3] : null,
            'improvements' => $status === 'submitted' ? ['Could share progress earlier, before a deadline gets close.', 'Take on more of the planning, not just the delivery.', 'Push back sooner when a scope keeps growing.'][$seed % 3] : null,
            'submitted_at' => $status === 'submitted' ? now()->subDays(2 + $seed % 10) : null,
        ]);

        if ($status !== 'submitted') {
            return;
        }

        foreach ($evaluation->scores as $index => $line) {
            if ($line->score === null) {
                continue;
            }

            $scale = $line->scale();
            $span = $scale['max'] - $scale['min'];
            // A shade above or below the evaluator, never off the scale.
            $raw = max($scale['min'], min($scale['max'], (float) $line->score + $span * ((($seed + $index) % 3) - 1) * 0.1));

            $review->scores()->create([
                'performance_score_id' => $line->id,
                'score' => $scale['type'] === 'numeric' || $scale['type'] === 'levels'
                    ? $this->nearestLevel($raw, $scale)
                    : round($raw),
            ]);
        }
    }

    /**
     * An open calibration session over the whole open cycle, holding back its
     * submitted results, with one rating already moved and the reason on record.
     */
    private function seedCalibration(EvaluationPeriod $period): void
    {
        $hr = User::query()->orderBy('id')->first();

        $session = CalibrationSession::create([
            'evaluation_period_id' => $period->id,
            'name' => 'H1 2026 calibration',
            'scheduled_for' => now()->addDays(3)->toDateString(),
            'department_ids' => null,
            'status' => 'open',
            'notes' => 'Compare the submitted ratings across departments before anything is shared.',
            'facilitator_id' => $hr?->id,
        ]);

        if ($hr !== null) {
            $session->participants()->sync([$hr->id]);
        }

        $evaluation = PerformanceEvaluation::query()
            ->where('evaluation_period_id', $period->id)
            ->where('status', 'submitted')
            ->orderByDesc('overall_percent')
            ->first();

        if ($evaluation === null) {
            return;
        }

        $bands = collect($evaluation->bandList())->sortByDesc('min_percent')->values();
        $at = $bands->search(fn (array $band): bool => $band['key'] === $evaluation->result_band);
        $to = $bands[$at === false ? 0 : min($at + 1, $bands->count() - 1)];

        if ($to['key'] === $evaluation->result_band) {
            return;
        }

        CalibrationAdjustment::create([
            'calibration_session_id' => $session->id,
            'performance_evaluation_id' => $evaluation->id,
            'from_band' => $evaluation->result_band,
            'from_label' => $evaluation->result_label,
            'to_band' => $to['key'],
            'to_label' => $to['label'],
            'reason' => 'Rated against a softer bar than the rest of the department; in line with peers one band lower.',
            'adjusted_by' => $hr?->id,
        ]);

        $evaluation->forceFill([
            'scored_band' => $evaluation->result_band,
            'scored_label' => $evaluation->result_label,
            'result_band' => $to['key'],
            'result_label' => $to['label'],
            'calibrated_at' => now(),
        ])->save();
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
