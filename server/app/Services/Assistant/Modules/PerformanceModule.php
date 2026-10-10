<?php

namespace App\Services\Assistant\Modules;

use App\Models\AppraisalReview;
use App\Models\CalibrationSession;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\GoalTemplate;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceGoal;
use App\Models\PerformanceScore;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Ml\PredictionWording;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\CalibrationWorkflow;
use App\Support\Performance\FeedbackSummary;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\GoalWorkflow;
use App\Support\Performance\PerformanceCalibration;
use App\Support\Performance\RatingScales;
use App\Support\Performance\ReviewWorkflow;
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
 * Disclosure follows the screens. Reading anyone's appraisal needs
 * `performance.view`, and an ML forecast additionally needs
 * `analytics.performance.view`; writes need `performance.manage`. Taking part
 * (`performance.participate`, ADRs 0072, 0073) reads one's own appraisals —
 * their results only once shared — acknowledges them, lists the reviews asked of
 * one, and reads and checks in on one's own goals. Reviews are read pooled
 * ({@see FeedbackSummary}), exactly as the scorecard shows them. Anything that
 * locks, signs off, deletes, launches or tells someone else waits for the
 * user's Confirm (ADR 0049).
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
        private readonly ReviewWorkflow $reviews,
        private readonly GoalWorkflow $goals,
        private readonly FeedbackSummary $feedback,
    ) {}

    public function key(): string
    {
        return 'performance';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('performance.view') || $user->can('performance.participate');
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
            'request_reviews' => 'requestReviews',
            'find_goals' => 'findGoals',
            'set_goal' => 'setGoal',
            'find_calibration_sessions' => 'findSessions',
            'find_my_appraisals' => 'myAppraisals',
            'acknowledge_my_appraisal' => 'acknowledgeMine',
            'find_my_reviews' => 'myReviews',
            'find_my_goals' => 'myGoals',
            'check_in_goal' => 'checkInGoal',
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
            'request_reviews' => 'performance.manage',
            'find_goals' => 'performance.view',
            'set_goal' => 'performance.manage',
            'find_calibration_sessions' => 'performance.view',
            'find_my_appraisals' => 'performance.participate',
            'acknowledge_my_appraisal' => 'performance.participate',
            'find_my_reviews' => 'performance.participate',
            'find_my_goals' => 'performance.participate',
            'check_in_goal' => 'performance.participate',
        ];
    }

    protected function confirmTools(): array
    {
        // Submitting locks a result, signing off makes it final, deleting and
        // launching a whole cycle are not done on a misread; asking for reviews
        // and setting goals tell other people.
        return [
            'submit_appraisal',
            'acknowledge_appraisal',
            'delete_draft_appraisal',
            'launch_review_cycle',
            'request_reviews',
            'set_goal',
            'acknowledge_my_appraisal',
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
            return $this->denied(match ($permission) {
                'performance.manage' => 'change appraisals, ask for reviews or set goals',
                'performance.participate' => 'take part in your own appraisal',
                default => 'view appraisals',
            });
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $view = '';

        if ($this->allows($user, 'performance.view')) {
            $cycles = $this->catalog(EvaluationPeriod::query()->recentFirst()->limit(6)->get(['name', 'status'])
                ->map(fn (EvaluationPeriod $p): string => "{$p->name} ({$p->status})"));

            $view = <<<TXT

            - find_appraisals lists appraisals (by person, cycle, status); get_appraisal reads one scorecard in full, with the reviews (self and manager named; peers and direct reports pooled, shown once two answer), the person's goals and any calibration; performance_summary reads a cycle — coverage, average, the spread across bands, and per-department calibration; list_review_cycles lists cycles; find_goals lists a cycle's goals (by person, health, status); find_calibration_sessions lists calibration sessions.
              Review cycles: {$cycles}
            TXT;
        }

        $manage = $this->allows($user, 'performance.manage')
            ? <<<'TXT'

            - open_appraisal opens one for a person in a cycle (the open cycle when none is named), on the framework that covers them unless one is named.
            - rate_appraisal saves ratings on a DRAFT: one entry per criterion, by its name, with a rating that is a number on that criterion's own scale or one of its level names ("Proficient"). Out-of-scale ratings are refused. Nobody rates their own appraisal.
            - submit_appraisal locks it (every criterion must be rated) and shares it with the employee unless a calibration session holds it; acknowledge_appraisal records a sign-off on the employee's behalf (they can acknowledge it themselves); delete_draft_appraisal discards a draft; launch_review_cycle opens appraisals for everyone active (or named departments), optionally asking for self-reviews and managers' reviews. These wait for the user's confirmation.
            - request_reviews asks people to review a DRAFT appraisal — named colleagues, and/or the person themself, their manager, their direct reports. What each is to the person comes from the reporting line. set_goal sets one goal for one or more people in a cycle (written out, or from the goal library by name). Both tell people, so they wait for the user's confirmation. Calibrating ratings and writing reviews are done on the screens.
            TXT
            : '';

        $participate = $this->allows($user, 'performance.participate')
            ? <<<'TXT'

            - For the user's OWN performance: find_my_appraisals lists their appraisals (a result shows only once it is shared with them); acknowledge_my_appraisal acknowledges a shared one, with an optional comment — it says they have read it, not that they agree — and waits for the user's confirmation; find_my_reviews lists the reviews they are asked to write (they write them on the Reviews screen); find_my_goals lists their goals with progress; check_in_goal records where one of their own goals stands (value, on_track / at_risk / off_track, note).
            TXT
            : '';

        return <<<TXT
        PERFORMANCE — appraisals scored against the company's own frameworks. A result is attainment on 0–100 reported in the company's own rating words (its "band"), plus a 1–5 index. Status runs draft → submitted (shared with the employee, unless a calibration session holds it) → acknowledged. People around an appraisal can be asked to review it; employees have goals with check-ins.
        - Pass people by name or employee number, and cycles by name.{$view}{$manage}{$participate}
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
                        'self_reviews' => ['type' => 'BOOLEAN', 'description' => 'Ask each person for a self-review.'],
                        'manager_reviews' => ['type' => 'BOOLEAN', 'description' => 'Ask each person\'s manager for a review.'],
                    ],
                ],
            ],
            [
                'name' => 'request_reviews',
                'description' => "Ask people to review an employee's draft appraisal: named colleagues, and/or the employee themself, their manager, their direct reports.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Whose appraisal — name or employee number.'],
                        'cycle' => $cycle,
                        'reviewers' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Colleagues to ask, by name or employee number.'],
                        'self' => ['type' => 'BOOLEAN', 'description' => 'Ask the employee for a self-review.'],
                        'manager' => ['type' => 'BOOLEAN', 'description' => 'Ask their manager.'],
                        'direct_reports' => ['type' => 'BOOLEAN', 'description' => 'Ask everyone who reports to them.'],
                        'due' => ['type' => 'STRING', 'description' => 'Due date, YYYY-MM-DD; defaults to the cycle end.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'find_goals',
                'description' => "List a review cycle's goals with their progress and health, optionally for one person or by health or status.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.'],
                        'health' => ['type' => 'STRING', 'enum' => PerformanceGoal::HEALTHS],
                        'status' => ['type' => 'STRING', 'enum' => PerformanceGoal::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'set_goal',
                'description' => 'Set one goal for one or more employees in a review cycle — written out, or from the goal library by name.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employees' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Who it is for, by name or employee number.'],
                        'cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.'],
                        'title' => ['type' => 'STRING', 'description' => 'The goal, in a line.'],
                        'library' => ['type' => 'STRING', 'description' => 'A goal-library entry to start from, by name.'],
                        'measure' => ['type' => 'STRING', 'enum' => PerformanceGoal::MEASURES, 'description' => 'percent: progress to 100%; number: from a start to a target.'],
                        'start' => ['type' => 'NUMBER', 'description' => 'Where a number goal starts.'],
                        'target' => ['type' => 'NUMBER', 'description' => 'The number to reach.'],
                        'unit' => ['type' => 'STRING', 'description' => 'What the number counts — deals, tickets, PHP.'],
                        'due' => ['type' => 'STRING', 'description' => 'Due date, YYYY-MM-DD.'],
                    ],
                    'required' => ['employees'],
                ],
            ],
            [
                'name' => 'find_calibration_sessions',
                'description' => 'List the calibration sessions of a review cycle: what each covers, when, how many ratings it moved, and whether it is open.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.']],
                ],
            ],
            [
                'name' => 'find_my_appraisals',
                'description' => "List the user's own appraisals. A result shows only once it has been shared with them.",
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'acknowledge_my_appraisal',
                'description' => "Acknowledge the user's own shared appraisal, optionally with a comment. It says they have read it, not that they agree.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the one waiting for acknowledgement.'],
                        'comment' => ['type' => 'STRING', 'description' => 'What they want to say about it.'],
                    ],
                ],
            ],
            [
                'name' => 'find_my_reviews',
                'description' => 'List the reviews the user is asked to write — their self-review and reviews of colleagues — waiting ones first.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'find_my_goals',
                'description' => "List the user's own goals for a review cycle, with progress and how each is going.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['cycle' => ['type' => 'STRING', 'description' => 'Review cycle name; defaults to the open cycle.']],
                ],
            ],
            [
                'name' => 'check_in_goal',
                'description' => "Record where one of the user's own goals stands: the value now, how it is going, and a note.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'goal' => ['type' => 'STRING', 'description' => 'The goal, by its title.'],
                        'value' => ['type' => 'NUMBER', 'description' => 'Where it stands now: a percentage for a percent goal, else the number.'],
                        'health' => ['type' => 'STRING', 'enum' => PerformanceGoal::HEALTHS],
                        'note' => ['type' => 'STRING', 'description' => 'What moved, or what is in the way.'],
                    ],
                    'required' => ['goal', 'value', 'health'],
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

        // Someone who only takes part reads their own through
        // find_my_appraisals, which shows a result only once it is shared.
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
            'self-review', 'peer review', '360', 'goals', 'check-in',
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
            $evaluation->isCalibrated() ? "Calibrated from “{$evaluation->scored_label}”" : null,
            ucfirst($evaluation->status).($evaluation->status === 'submitted' && $evaluation->shared_at === null ? ' (held for calibration)' : ''),
            $rated->count().' of '.$scores->count().' criteria rated',
            $evaluation->evaluator ? 'Evaluator: '.trim($evaluation->evaluator->first_name.' '.$evaluation->evaluator->last_name) : null,
            ...$criteria,
            filled($evaluation->remarks) ? 'Remarks: '.Str::limit((string) $evaluation->remarks, 240) : null,
            ...$this->reviewLines($evaluation, $scores),
            $this->goalLine($evaluation),
            filled($evaluation->employee_comment) ? 'Employee’s comment on acknowledging: '.Str::limit((string) $evaluation->employee_comment, 240) : null,
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
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''), 'Say whose appraisal.');

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
                by: $user,
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
            $this->workflow->submit($evaluation, ' via assistant', $user);
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
            $this->workflow->acknowledge($evaluation, ' via assistant', $user);
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
            $this->workflow->discard($evaluation, ' via assistant', $user);
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
            $launch = $this->workflow->launch(
                $period,
                $departmentIds,
                $pinned,
                $user,
                ' via assistant',
                selfReviews: (bool) ($args['self_reviews'] ?? false),
                managerReviews: (bool) ($args['manager_reviews'] ?? false),
            );
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
                    $launch->reviews > 0 ? $launch->reviews.' '.Str::plural('review', $launch->reviews).' asked for' : null,
                ],
                id: $period->hashid,
            ),
        );
    }

    // ── Reviews, goals and calibration (ADRs 0072, 0073) ────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function requestReviews(User $user, array $args): ToolResult
    {
        [$evaluation, $error] = $this->locateAppraisal($args, 'draft');

        if ($evaluation === null) {
            return ToolResult::error('Looked up the draft appraisal', $error);
        }

        if ($evaluation->isAbout($user)) {
            return ToolResult::error('Asked for reviews', AppraisalWorkflow::OWN_APPRAISAL);
        }

        $subject = $evaluation->employee;
        $reviewers = collect();
        $names = is_array($args['reviewers'] ?? null) ? $args['reviewers'] : [];

        if ($names !== []) {
            [$named, $error] = $this->resolveEmployees($names, 10);

            if ($named === null) {
                return ToolResult::error('Looked up the reviewers', $error);
            }

            $reviewers = $reviewers->merge($named);
        }

        if ((bool) ($args['self'] ?? false)) {
            $reviewers->push($subject);
        }

        if ((bool) ($args['manager'] ?? false)) {
            if ($subject->manager === null) {
                return ToolResult::error('Looked up the manager', "{$subject->full_name} has no manager on record.");
            }

            $reviewers->push($subject->manager);
        }

        if ((bool) ($args['direct_reports'] ?? false)) {
            $reviewers = $reviewers->merge($subject->reports()->where('employment_status', 'active')->get());
        }

        $reviewers = $reviewers->filter()->unique('id')->values();

        if ($reviewers->isEmpty()) {
            return ToolResult::error('Asked for reviews', 'Say who to ask: colleagues by name, the person themself, their manager or their reports.');
        }

        $due = filled($args['due'] ?? null) ? (string) $args['due'] : null;

        if ($due !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < today()->toDateString())) {
            return ToolResult::error('Asked for reviews', 'The due date must be today or later, as YYYY-MM-DD.');
        }

        try {
            $outcome = $this->reviews->request($evaluation, $reviewers, $user, $due, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Asked for reviews', $e->getMessage());
        }

        $asked = collect($outcome['requested']);

        if ($asked->isEmpty()) {
            return ToolResult::error('Asked for reviews', implode(' ', $outcome['refused']));
        }

        return ToolResult::ok(
            "Asked {$asked->count()} ".Str::plural('person', $asked->count())." to review {$subject->full_name}",
            $outcome['refused'] === [] ? null : implode(' ', $outcome['refused']),
            $this->card(
                kind: 'start',
                tone: $outcome['refused'] === [] ? 'positive' : 'warning',
                badge: 'Asked',
                title: $subject->full_name,
                subtitle: ($evaluation->period?->name ?? 'Cycle').' · reviews',
                meta: [
                    ...$asked->map(fn (AppraisalReview $r): string => $r->reviewer?->full_name.' ('.ReviewWorkflow::label($r->relationship).')')->all(),
                    ...array_map(fn (string $why): string => 'Not asked: '.$why, array_values($outcome['refused'])),
                ],
                id: $evaluation->hashid,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findGoals(User $user, array $args): ToolResult
    {
        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : $this->currentCycle();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycles have been set up yet.');
        }

        $needle = trim((string) ($args['employee'] ?? ''));
        $health = in_array($args['health'] ?? null, PerformanceGoal::HEALTHS, true) ? $args['health'] : null;
        $status = in_array($args['status'] ?? null, PerformanceGoal::STATUSES, true) ? $args['status'] : null;

        $goals = PerformanceGoal::query()
            ->forPeriod($period->id)
            ->with('employee')
            ->when($needle !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $this->matchByTokens($e, $needle)))
            ->when($health !== null, fn (Builder $q) => $q->where('health', $health)->where('status', 'active'))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        return ToolResult::found(
            "Searched {$period->name} goals",
            $goals->count().' found',
            $goals->map(fn (PerformanceGoal $g): array => $this->goalCard($g, true))->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setGoal(User $user, array $args): ToolResult
    {
        [$employees, $error] = $this->resolveEmployees(is_array($args['employees'] ?? null) ? $args['employees'] : [], 20);

        if ($employees === null) {
            return ToolResult::error('Looked up who the goal is for', $error);
        }

        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : EvaluationPeriod::query()->open()->recentFirst()->first();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycle is open.');
        }

        $template = null;

        if (filled($args['library'] ?? null)) {
            $id = $this->resolveId(GoalTemplate::query()->active(), 'name', (string) $args['library']);
            $template = $id === null ? null : GoalTemplate::find($id);

            if ($template === null) {
                return ToolResult::error('Looked up the library goal', 'No goal in the library is called “'.Str::limit((string) $args['library'], 60).'”.');
            }
        }

        $data = array_filter([
            'title' => filled($args['title'] ?? null) ? Str::limit((string) $args['title'], 255, '') : null,
            'measure' => $args['measure'] ?? null,
            'start_value' => $args['start'] ?? null,
            'target_value' => $args['target'] ?? null,
            'unit' => $args['unit'] ?? null,
            'due_on' => filled($args['due'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['due']) ? (string) $args['due'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $goals = $this->goals->set($employees, $period, $data, $template, $user, channel: ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Set the goal', $e->getMessage());
        }

        $first = $goals[0];

        return ToolResult::ok(
            "Set “{$first->title}” for ".(count($goals) === 1 ? $first->employee?->full_name : count($goals).' people'),
            $period->name,
            $this->goalCard($first, true),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findSessions(User $user, array $args): ToolResult
    {
        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : $this->currentCycle();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycles have been set up yet.');
        }

        $sessions = CalibrationSession::query()
            ->where('evaluation_period_id', $period->id)
            ->withCount('adjustments')
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        return ToolResult::found(
            "Listed {$period->name} calibration sessions",
            $sessions->count().' found',
            $sessions->map(fn (CalibrationSession $session): array => $this->card(
                kind: 'find',
                tone: $session->isOpen() ? 'info' : 'neutral',
                badge: ucfirst($session->status),
                title: $session->name,
                subtitle: CalibrationWorkflow::scopeLabel($session),
                meta: [
                    $session->scheduled_for ? 'Meets '.$session->scheduled_for->format('M j, Y') : null,
                    $session->adjustments_count.' '.Str::plural('rating', $session->adjustments_count).' moved',
                    $session->isOpen() ? 'Holding back appraisals submitted inside it' : null,
                ],
                id: $session->hashid,
            ))->all(),
        );
    }

    // ── Taking part: the user's own (ADRs 0072, 0073) ───────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function myAppraisals(User $user, array $args): ToolResult
    {
        if (($me = $user->employee()->first()) === null) {
            return ToolResult::error('Looked up your appraisals', 'Your account isn’t linked to an employee record.');
        }

        $evaluations = PerformanceEvaluation::query()
            ->forEmployee($me)
            ->with('period:id,name')
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        return ToolResult::found('Read your appraisals', $evaluations->count().' found', $evaluations->map(fn (PerformanceEvaluation $e): array => $this->card(
            kind: 'find',
            tone: $e->isShared() && $e->status === 'submitted' ? 'warning' : 'neutral',
            badge: $e->status === 'acknowledged' ? 'Acknowledged' : ($e->isShared() ? 'Ready to acknowledge' : 'In progress'),
            title: $e->period?->name ?? 'Review cycle',
            subtitle: $e->template_name ?? 'Appraisal',
            // A result the employee may not see yet stays out of the reply.
            meta: [
                $e->isShared() ? $this->resultText($e) : ($e->status === 'submitted' ? 'Being calibrated — shared once that is done' : 'Still being rated'),
                $e->acknowledged_at ? 'Acknowledged '.$e->acknowledged_at->format('M j, Y') : null,
            ],
            id: $e->hashid,
        ))->all());
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function acknowledgeMine(User $user, array $args): ToolResult
    {
        if (($me = $user->employee()->first()) === null) {
            return ToolResult::error('Acknowledged your appraisal', 'Your account isn’t linked to an employee record.');
        }

        $period = null;

        if (filled($args['cycle'] ?? null) && ($period = $this->locateCycle((string) $args['cycle'])) === null) {
            return ToolResult::error('Looked up the review cycle', 'No review cycle matches that name.');
        }

        $evaluation = PerformanceEvaluation::query()
            ->forEmployee($me)
            ->with(['period:id,name', 'employee'])
            ->where('status', 'submitted')
            ->whereNotNull('shared_at')
            ->when($period !== null, fn (Builder $q) => $q->where('evaluation_period_id', $period->id))
            ->latest('id')
            ->first();

        if ($evaluation === null) {
            return ToolResult::error('Acknowledged your appraisal', 'You have no shared appraisal waiting to be acknowledged'.($period ? " in {$period->name}" : '').'.');
        }

        try {
            $this->workflow->acknowledge(
                $evaluation,
                ' via assistant',
                $user,
                filled($args['comment'] ?? null) ? Str::limit((string) $args['comment'], 2000, '') : null,
            );
        } catch (AppraisalException $e) {
            return ToolResult::error('Acknowledged your appraisal', $e->getMessage());
        }

        return ToolResult::ok(
            "Acknowledged your {$evaluation->period?->name} appraisal",
            $this->resultText($evaluation),
            $this->card(
                kind: 'approve',
                tone: 'positive',
                badge: 'Acknowledged',
                title: $evaluation->period?->name ?? 'Your appraisal',
                subtitle: $evaluation->template_name ?? 'Appraisal',
                meta: [$this->resultText($evaluation), filled($args['comment'] ?? null) ? 'With your comment' : null],
                id: $evaluation->hashid,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function myReviews(User $user, array $args): ToolResult
    {
        if (($me = $user->employee()->first()) === null) {
            return ToolResult::error('Looked up your reviews', 'Your account isn’t linked to an employee record.');
        }

        $reviews = AppraisalReview::query()
            ->byReviewer($me)
            ->where('status', '!=', 'cancelled')
            ->with(['evaluation:id,employee_id,evaluation_period_id,status', 'evaluation.employee', 'evaluation.period:id,name'])
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        return ToolResult::found('Read the reviews asked of you', $reviews->where('status', 'pending')->count().' waiting', $reviews->map(fn (AppraisalReview $r): array => $this->card(
            kind: 'find',
            tone: $r->isOverdue() ? 'warning' : ($r->isPending() ? 'info' : 'neutral'),
            badge: $r->isPending() ? ($r->isOverdue() ? 'Overdue' : 'Waiting') : ucfirst($r->status),
            title: $r->isSelf() ? 'Your self-review' : ($r->evaluation?->employee?->full_name ?? 'A colleague'),
            subtitle: trim(($r->evaluation?->period?->name ?? 'Cycle').($r->isSelf() ? '' : ' · as their '.ReviewWorkflow::label($r->relationship))),
            meta: [
                $r->isPending() && $r->due_on ? 'Due '.$r->due_on->format('M j, Y') : null,
                $r->isPending() ? 'Write it on the Reviews screen' : null,
            ],
            id: $r->hashid,
        ))->all());
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function myGoals(User $user, array $args): ToolResult
    {
        if (($me = $user->employee()->first()) === null) {
            return ToolResult::error('Looked up your goals', 'Your account isn’t linked to an employee record.');
        }

        $period = filled($args['cycle'] ?? null) ? $this->locateCycle((string) $args['cycle']) : $this->currentCycle();

        if ($period === null) {
            return ToolResult::error('Looked up the review cycle', filled($args['cycle'] ?? null) ? 'No review cycle matches that name.' : 'No review cycles have been set up yet.');
        }

        $goals = PerformanceGoal::query()->forEmployee($me)->forPeriod($period->id)->with('employee')->latest('id')->get();
        $attainment = GoalProgress::attainment($goals);

        return ToolResult::found(
            "Read your {$period->name} goals",
            $goals->count().' found'.($attainment !== null ? ' · '.$this->percent($attainment).'% overall' : ''),
            $goals->map(fn (PerformanceGoal $g): array => $this->goalCard($g, false))->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function checkInGoal(User $user, array $args): ToolResult
    {
        if (($me = $user->employee()->first()) === null) {
            return ToolResult::error('Checked in', 'Your account isn’t linked to an employee record.');
        }

        $goals = PerformanceGoal::query()->forEmployee($me)->where('status', 'active')->with(['employee', 'period'])->get();
        $needle = Str::lower(trim((string) ($args['goal'] ?? '')));
        $goal = $goals->first(fn (PerformanceGoal $g): bool => Str::lower($g->title) === $needle);

        if ($goal === null) {
            $partial = $goals->filter(fn (PerformanceGoal $g): bool => $needle !== '' && str_contains(Str::lower($g->title), $needle));
            $goal = $partial->count() === 1 ? $partial->first() : null;
        }

        if ($goal === null) {
            return ToolResult::error('Looked up the goal', 'None of your active goals is called “'.Str::limit((string) ($args['goal'] ?? ''), 60).'”. Your goals: '.($goals->pluck('title')->take(8)->implode(', ') ?: 'none').'.');
        }

        $value = trim(rtrim(trim((string) ($args['value'] ?? '')), '%'));
        $health = (string) ($args['health'] ?? '');

        if (! is_numeric($value)) {
            return ToolResult::error('Checked in', 'Say where the goal stands now, as a number.');
        }

        try {
            $this->goals->checkIn($goal, (float) $value, $health, filled($args['note'] ?? null) ? Str::limit((string) $args['note'], 1000, '') : null, $user, ' via assistant');
        } catch (AppraisalException $e) {
            return ToolResult::error('Checked in', $e->getMessage());
        }

        $goal->refresh()->load('employee');

        return ToolResult::ok("Checked in on “{$goal->title}”", null, $this->goalCard($goal, false));
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * The appraisal the arguments mean: the person's in the named cycle, else
     * their latest (in the given status, when the action needs one).
     *
     * @param  array<string, mixed>  $args
     * @return array{0: PerformanceEvaluation|null, 1: string}
     */
    private function locateAppraisal(array $args, ?string $status = null): array
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''), 'Say whose appraisal.');

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
     * The reviews of an appraisal in lines, read exactly as the scorecard shows
     * them: who answered, and the comparison per criterion — self and manager
     * named, peers and direct reports pooled and only once two have answered.
     *
     * @param  Collection<int, PerformanceScore>  $scores
     * @return list<string>
     */
    private function reviewLines(PerformanceEvaluation $evaluation, Collection $scores): array
    {
        $summary = $this->feedback->for($evaluation);

        if ($summary['counts']['asked'] === 0) {
            return [];
        }

        $columns = collect($summary['columns']);
        $lines = [sprintf(
            'Reviews: %d of %d answered (%s).',
            $summary['counts']['submitted'],
            $summary['counts']['asked'],
            $columns->map(fn (array $c): string => "{$c['label']} {$c['answered']}/{$c['asked']}".($c['shown'] ? '' : ' — not shown until 2 answer'))->implode(', '),
        )];

        $shown = $columns->where('shown', true)->pluck('label', 'key');

        foreach ($summary['lines'] as $line) {
            $values = collect($line['values'])
                ->filter()
                ->map(fn (array $v, string $key): string => Str::lower((string) $shown->get($key)).' '.$v['formatted'])
                ->implode(', ');

            if ($values !== '') {
                $lines[] = 'Review ratings — '.($scores->firstWhere('id', $line['id'])?->label ?? 'criterion').': '.$values.'.';
            }
        }

        foreach (array_slice($summary['comments'], 0, 4) as $comment) {
            $who = $comment['by'] ?? 'A '.ReviewWorkflow::label($comment['relationship']);
            $lines[] = trim($who.' wrote: '.Str::limit(trim(($comment['strengths'] ?? '').' '.($comment['improvements'] ?? '')), 200));
        }

        return $lines;
    }

    /**
     * The person's goals for the appraisal's cycle, in a line.
     */
    private function goalLine(PerformanceEvaluation $evaluation): ?string
    {
        $goals = PerformanceGoal::query()
            ->forEmployee($evaluation->employee_id)
            ->forPeriod($evaluation->evaluation_period_id)
            ->get();

        if ($goals->isEmpty()) {
            return null;
        }

        $attainment = GoalProgress::attainment($goals);

        return sprintf(
            'Goals this cycle: %d (%s achieved)%s.',
            $goals->count(),
            $goals->where('status', 'achieved')->count(),
            $attainment === null ? '' : ', '.$this->percent($attainment).'% attainment',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function goalCard(PerformanceGoal $goal, bool $withOwner): array
    {
        $format = fn (float $value): string => GoalProgress::format($value, $goal->measure, $goal->unit);

        return $this->card(
            kind: 'find',
            tone: match (true) {
                $goal->status === 'achieved', $goal->health === 'on_track' => 'positive',
                $goal->health === 'at_risk' => 'warning',
                $goal->health === 'off_track', $goal->status === 'missed' => 'danger',
                default => 'neutral',
            },
            badge: $goal->status === 'active' ? ($goal->health ? Str::headline($goal->health) : 'No check-in yet') : ucfirst($goal->status),
            title: $goal->title,
            subtitle: $withOwner ? ($goal->employee?->full_name ?? 'Employee') : ($goal->due_on ? 'Due '.$goal->due_on->format('M j, Y') : 'No due date'),
            meta: [
                $this->percent($goal->progress()).'% — '.$format((float) $goal->current_value).', target '.$format((float) $goal->target_value),
                $goal->last_check_in_at ? 'Last check-in '.$goal->last_check_in_at->format('M j, Y') : 'Not checked in on yet',
                $goal->isStale() ? 'No check-in for a month' : null,
            ],
            avatar: $withOwner && $goal->employee
                ? ['name' => $goal->employee->full_name, 'initials' => $goal->employee->initials(), 'photo' => $goal->employee->photo_url]
                : null,
            id: $goal->hashid,
        );
    }

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

        // Ratings are attainment on 0–100 since ADR 0045 — never the old 1–5 index.
        return 'Latest performance forecast: '.PredictionWording::forecast($forecast).'.';
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
