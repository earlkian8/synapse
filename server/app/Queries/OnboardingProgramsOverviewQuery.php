<?php

namespace App\Queries;

use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use App\Models\OnboardingTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The onboarding overview: every program, with how many people it is onboarding
 * and how that onboarding is going — plus an "Unassigned" row for cases that run
 * on no program (started without one, or whose program was since deleted).
 *
 * The figures come from two grouped queries (cases per program; tasks of active
 * cases per program), not one per row, and are tenant-scoped by the models'
 * global scope.
 */
class OnboardingProgramsOverviewQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Request $request): array
    {
        $search = trim($request->string('search')->toString());
        $cases = $this->caseCounts();
        $tasks = $this->activeTaskCounts();

        $rows = OnboardingProgram::query()
            ->with('department:id,name')
            ->withCount('tasks')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->where('name', $like, '%'.$search.'%');
            })
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (OnboardingProgram $program): array => [
                'id' => $program->id,
                'hashid' => $program->hashid,
                'name' => $program->name,
                'description' => $program->description,
                'department' => $program->department ? ['id' => $program->department->id, 'name' => $program->department->name] : null,
                'employment_type' => $program->employment_type,
                'is_default' => $program->is_default,
                'is_active' => $program->is_active,
                'tasks_count' => (int) $program->tasks_count,
                ...$this->figures($cases->get($program->id), $tasks->get($program->id)),
            ])
            ->all();

        // Cases on no program only have a row when there are any, and the search
        // is not for something else.
        $unassigned = $cases->get('');

        if ($unassigned !== null && ($search === '' || str_contains('unassigned', mb_strtolower($search)))) {
            $rows[] = [
                'id' => null,
                'hashid' => null,
                'name' => 'Unassigned',
                'description' => 'Onboarding started without a program, or whose program was deleted.',
                'department' => null,
                'employment_type' => null,
                'is_default' => false,
                'is_active' => true,
                'tasks_count' => null,
                ...$this->figures($unassigned, $tasks->get('')),
            ];
        }

        return $rows;
    }

    /**
     * @param  object{total: int, active: int, completed: int}|null  $cases
     * @param  object{total: int, resolved: int, overdue: int}|null  $tasks
     * @return array<string, mixed>
     */
    private function figures(?object $cases, ?object $tasks): array
    {
        $total = (int) ($tasks->total ?? 0);

        return [
            'cases' => [
                'total' => (int) ($cases->total ?? 0),
                'active' => (int) ($cases->active ?? 0),
                'completed' => (int) ($cases->completed ?? 0),
            ],
            // Across the program's in-flight cases only: a finished checklist is
            // not progress anyone is waiting on.
            'progress' => $total > 0 ? (int) round(100 * (int) $tasks->resolved / $total) : null,
            'overdue' => (int) ($tasks->overdue ?? 0),
        ];
    }

    /**
     * Cases per program: all of them, the in-flight ones, the completed ones.
     * Keyed by program id ('' for no program).
     *
     * @return Collection<int|string, object>
     */
    private function caseCounts(): Collection
    {
        $active = "'".implode("','", OnboardingCase::ACTIVE_STATUSES)."'";

        return OnboardingCase::query()
            ->toBase()
            ->selectRaw('onboarding_program_id as program_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status in ({$active}) then 1 else 0 end) as active")
            ->selectRaw("sum(case when status = 'completed' then 1 else 0 end) as completed")
            ->groupBy('onboarding_program_id')
            ->get()
            ->keyBy(fn (object $row): int|string => $row->program_id ?? '');
    }

    /**
     * The checklist tasks of in-flight cases, per program: how many, how many
     * resolved, how many overdue. Keyed by program id ('' for no program).
     *
     * @return Collection<int|string, object>
     */
    private function activeTaskCounts(): Collection
    {
        $resolved = "'".implode("','", OnboardingTask::RESOLVED_STATUSES)."'";

        return OnboardingTask::query()
            ->toBase()
            ->join('onboarding_cases', 'onboarding_cases.id', '=', 'onboarding_tasks.onboarding_case_id')
            ->whereIn('onboarding_cases.status', OnboardingCase::ACTIVE_STATUSES)
            ->selectRaw('onboarding_cases.onboarding_program_id as program_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when onboarding_tasks.status in ({$resolved}) then 1 else 0 end) as resolved")
            ->selectRaw("sum(case when onboarding_tasks.status not in ({$resolved}) and onboarding_tasks.due_date is not null and onboarding_tasks.due_date < ? then 1 else 0 end) as overdue", [now()->toDateString()])
            ->groupBy('onboarding_cases.onboarding_program_id')
            ->get()
            ->keyBy(fn (object $row): int|string => $row->program_id ?? '');
    }
}
