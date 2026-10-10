<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationPeriodResource;
use App\Http\Resources\PerformanceEvaluationResource;
use App\Http\Resources\PerformanceGoalResource;
use App\Http\Resources\ReviewTemplateResource;
use App\Models\AppraisalReview;
use App\Models\CalibrationAdjustment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceGoal;
use App\Support\ActivityLogger;
use App\Support\Performance\AppraisalSharing;
use App\Support\Performance\FeedbackSummary;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\PerformanceCalibration;
use App\Support\Performance\PerformanceInsights;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\ReviewWorkflow;
use App\Support\Performance\TemplateResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance Management — the live view of the appraisal programme. The
 * overview is scoped to **one review cycle** rather than the whole history,
 * because that is the unit HR runs and reports on: how far through the cycle we
 * are, how the results spread across the company's own rating bands, and which
 * departments are rating out of step with the rest.
 *
 * Frameworks, criteria, scales and cycles are configured in Company Setup; this
 * module conducts the appraisals. Evaluations are addressed by hashid.
 *
 * Somebody who only takes part (`performance.participate`) is sent to their own
 * appraisals (ADR 0072). The scorecard carries the reviews asked of the people
 * around the appraisal, the person's goals for the cycle, and any calibration.
 */
class PerformanceController extends Controller
{
    use PerformanceResponses;

    public function __construct(
        private readonly PerformanceCalibration $calibration,
        private readonly TemplateResolver $templates,
    ) {}

