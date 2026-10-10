<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\GoalCheckInRequest;
use App\Http\Requests\Performance\GoalRequest;
use App\Http\Resources\PerformanceGoalResource;
use App\Models\EvaluationPeriod;
use App\Models\GoalTemplate;
use App\Models\PerformanceGoal;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\GoalWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My goals (ADR 0073): the signed-in employee's goals for a cycle, their
 * check-ins, and adding a goal of their own. Gated by `performance.participate`;
 * someone else's goal is a 404 here.
 */
class MyGoalsController extends Controller
{
    use PerformanceResponses;

    public function __construct(private readonly GoalWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $employee = $this->ownEmployee($request);

        $goals = $employee === null ? collect() : PerformanceGoal::query()
            ->forEmployee($employee)
            ->with(['employee:id,user_id,first_name,last_name', 'period:id,name,status', 'checkIns.author:id,first_name,last_name'])
            ->withCount('checkIns')
            ->latest('id')
            ->get();

        $periods = $this->periods($goals);
        $requested = $request->integer('period');
        $focus = is_string($request->query('goal')) ? $request->query('goal') : null;
        $focused = $focus === null ? null : $goals->firstWhere('hashid', $focus);

        $current = $periods->firstWhere('id', $focused?->evaluation_period_id)
            ?? $periods->firstWhere('id', $requested)
            ?? $periods->firstWhere('status', 'open')
            ?? $periods->first();

        $shown = $goals->where('evaluation_period_id', $current?->id)->values();

        return Inertia::render('performance/my-goals', [
            'goals' => PerformanceGoalResource::collection($shown)->resolve($request),
            'attainment' => GoalProgress::attainment($shown),
            'periods' => $periods->map(fn (EvaluationPeriod $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
            ])->values()->all(),
            'currentPeriodId' => $current?->id,
            'templates' => GoalTemplate::query()->active()->orderBy('name')
                ->get(['id', 'name', 'description', 'measure', 'start_value', 'target_value', 'unit'])
                ->map(fn (GoalTemplate $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'measure' => $t->measure,
                    'start_value' => (float) $t->start_value,
                    'target_value' => (float) $t->target_value,
                    'unit' => $t->unit,
                ])->all(),
            'focus' => $focus,
            'has_employee' => $employee !== null,
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function store(GoalRequest $request): RedirectResponse
    {
        $employee = $this->ownEmployee($request);
        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        $data = $request->validated();
        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);
        $template = isset($data['goal_template_id']) ? GoalTemplate::findOrFail($data['goal_template_id']) : null;

        return $this->attempt(
            fn () => $this->workflow->set([$employee], $period, $data, $template, $request->user(), ownGoal: true),
            'Goal added.',
        );
    }

    public function checkIn(GoalCheckInRequest $request, PerformanceGoal $goal): RedirectResponse
    {
        $this->assertOwn($request, $goal);
        $data = $request->validated();

        return $this->attempt(
            fn () => $this->workflow->checkIn($goal, (float) $data['value'], $data['health'], $data['note'] ?? null, $request->user()),
            'Checked in.',
        );
    }

    public function destroy(Request $request, PerformanceGoal $goal): RedirectResponse
    {
        $this->assertOwn($request, $goal);

        return $this->attempt(
            fn () => $this->workflow->delete($goal, $request->user(), asOwner: true),
            'Goal deleted.',
        );
    }

    /**
     * The cycles to choose between: every one the person has goals in, plus the
     * open ones they could add a goal to — newest first.
     *
     * @param  Collection<int, PerformanceGoal>  $goals
     * @return Collection<int, EvaluationPeriod>
     */
    private function periods(Collection $goals): Collection
    {
        return EvaluationPeriod::query()
            ->where(fn ($q) => $q
                ->whereIn('id', $goals->pluck('evaluation_period_id')->unique())
                ->orWhere('status', 'open'))
            ->recentFirst()
            ->get(['id', 'name', 'status', 'start_date']);
    }

    private function assertOwn(Request $request, PerformanceGoal $goal): void
    {
        $employeeId = $request->user()->employee()->value('id');

        abort_unless($employeeId !== null && (int) $employeeId === $goal->employee_id, 404);
    }
}
