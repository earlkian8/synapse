<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\GoalCheckInRequest;
use App\Http\Requests\Performance\GoalRequest;
use App\Http\Resources\EvaluationPeriodResource;
use App\Http\Resources\GoalTemplateResource;
use App\Http\Resources\PerformanceGoalResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\GoalTemplate;
use App\Models\PerformanceGoal;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\GoalWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goals (ADR 0073): every goal set in a review cycle, how far along each is and
 * how it is going, with setting goals for one person or several (from the
 * library or written out), editing, checking in, closing and deleting. Viewing
 * needs `performance.view`; every change needs `performance.manage`. The work is
 * {@see GoalWorkflow}.
 */
class GoalController extends Controller
{
    use PerformanceResponses;

    public function __construct(private readonly GoalWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $periods = EvaluationPeriod::query()->withCount('evaluations')->recentFirst()->get();
        $period = $periods->firstWhere('id', $request->integer('period'))
            ?? $periods->firstWhere('status', 'open')
            ?? $periods->first();

        $goals = PerformanceGoal::query()
            ->forPeriod($period?->id ?? 0)
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,photo,department_id,user_id',
                'employee.department:id,name',
                'period:id,name,status',
                'checkIns.author:id,first_name,last_name',
            ])
            ->withCount('checkIns')
            ->latest('id')
            ->get();

        return Inertia::render('performance/goals', [
            'goals' => PerformanceGoalResource::collection($goals)->resolve($request),
            'stats' => $this->stats($goals),
            'periods' => EvaluationPeriodResource::collection($periods)->resolve($request),
            'currentPeriodId' => $period?->id,
            'templates' => GoalTemplateResource::collection(GoalTemplate::query()->active()->orderBy('name')->get())->resolve($request),
            'employees' => Employee::query()
                ->where('employment_status', 'active')
                ->with('department:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'department_id'])
                ->map(fn (Employee $e): array => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_no' => $e->employee_no,
                    'department_id' => $e->department_id,
                    'department' => $e->department?->name,
                ])->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d): array => ['id' => $d->id, 'name' => $d->name])->all(),
            'focus' => is_string($request->query('goal')) ? $request->query('goal') : null,
            'can' => ['manage' => $request->user()->can('performance.manage')],
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function store(GoalRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);
        $template = isset($data['goal_template_id']) ? GoalTemplate::findOrFail($data['goal_template_id']) : null;
        $employees = Employee::query()->whereIn('id', $data['employee_ids'])->get();

        return $this->attempt(
            fn () => $this->workflow->set($employees, $period, $data, $template, $request->user()),
            fn (array $goals): string => count($goals) === 1
                ? 'Goal set for '.$goals[0]->employee?->full_name.'.'
                : 'Goal set for '.count($goals).' people.',
        );
    }

    public function update(GoalRequest $request, PerformanceGoal $goal): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->update($goal, $request->validated()), 'Goal updated.');
    }

    public function checkIn(GoalCheckInRequest $request, PerformanceGoal $goal): RedirectResponse
    {
        $data = $request->validated();

        return $this->attempt(
            fn () => $this->workflow->checkIn($goal, (float) $data['value'], $data['health'], $data['note'] ?? null, $request->user()),
            'Checked in.',
        );
    }

    /**
     * Close a goal as achieved, missed or dropped — or reopen it.
     */
    public function status(Request $request, PerformanceGoal $goal): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(PerformanceGoal::STATUSES)]])['status'];

        return $this->attempt(fn () => $this->workflow->close($goal, $status), match ($status) {
            'active' => 'Goal reopened.',
            'achieved' => 'Goal marked achieved.',
            'missed' => 'Goal marked missed.',
            default => 'Goal dropped.',
        });
    }

    public function destroy(Request $request, PerformanceGoal $goal): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->delete($goal, $request->user()), 'Goal deleted.');
    }

    /**
     * The cycle's goals at a glance: how many, how they are going, how many have
     * gone quiet, and their average progress.
     *
     * @param  Collection<int, PerformanceGoal>  $goals
     * @return array<string, int|float|null>
     */
    private function stats(Collection $goals): array
    {
        $active = $goals->where('status', 'active');

        return [
            'total' => $goals->count(),
            'people' => $goals->pluck('employee_id')->unique()->count(),
            'on_track' => $active->where('health', 'on_track')->count(),
            'at_risk' => $active->where('health', 'at_risk')->count(),
            'off_track' => $active->where('health', 'off_track')->count(),
            'stale' => $active->filter(fn (PerformanceGoal $g): bool => $g->isStale())->count(),
            'achieved' => $goals->where('status', 'achieved')->count(),
            'average_progress' => GoalProgress::attainment($goals),
        ];
    }
}