    /**
     * The performance overview for one review cycle: coverage, the band
     * distribution, per-department calibration and the appraisal list — plus
     * everything the "open one" and "launch the cycle" actions need.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->cannot('performance.view')) {
            abort_unless($request->user()->can('performance.participate'), 403);

            return redirect()->route('performance.me');
        }

        $periods = EvaluationPeriod::query()->withCount('evaluations')->recentFirst()->get();
        $period = $this->currentPeriod($request, $periods);

        $evaluations = PerformanceEvaluation::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'period:id,name,status,start_date,end_date',
                'evaluator:id,first_name,last_name',
            ])
            ->withCount('scores')
            ->forPeriod($period?->id)
            ->latestFirst()
            ->get();

        $eligible = Employee::query()->where('employment_status', 'active')->count();

        return Inertia::render('performance/index', [
            'evaluations' => PerformanceEvaluationResource::collection($evaluations)->resolve($request),
            'periods' => EvaluationPeriodResource::collection($periods)->resolve($request),
            'templates' => ReviewTemplateResource::collection($this->templates->active())->resolve($request),
            'departments' => $this->departments(),
            'employees' => $this->activeEmployees(),
            'currentPeriodId' => $period?->id,
            'stats' => $this->calibration->summary($evaluations, $eligible),
            'distribution' => $this->calibration->distribution($evaluations),
            'byDepartment' => $this->calibration->byDepartment($evaluations),
            'can' => $this->permissions($request),
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    /**
     * A single appraisal and its scorecard, plus per-employee decision support
     * (rating history, the latest ML forecast and any saved AI read).
     */
    public function show(Request $request, PerformanceEvaluation $evaluation, PerformanceInsights $insights, PerformanceScorer $scorer, FeedbackSummary $feedback, AppraisalSharing $sharing): Response
    {
        $evaluation->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,user_id,manager_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'period:id,name,status,start_date,end_date',
            'evaluator:id,first_name,last_name',
            'acknowledger:id,first_name,last_name',
            'scores' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'scores.criterion:id,name,is_active',
        ]);

        $own = $evaluation->isAbout($request->user());
        $holding = $evaluation->status === 'submitted' && $evaluation->shared_at === null
            ? $sharing->holdingSession($evaluation)
            : null;

        return Inertia::render('performance/show', [
            'evaluation' => (new PerformanceEvaluationResource($evaluation))->resolve($request),
            'result' => $scorer->score($evaluation->scores, $evaluation->bandList())->toArray(),
            'support' => $this->decisionSupport($evaluation, $insights),
            'feedback' => $feedback->for($evaluation),
            'goals' => $this->goals($request, $evaluation),
            'calibration' => [
                'adjustments' => $this->adjustments($evaluation),
                'holding' => $holding ? ['hashid' => $holding->hashid, 'name' => $holding->name] : null,
            ],
            'reviewers' => $request->user()->can('performance.manage') && $evaluation->isEditable() && ! $own
                ? $this->reviewCandidates($evaluation)
                : [],
            'can' => [
                ...$this->permissions($request),
                // Nobody conducts their own appraisal (ADR 0072).
                'own' => $own,
            ],
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    /**
     * Generate (and persist) the LLM performance read for one evaluation. Mirrors
     * the recruitment insights endpoint: on-demand, cached on the model, never a
     * thrown error to the client — failures resolve to an "unavailable" payload.
     */
    public function insights(PerformanceEvaluation $evaluation, PerformanceInsights $insights): JsonResponse
    {
        $evaluation->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'period:id,name',
            'scores' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $result = $insights->generate($evaluation, $this->history($evaluation));

        if ($result['available'] ?? false) {
            $evaluation->ai_insights = $result;
            $evaluation->save();

            ActivityLogger::log(
                event: 'updated',
                description: "Generated AI performance insights for {$evaluation->employee?->full_name}",
                subject: $evaluation,
                logName: 'performance',
                subjectLabel: $evaluation->employee?->full_name,
            );
        }

        return response()->json(['insights' => $result]);
    }

    /**
     * The cycle the overview is showing: the one asked for, else the open cycle
     * (the one work is actually happening in), else the most recent.
     *
     * @param  Collection<int, EvaluationPeriod>  $periods
     */
    private function currentPeriod(Request $request, Collection $periods): ?EvaluationPeriod
    {
        $requested = $request->integer('period');

        return $periods->firstWhere('id', $requested)
            ?? $periods->firstWhere('status', 'open')
            ?? $periods->first();
    }

    /**
     * Per-employee decision support: rating history (trajectory), the latest ML
     * performance forecast for this employee, and whether AI insights are enabled.
     *
     * @return array<string, mixed>
     */
    private function decisionSupport(PerformanceEvaluation $evaluation, PerformanceInsights $insights): array
    {
        $forecast = PerformanceForecast::query()
            ->where('employee_id', $evaluation->employee_id)
            ->with('run:id,created_at')
            ->latest('id')
            ->first();

        return [
            'history' => $this->history($evaluation),
            'forecast' => $forecast ? [
                'predicted_rating' => (float) $forecast->predicted_rating,
                'predicted_low' => $forecast->predicted_low === null ? null : (float) $forecast->predicted_low,
                'predicted_high' => $forecast->predicted_high === null ? null : (float) $forecast->predicted_high,
                'band' => $forecast->band,
                'confidence' => (float) $forecast->confidence,
                'generated_at' => $forecast->run?->created_at?->toIso8601String(),
            ] : null,
            'ai_available' => $insights->enabled(),
        ];
    }

    /**
     * The employee's result history across cycles (oldest first), for the
     * trajectory chart and the LLM digest. Attainment is the comparable figure —
     * frameworks change between cycles, 0–100 does not.
     *
     * @return list<array{period: string|null, percent: float, score: float, label: string|null, status: string, is_current: bool}>
     */
    private function history(PerformanceEvaluation $evaluation): array
    {
        return PerformanceEvaluation::query()
            ->where('employee_id', $evaluation->employee_id)
            ->whereNotNull('overall_score')
            ->with('period:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (PerformanceEvaluation $e): array => [
                'period' => $e->period?->name,
                'percent' => $e->overall_percent === null
                    ? round((((float) $e->overall_score) - 1) / 4 * 100, 2)
                    : (float) $e->overall_percent,
                'score' => (float) $e->overall_score,
                'label' => $e->result_label,
                'status' => $e->status,
                'is_current' => $e->id === $evaluation->id,
            ])
            ->values()
            ->all();
    }

    /**
     * The person's goals for the appraisal's cycle and what they add up to —
     * decision support for the evaluator, never a rating.
     *
     * @return array{items: list<array<string, mixed>>, attainment: float|null}
     */
    private function goals(Request $request, PerformanceEvaluation $evaluation): array
    {
        $goals = PerformanceGoal::query()
            ->forEmployee($evaluation->employee_id)
            ->forPeriod($evaluation->evaluation_period_id)
            ->withCount('checkIns')
            ->orderByRaw("case status when 'active' then 0 when 'achieved' then 1 when 'missed' then 2 else 3 end")
            ->orderBy('id')
            ->get();

        return [
            'items' => PerformanceGoalResource::collection($goals)->resolve($request),
            'attainment' => GoalProgress::attainment($goals),
        ];
    }

    /**
     * Every rating move calibration made on this appraisal, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function adjustments(PerformanceEvaluation $evaluation): array
    {
        return $evaluation->adjustments()
            ->with(['session:id,name', 'adjuster:id,first_name,last_name'])
            ->get()
            ->map(fn (CalibrationAdjustment $a): array => [
                'id' => $a->id,
                'from_label' => $a->from_label,
                'to_label' => $a->to_label,
                'to_band' => $a->to_band,
                'reason' => $a->reason,
                'by' => $a->adjuster?->full_name,
                'session' => $a->session ? ['hashid' => $a->session->hashid, 'name' => $a->session->name] : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The colleagues HR can ask to review this appraisal: each with what they are
     * to the person (derived from the reporting line), whether they have an
     * account to answer with, and whether they have been asked already.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewCandidates(PerformanceEvaluation $evaluation): array
    {
        $subject = $evaluation->employee;

        if ($subject === null) {
            return [];
        }

        $asked = AppraisalReview::query()
            ->where('performance_evaluation_id', $evaluation->id)
            ->pluck('status', 'reviewer_id');

        return Employee::query()
            ->where('employment_status', 'active')
            ->with(['department:id,name', 'user:id,is_active'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'photo', 'department_id', 'manager_id', 'user_id'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials(),
                'department' => $employee->department?->name,
                'relationship' => ReviewWorkflow::relationshipOf($employee, $subject),
                'has_account' => $employee->user !== null && $employee->user->is_active,
                'is_evaluator' => $evaluation->evaluator_id !== null && $employee->user_id === $evaluation->evaluator_id,
                'asked' => in_array($asked->get($employee->id), ['pending', 'submitted'], true) ? $asked->get($employee->id) : null,
            ])
            ->all();
    }

    /**
     * The departments a cycle can be launched for, with their active headcount.
     *
     * @return list<array{id: int, name: string, headcount: int}>
     */
    private function departments(): array
    {
        return Department::query()
            ->withCount(['employees as headcount' => fn ($query) => $query->where('employment_status', 'active')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'headcount' => (int) $department->headcount,
            ])
            ->all();
    }

    /**
     * Active employees for the "open an appraisal" picker.
     *
     * @return list<array{id: int, full_name: string, employee_no: string, department_id: int|null}>
     */
    private function activeEmployees(): array
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'department_id'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'department_id' => $employee->department_id,
            ])
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('performance.manage')];
    }
}
