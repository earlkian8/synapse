<?php

namespace App\Services\Assistant\Modules;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceScore;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\PerformanceCalibration;
use App\Support\Performance\RatingScales;
use App\Support\Performance\TemplateResolver;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Performance capability: read and conduct appraisals.
 *
 * **Reading** answers what HR and supervisors actually ask of a review cycle —
 * "how is Maria's appraisal looking?", "how far through the cycle are we?",
 * "which department is rating softest?" — from the same queries the screens run
 * ({@see PerformanceCalibration} for the cycle read, each appraisal's own frozen
 * rating model for its result). **Doing** opens, rates, submits, signs off and
 * discards appraisals, and launches a cycle, all through
 * {@see AppraisalWorkflow} — the path the screens take — so the scale checks,
 * the scoring and the lifecycle rules are the screens' own.
 *
 * Disclosure follows the screens, which have no self-service view: an
 * appraisal — including one's own — needs `performance.view`, and an ML
 * forecast additionally needs `analytics.performance.view`. Writes need
 * `performance.manage`. Anything that locks, signs off, deletes or launches
 * waits for the user's Confirm (ADR 0049).
 *
 * Names resolve to exactly one person or not at all: an appraisal is not a
 * thing to act on for "the first Maria".
 */
class PerformanceModule extends Module implements ContributesContext, ContributesTopicContext
{
    /** How many past appraisals a person's brief lists. */
    private const CONTEXT_APPRAISALS = 4;

    /** How many results a list returns. */
    private const MAX_RESULTS = 10;

    /** How many criteria a scorecard read-out spells out. */
    private const MAX_CRITERIA = 20;

    public function __construct(
        private readonly AppraisalWorkflow $workflow,
        private readonly PerformanceCalibration $calibration,
        private readonly TemplateResolver $templates,
    ) {}

