<?php

namespace App\Queries;

use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class OnboardingCasesIndexQuery
{
    /**
     * Statuses the board may be filtered by (`active` covers pending + in_progress).
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'active', 'pending', 'in_progress', 'completed', 'cancelled'];

    /**
     * Build the filtered onboarding-case list (no pagination — the board shows
     * cards, and onboarding volume is small relative to, say, applications).
     *
     * @return Collection<int, OnboardingCase>
     */
    public function get(Request $request): Collection
    {
        return $this->build($request)->get();
    }

    /**
     * @return Builder<OnboardingCase>
     */
    public function build(Request $request): Builder
    {
        $status = $this->status($request);
        $department = $request->integer('department');
        $search = $request->string('search')->toString();

        return OnboardingCase::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,employment_type,date_hired',
                'employee.department:id,name',
                'employee.position:id,title',
                'program:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn (Builder $query) => $query->where('status', 'done'),
                'tasks as resolved_tasks_count' => fn (Builder $query) => $query->whereIn('status', OnboardingTask::RESOLVED_STATUSES),
                'tasks as overdue_tasks_count' => fn (Builder $query) => $query->overdue(),
            ])
            ->when($status === 'active', fn (Builder $query) => $query->active())
            ->when(in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($department > 0, fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $department)))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->search($search)))
            ->orderByRaw("case when status in ('pending', 'in_progress') then 0 else 1 end")
            ->orderByRaw('target_end_date is null')
            ->orderBy('target_end_date')
            ->orderByDesc('id');
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'active';
    }
}
