<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationPeriodResource;
use App\Http\Resources\PerformanceEvaluationResource;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Support\ActivityLogger;
use App\Support\Performance\PerformanceInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance Management — the live view of the appraisal program: an overview of
 * every evaluation with its score and status, and the scorecard for one
 * evaluation. KPI criteria and review periods are configured in Company Setup;
 * this module conducts the evaluations. Evaluations are addressed by hashid.
 */
class PerformanceController extends Controller
{
    /**
     * The performance overview: headline metrics + the evaluations list, with the
     * periods and employees needed to open a new one.
     */
    public function index(Request $request): Response
    {
        $evaluations = PerformanceEvaluation::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'period:id,name,status,start_date,end_date',
                'evaluator:id,first_name,last_name',
            ])
            ->withCount('scores')
            ->latestFirst()
            ->get();

        $periods = EvaluationPeriod::query()->recentFirst()->get();

        return Inertia::render('performance/index', [
            'evaluations' => PerformanceEvaluationResource::collection($evaluations)->resolve($request),
            'periods' => EvaluationPeriodResource::collection($periods)->resolve($request),
            'employees' => $this->activeEmployees(),
            'stats' => $this->stats($evaluations),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * A single evaluation and its KPI scorecard, plus per-employee decision
     * support (rating history, the latest ML forecast and any saved AI read).
     */
    public function show(Request $request, PerformanceEvaluation $evaluation, PerformanceInsights $insights): Response
    {
        $evaluation->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'period:id,name,status,start_date,end_date',
            'evaluator:id,first_name,last_name',
            'scores' => fn ($query) => $query->orderBy('id'),
            'scores.criterion:id,name,is_active',
        ]);

        return Inertia::render('performance/show', [
            'evaluation' => (new PerformanceEvaluationResource($evaluation))->resolve($request),
            'support' => $this->decisionSupport($evaluation, $insights),
            'can' => $this->permissions($request),
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
            'scores' => fn ($query) => $query->orderBy('id'),
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
                'band' => $forecast->band,
                'confidence' => (float) $forecast->confidence,
                'generated_at' => $forecast->run?->created_at?->toIso8601String(),
            ] : null,
            'ai_available' => $insights->enabled(),
        ];
    }

    /**
     * The employee's overall-score history across periods (oldest first), for the
     * trajectory chart and the LLM digest.
     *
     * @return list<array{period: string|null, score: float, status: string, is_current: bool}>
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
                'score' => (float) $e->overall_score,
                'status' => $e->status,
                'is_current' => $e->id === $evaluation->id,
            ])
            ->values()
            ->all();
    }

    /**
     * Active employees for the "new evaluation" picker.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function activeEmployees(): array
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
            ])
            ->all();
    }

    /**
     * Headline performance metrics, derived from the evaluation collection.
     *
     * @param  Collection<int, PerformanceEvaluation>  $evaluations
     * @return array<string, int|float|null>
     */
    private function stats($evaluations): array
    {
        $completed = $evaluations->whereIn('status', ['submitted', 'acknowledged'])
            ->whereNotNull('overall_score');

        return [
            'total' => $evaluations->count(),
            'draft' => $evaluations->where('status', 'draft')->count(),
            'submitted' => $evaluations->where('status', 'submitted')->count(),
            'acknowledged' => $evaluations->where('status', 'acknowledged')->count(),
            'average_score' => $completed->isEmpty()
                ? null
                : round((float) $completed->avg(fn (PerformanceEvaluation $e): float => (float) $e->overall_score), 2),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('performance.manage')];
    }
}