    public function key(): string
    {
        return 'performance';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('performance.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_appraisals' => 'findAppraisals',
            'get_appraisal' => 'getAppraisal',
            'performance_summary' => 'summary',
            'list_review_cycles' => 'listCycles',
            'open_appraisal' => 'openAppraisal',
            'rate_appraisal' => 'rateAppraisal',
            'submit_appraisal' => 'submitAppraisal',
            'acknowledge_appraisal' => 'acknowledgeAppraisal',
            'delete_draft_appraisal' => 'deleteDraft',
            'launch_review_cycle' => 'launchCycle',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_appraisals' => 'performance.view',
            'get_appraisal' => 'performance.view',
            'performance_summary' => 'performance.view',
            'list_review_cycles' => 'performance.view',
            'open_appraisal' => 'performance.manage',
            'rate_appraisal' => 'performance.manage',
            'submit_appraisal' => 'performance.manage',
            'acknowledge_appraisal' => 'performance.manage',
            'delete_draft_appraisal' => 'performance.manage',
            'launch_review_cycle' => 'performance.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // Submitting locks a result, signing off makes it final, deleting and
        // launching a whole cycle are not done on a misread.
        return [
            'submit_appraisal',
            'acknowledge_appraisal',
            'delete_draft_appraisal',
            'launch_review_cycle',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        // Isolation is a global scope that switches itself off with no tenant
        // bound — the one state in which these queries would see everyone.
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'performance.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'performance.manage' ? 'change appraisals' : 'view appraisals');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $cycles = EvaluationPeriod::query()->recentFirst()->limit(6)->get(['name', 'status'])
            ->map(fn (EvaluationPeriod $p): string => "{$p->name} ({$p->status})")->implode(', ') ?: 'none';

        $manage = $this->allows($user, 'performance.manage')
            ? <<<'TXT'

            - open_appraisal opens one for a person in a cycle (the open cycle when none is named), on the framework that covers them unless one is named.
            - rate_appraisal saves ratings on a DRAFT: one entry per criterion, by its name, with a rating that is a number on that criterion's own scale or one of its level names ("Proficient"). Out-of-scale ratings are refused.
            - submit_appraisal locks it (every criterion must be rated); acknowledge_appraisal records the employee's sign-off; delete_draft_appraisal discards a draft; launch_review_cycle opens appraisals for everyone active (or named departments). These wait for the user's confirmation.
            TXT
            : '';

        return <<<TXT
        PERFORMANCE — appraisals scored against the company's own frameworks. A result is attainment on 0–100 reported in the company's own rating words (its "band"), plus a 1–5 index. Status runs draft → submitted → acknowledged.
        - find_appraisals lists appraisals (by person, cycle, status); get_appraisal reads one scorecard in full; performance_summary reads a cycle — coverage, average, the spread across bands, and per-department calibration; list_review_cycles lists cycles.
        - Pass people by name or employee number, and cycles by name.{$manage}
          Review cycles: {$cycles}
        TXT;
    }

    public function tools(User $user): array
    {
        $employee = ['type' => 'STRING', 'description' => 'Employee name or employee number.'];
        $cycle = ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle (or the person\'s latest appraisal).'];

        return $this->permitted($user, [
            [
                'name' => 'find_appraisals',
                'description' => 'List appraisals, optionally filtered by employee, review cycle and status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'cycle' => ['type' => 'STRING', 'description' => 'Review cycle name.'],
                        'status' => ['type' => 'STRING', 'enum' => PerformanceEvaluation::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'get_appraisal',
                'description' => "Read one person's appraisal in full: result, band, section attainment and every criterion's rating.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'cycle' => $cycle],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'performance_summary',
                'description' => 'How a review cycle is going: coverage, statuses, average attainment, the spread across rating bands, and per-department calibration.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.']],
                ],
            ],
            [
                'name' => 'list_review_cycles',
                'description' => 'List review cycles with their dates, status and how many appraisals each holds.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'open_appraisal',
                'description' => 'Open an appraisal for one employee in an open review cycle.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.'],
                        'framework' => ['type' => 'STRING', 'description' => 'Appraisal framework name; defaults to the one covering the employee.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'rate_appraisal',
                'description' => "Save ratings (and remarks) on an employee's draft appraisal.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'cycle' => $cycle,
                        'ratings' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'criterion' => ['type' => 'STRING', 'description' => 'The criterion, by name.'],
                                    'rating' => ['type' => 'STRING', 'description' => 'A number on the criterion\'s scale, or one of its level names.'],
                                    'remarks' => ['type' => 'STRING', 'description' => 'Evidence for the rating.'],
                                ],
                            ],
                        ],
                        'remarks' => ['type' => 'STRING', 'description' => 'Overall remarks for the appraisal.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'submit_appraisal',
                'description' => "Submit (lock) an employee's draft appraisal. Every criterion must be rated.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'cycle' => $cycle],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'acknowledge_appraisal',
                'description' => "Record the employee's sign-off on their submitted appraisal.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'cycle' => $cycle],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'delete_draft_appraisal',
                'description' => "Discard an employee's draft appraisal. Submitted ones cannot be deleted.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'cycle' => $cycle],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'launch_review_cycle',
                'description' => 'Open appraisals for every active employee in a review cycle (or only named departments). People already appraised are skipped.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.'],
                        'departments' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Only these departments, by name.'],
                        'framework' => ['type' => 'STRING', 'description' => 'Use this framework for everyone instead of each person\'s own.'],
                    ],
                ],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * A person's appraisal record: their recent results in the company's own
     * words, how they have moved, and — when the asker may see forecasts — where
     * the model expects them next.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        // No self-service exception: the screens have no "my appraisal" view,
        // so neither does the assistant.
        if ($employee === null || $user->cannot('performance.view')) {
            return null;
        }

        $appraisals = PerformanceEvaluation::query()
            ->where('employee_id', $employee->id)
            ->with('period:id,name')
            ->withCount(['scores', 'scores as rated_count' => fn (Builder $q) => $q->whereNotNull('score')])
            ->latest('id')
            ->limit(self::CONTEXT_APPRAISALS)
            ->get();

        if ($appraisals->isEmpty()) {
            return ContextSection::of('Performance appraisals', ['No appraisals on record.']);
        }

        $completed = $appraisals->filter(fn (PerformanceEvaluation $e): bool => $e->overall_percent !== null && $e->status !== 'draft');
        $trend = $completed->count() >= 2
            ? sprintf(
                'Attainment moved from %s%% (%s) to %s%% (%s).',
                $this->percent($completed->last()->overall_percent),
                $completed->last()->period?->name ?? 'earlier',
                $this->percent($completed->first()->overall_percent),
                $completed->first()->period?->name ?? 'latest',
            )
            : null;

        return ContextSection::of('Performance appraisals', [
            ...$appraisals->map(fn (PerformanceEvaluation $e): string => $this->describeAppraisal($e))->all(),
            $trend,
            $this->forecastLine($user, $employee),
        ]);
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'appraisal', 'appraisals', 'performance', 'evaluation', 'evaluations', 'review cycle',
            'review cycles', 'calibration', 'rating', 'ratings', 'scorecard', 'scorecards', 'kpi', 'kpis',
        ];
    }

    /**
     * The cycle in progress (else the latest): how far through it the company
     * is, how it rated itself, and which departments stand out.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('performance.view')) {
            return null;
        }

        $period = $this->currentCycle();

        if ($period === null) {
            return ContextSection::of('Performance', ['No review cycles have been set up yet.']);
        }

        return ContextSection::of("Performance ({$period->name})", $this->cycleLines($period));
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findAppraisals(User $user, array $args): ToolResult
    {
        $needle = trim((string) ($args['employee'] ?? ''));
        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : null;

        if (filled($args['cycle'] ?? null) && $period === null) {
            return ToolResult::error('Looked up the review cycle', 'No review cycle matches that name.');
        }

        $status = in_array($args['status'] ?? null, PerformanceEvaluation::STATUSES, true) ? $args['status'] : null;

        $evaluations = PerformanceEvaluation::query()
            ->with(['employee.department', 'employee.position', 'period:id,name'])
            ->when($needle !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $this->matchByTokens($e, $needle)))
            ->when($period !== null, fn (Builder $q) => $q->where('evaluation_period_id', $period->id))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $evaluations->map(fn (PerformanceEvaluation $e): array => $this->appraisalCard($e, 'find', 'neutral', ucfirst($e->status)))->all();

        return ToolResult::found('Searched appraisals', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getAppraisal(User $user, array $args): ToolResult
    {
        [$evaluation, $error] = $this->locateAppraisal($args);

        if ($evaluation === null) {
            return ToolResult::error('Looked up the appraisal', $error);
        }

        $evaluation->load(['scores' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'evaluator:id,first_name,last_name']);

        // A named individual's appraisal was read — ADR 0027's audit rule.
        ActivityLogger::log(
            event: 'viewed',
            description: "Viewed the appraisal for {$evaluation->employee?->full_name} via assistant",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        $scores = $evaluation->scores;
        $rated = $scores->whereNotNull('score');

        $criteria = $scores->take(self::MAX_CRITERIA)->map(fn (PerformanceScore $score): string => sprintf(
            '%s: %s',
            $score->label,
            $score->score === null ? 'not rated' : $score->formattedScore(),
        ))->all();

        $card = $this->appraisalCard($evaluation, 'insight', $this->tone($evaluation), $evaluation->result_label ?? ucfirst($evaluation->status));
        $card['meta'] = array_values(array_filter([
            $this->resultText($evaluation),
            ucfirst($evaluation->status),
            $rated->count().' of '.$scores->count().' criteria rated',
            $evaluation->evaluator ? 'Evaluator: '.trim($evaluation->evaluator->first_name.' '.$evaluation->evaluator->last_name) : null,
            ...$criteria,
            filled($evaluation->remarks) ? 'Remarks: '.Str::limit((string) $evaluation->remarks, 240) : null,
        ]));

        return ToolResult::found(
            "Read {$evaluation->employee?->full_name}'s appraisal",
            $evaluation->period?->name,
            [$card],
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : $this->currentCycle();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycles have been set up yet.');
        }

        $card = $this->card(
            kind: 'insight',
            tone: 'info',
            badge: ucfirst($period->status),
            title: $period->name,
            subtitle: 'Review cycle summary',
            meta: $this->cycleLines($period),
            id: $period->hashid,
        );

        return ToolResult::found("Read the {$period->name} cycle", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function listCycles(User $user, array $args): ToolResult
    {
        $cards = EvaluationPeriod::query()
            ->withCount('evaluations')
            ->recentFirst()
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (EvaluationPeriod $p): array => $this->card(
                kind: 'find',
                tone: $p->status === 'open' ? 'positive' : 'neutral',
                badge: ucfirst($p->status),
                title: $p->name,
                subtitle: trim(($p->start_date?->format('M j, Y') ?? '?').' – '.($p->end_date?->format('M j, Y') ?? '?')),
                meta: [$p->evaluations_count.' '.Str::plural('appraisal', $p->evaluations_count)],
                id: $p->hashid,
            ))
            ->all();

        return ToolResult::found('Listed review cycles', count($cards).' found', $cards);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function openAppraisal(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : EvaluationPeriod::query()->open()->recentFirst()->first();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycle is open.');
        }

        $template = null;

        if (filled($args['framework'] ?? null)) {
            $template = $this->locateFramework((string) $args['framework']);

            if ($template === null) {
                return ToolResult::error('Looked up the framework', 'No active appraisal framework matches that name.');
            }
        }

        try {
            $evaluation = $this->workflow->open($employee, $period, $template, $user, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Opened the appraisal', $e->getMessage());
        }

        $evaluation->load(['employee.department', 'employee.position', 'period:id,name']);

        return ToolResult::ok(
            "Opened {$employee->full_name}'s appraisal",
            "{$period->name} · {$evaluation->template_name}",
            $this->appraisalCard($evaluation, 'start', 'info', 'Opened'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function rateAppraisal(User $user, array $args): ToolResult
    {
        [$evaluation, $error] = $this->locateAppraisal($args, 'draft');

        if ($evaluation === null) {
            return ToolResult::error('Looked up the draft appraisal', $error);
        }

        $ratings = is_array($args['ratings'] ?? null) ? $args['ratings'] : [];
        $hasRemarks = filled($args['remarks'] ?? null);

        if ($ratings === [] && ! $hasRemarks) {
            return ToolResult::error('Rated the appraisal', 'Say which criteria to rate, and how.');
        }

        $scores = $evaluation->scores()->orderBy('sort_order')->orderBy('id')->get();
        $lines = [];

        foreach ($ratings as $rating) {
            $criterion = trim((string) ($rating['criterion'] ?? ''));
            $line = $this->matchCriterion($scores, $criterion);

            if ($line === null) {
                return ToolResult::error('Rated the appraisal', 'No criterion on this scorecard is called “'.Str::limit($criterion, 60).'”. Criteria: '.$scores->pluck('label')->take(12)->implode(', ').'.');
            }

            $entry = [];

            if (filled($rating['rating'] ?? null)) {
                $value = $this->ratingValue($line, (string) $rating['rating']);

                if ($value === null) {
                    return ToolResult::error('Rated the appraisal', "“{$line->label}” is rated ".RatingScales::descriptor($line->scale()).'; '.Str::limit((string) $rating['rating'], 40).' is not on that scale.');
                }

                $entry['score'] = $value;
            }

            if (filled($rating['remarks'] ?? null)) {
                $entry['remarks'] = Str::limit((string) $rating['remarks'], 2000, '');
            }

            if ($entry !== []) {
                $lines[$line->id] = [...($lines[$line->id] ?? []), ...$entry];
            }
        }

        try {
            $result = $this->workflow->rate(
                $evaluation,
                $lines,
                $hasRemarks ? Str::limit((string) $args['remarks'], 4000, '') : null,
                setRemarks: $hasRemarks,
                channel: ' via assistant',
            );
        } catch (AppraisalException $e) {
            return ToolResult::error('Rated the appraisal', $e->getMessage());
        }

        $evaluation->refresh()->load(['employee.department', 'employee.position', 'period:id,name']);

        return ToolResult::ok(
            "Rated {$evaluation->employee?->full_name}'s appraisal",
            $result->scored.' of '.$result->total.' criteria rated'.($result->percent !== null ? ' · running '.$this->percent($result->percent).'%' : ''),
            $this->appraisalCard($evaluation, 'edit', 'info', 'Rated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function submitAppraisal(User $user, array $args): ToolResult
    {
        [$evaluation, $error] = $this->locateAppraisal($args, 'draft');

        if ($evaluation === null) {
            return ToolResult::error('Looked up the draft appraisal', $error);
        }

        try {
            $this->workflow->submit($evaluation, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Submitted the appraisal', $e->getMessage());
        }

        return ToolResult::ok(
            "Submitted {$evaluation->employee?->full_name}'s appraisal",
            $this->resultText($evaluation->refresh()),
            $this->appraisalCard($evaluation, 'approve', 'positive', 'Submitted'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function acknowledgeAppraisal(User $user, array $args): ToolResult
    {
        [$evaluation, $error] = $this->locateAppraisal($args, 'submitted');

        if ($evaluation === null) {
            return ToolResult::error('Looked up the submitted appraisal', $error);
        }

        try {
            $this->workflow->acknowledge($evaluation, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Recorded the sign-off', $e->getMessage());
        }

        return ToolResult::ok(
            "Recorded {$evaluation->employee?->full_name}'s sign-off",
            $evaluation->period?->name,
            $this->appraisalCard($evaluation->refresh(), 'approve', 'positive', 'Acknowledged'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteDraft(User $user, array $args): ToolResult
    {
        [$evaluation, $error] = $this->locateAppraisal($args, 'draft');

        if ($evaluation === null) {
            return ToolResult::error('Looked up the draft appraisal', $error);
        }

        $card = $this->appraisalCard($evaluation, 'archive', 'warning', 'Deleted');
        $name = $evaluation->employee?->full_name;

        try {
            $this->workflow->discard($evaluation, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Deleted the draft', $e->getMessage());
        }

        return ToolResult::ok("Deleted {$name}'s draft appraisal", null, $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function launchCycle(User $user, array $args): ToolResult
    {
        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : EvaluationPeriod::query()->open()->recentFirst()->first();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycle is open.');
        }

        $departmentIds = null;
        $names = array_values(array_filter(array_map('strval', is_array($args['departments'] ?? null) ? $args['departments'] : [])));

        if ($names !== []) {
            $departmentIds = [];

            foreach ($names as $name) {
                $id = $this->resolveId(Department::query(), 'name', $name);

                if ($id === null) {
                    return ToolResult::error('Looked up the departments', 'No department is called “'.Str::limit($name, 60).'”.');
                }

                $departmentIds[] = $id;
            }
        }

        $pinned = null;

        if (filled($args['framework'] ?? null)) {
            $pinned = $this->locateFramework((string) $args['framework']);

            if ($pinned === null) {
                return ToolResult::error('Looked up the framework', 'No active appraisal framework matches that name.');
            }
        }

        try {
            $launch = $this->workflow->launch($period, $departmentIds, $pinned, $user, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Launched the cycle', $e->getMessage());
        }

        [$message] = $launch->message();

        return ToolResult::ok(
            "Launched {$period->name}",
            $message,
            $this->card(
                kind: 'start',
                tone: $launch->opened > 0 ? 'positive' : 'warning',
                badge: 'Launched',
                title: $period->name,
                subtitle: $message,
                meta: [
                    $launch->opened.' opened',
                    $launch->skipped > 0 ? $launch->skipped.' already had one' : null,
                    $launch->uncovered > 0 ? $launch->uncovered.' without a framework' : null,
                ],
                id: $period->hashid,
            ),
        );
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one employee for a name or number, or why not.
     *
     * @return array{0: Employee|null, 1: string}
     */
    private function resolveEmployee(string $needle): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say whose appraisal.'];
        }

        // An employee number is exact.
        $byNumber = Employee::query()->whereRaw('lower(employee_no) = ?', [Str::lower($needle)])->first();

        if ($byNumber !== null) {
            return [$byNumber, ''];
        }

        $matches = $this->matchByTokens(Employee::query(), $needle)->limit(2)->get();

        return match ($matches->count()) {
            0 => [null, 'No matching employee found.'],
            1 => [$matches->first(), ''],
            default => [null, 'More than one person matches “'.Str::limit($needle, 60).'”. Use their full name or employee number.'],
        };
    }

    /**
     * The appraisal the arguments mean: the person's in the named cycle, else
     * their latest (in the given status, when the action needs one).
     *
     * @param  array<string, mixed>  $args
     * @return array{0: PerformanceEvaluation|null, 1: string}
     */
    private function locateAppraisal(array $args, ?string $status = null): array
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return [null, $error];
        }

        $period = null;

        if (filled($args['cycle'] ?? null)) {
            $period = $this->locateCycle((string) $args['cycle']);

            if ($period === null) {
                return [null, 'No review cycle matches that name.'];
            }
        }

        $evaluation = PerformanceEvaluation::query()
            ->with(['employee.department', 'employee.position', 'period:id,name,status'])
            ->where('employee_id', $employee->id)
            ->when($period !== null, fn (Builder $q) => $q->where('evaluation_period_id', $period->id))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->first();

        if ($evaluation === null) {
            $what = $status !== null ? "a {$status} appraisal" : 'an appraisal';

            return [null, "{$employee->full_name} has no {$what}".($period !== null ? " in {$period->name}" : '').'.'];
        }

        return [$evaluation, ''];
    }

    private function locateCycle(string $name): ?EvaluationPeriod
    {
        $id = $this->resolveId(EvaluationPeriod::query(), 'name', $name);

        if ($id !== null) {
            return EvaluationPeriod::find($id);
        }

        $like = EvaluationPeriod::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return EvaluationPeriod::query()
            ->where('name', $like, '%'.addcslashes(trim($name), '%_\\').'%')
            ->recentFirst()
            ->first();
    }

    private function currentCycle(): ?EvaluationPeriod
    {
        return EvaluationPeriod::query()->open()->recentFirst()->first()
            ?? EvaluationPeriod::query()->recentFirst()->first();
    }

    private function locateFramework(string $name): ?ReviewTemplate
    {
        $frameworks = $this->templates->active();
        $needle = Str::lower(trim($name));

        return $frameworks->first(fn (ReviewTemplate $t): bool => Str::lower($t->name) === $needle)
            ?? $frameworks->first(fn (ReviewTemplate $t): bool => str_contains(Str::lower($t->name), $needle));
    }

    /**
     * A scorecard line by its criterion's name: exact, then unique partial.
     *
     * @param  Collection<int, PerformanceScore>  $scores
     */
    private function matchCriterion(Collection $scores, string $name): ?PerformanceScore
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        $exact = $scores->first(fn (PerformanceScore $s): bool => Str::lower((string) $s->label) === $needle);

        if ($exact !== null) {
            return $exact;
        }

        $partial = $scores->filter(fn (PerformanceScore $s): bool => str_contains(Str::lower((string) $s->label), $needle));

        return $partial->count() === 1 ? $partial->first() : null;
    }

    /**
     * A rating as the line's own scale takes it: a number on it, or one of its
     * level names. Null when it is neither — never clamped into range.
     */
    private function ratingValue(PerformanceScore $line, string $rating): ?float
    {
        $rating = trim(rtrim(trim($rating), '%'));

        if (is_numeric($rating)) {
            $value = (float) $rating;

            return $line->acceptsScore($value) ? $value : null;
        }

        foreach ($line->scale()['levels'] ?? [] as $level) {
            if (Str::lower($level['label']) === Str::lower($rating)) {
                return (float) $level['value'];
            }
        }

        return null;
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * A cycle in lines: coverage, statuses, average, the band spread and the
     * departments that stand out — the overview screen, read aloud.
     *
     * @return list<string|null>
     */
    private function cycleLines(EvaluationPeriod $period): array
    {
        $evaluations = PerformanceEvaluation::query()
            ->with(['employee:id,department_id', 'employee.department:id,name'])
            ->forPeriod($period->id)
            ->get();

        $eligible = Employee::query()->where('employment_status', 'active')->count();
        $stats = $this->calibration->summary($evaluations, $eligible);
        $bands = $this->calibration->distribution($evaluations);
        $departments = $this->calibration->byDepartment($evaluations);
        $average = $stats['average_percent'];

        $outliers = $average === null ? [] : collect($departments)
            ->filter(fn (array $d): bool => $d['average_percent'] !== null && $d['completed'] >= 2)
            ->map(fn (array $d): array => [...$d, 'gap' => round($d['average_percent'] - $average, 1)])
            ->filter(fn (array $d): bool => abs($d['gap']) >= 5)
            ->sortByDesc(fn (array $d): float => abs($d['gap']))
            ->take(3)
            ->map(fn (array $d): string => sprintf('%s %s%% (%s%s pts vs the cycle)', $d['department'], $this->percent($d['average_percent']), $d['gap'] > 0 ? '+' : '', $this->percent($d['gap'])))
            ->values()
            ->all();

        return [
            sprintf(
                'Status: %s, %s to %s.',
                $period->status,
                $period->start_date?->toDateString() ?? '?',
                $period->end_date?->toDateString() ?? '?',
            ),
            sprintf(
                'Coverage: %d appraisals for %d active employees (%s%%) — %d draft, %d submitted, %d acknowledged.',
                $stats['total'],
                $eligible,
                $stats['coverage'] ?? 0,
                $stats['draft'],
                $stats['submitted'],
                $stats['acknowledged'],
            ),
            $average !== null ? 'Average attainment of completed appraisals: '.$this->percent($average).'%.' : 'No appraisal in this cycle is complete yet.',
            $bands !== [] ? 'Results by band: '.collect($bands)->map(fn (array $b): string => "{$b['label']} {$b['count']} ({$b['share']}%)")->implode(', ').'.' : null,
            $outliers !== [] ? 'Departments rating furthest from the cycle average: '.implode('; ', $outliers).'.' : null,
        ];
    }

    private function describeAppraisal(PerformanceEvaluation $e): string
    {
        $cycle = $e->period?->name ?? 'Unknown cycle';

        if ($e->status === 'draft') {
            return sprintf(
                '%s: draft on %s — %d of %d criteria rated%s.',
                $cycle,
                $e->template_name ?? 'its framework',
                (int) $e->rated_count,
                (int) $e->scores_count,
                $e->overall_percent !== null ? ', running '.$this->percent($e->overall_percent).'%' : '',
            );
        }

        return sprintf('%s: %s — %s (%s).', $cycle, $e->status, $this->resultText($e), $e->template_name ?? 'framework');
    }

    private function forecastLine(User $user, Employee $employee): ?string
    {
        if ($user->cannot('analytics.performance.view')) {
            return null;
        }

        $forecast = PerformanceForecast::query()->where('employee_id', $employee->id)->latest('id')->first();

        if ($forecast === null) {
            return null;
        }

        return sprintf(
            'Latest performance forecast: %s on the 1–5 index%s, band %s.',
            rtrim(rtrim(number_format((float) $forecast->predicted_rating, 2), '0'), '.'),
            $forecast->predicted_low !== null && $forecast->predicted_high !== null
                ? ' (likely '.round((float) $forecast->predicted_low, 1).'–'.round((float) $forecast->predicted_high, 1).')'
                : '',
            (string) $forecast->band,
        );
    }

    private function resultText(PerformanceEvaluation $e): string
    {
        if ($e->overall_percent === null) {
            return 'no result yet';
        }

        return trim(($e->result_label ? $e->result_label.' · ' : '').$this->percent($e->overall_percent).'%');
    }

    private function tone(PerformanceEvaluation $e): string
    {
        $band = collect($e->bandList())->firstWhere('key', $e->result_band);

        return match ($band['tone'] ?? null) {
            'positive', 'success', 'emerald' => 'positive',
            'warning', 'amber' => 'warning',
            'danger', 'rose', 'negative' => 'danger',
            default => 'info',
        };
    }

    private function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function appraisalCard(PerformanceEvaluation $e, string $kind, string $tone, string $badge): array
    {
        $employee = $e->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: trim(($e->period?->name ?? 'Cycle').' · '.($e->template_name ?? 'Appraisal')),
            meta: [$this->resultText($e), $e->status],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $e->hashid,
        );
    }
}
