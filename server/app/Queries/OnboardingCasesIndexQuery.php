<?php

namespace App\Queries;

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /** Columns the paginated list may be sorted by. */
    public const SORTS = ['employee', 'start_date', 'target_end_date'];

    /** @var list<int> */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    /**
     * Build the filtered onboarding-case list, unpaginated (the assistant and
     * anything else wanting every match).
     *
     * @return Collection<int, OnboardingCase>
     */
    public function get(Request $request): Collection
    {
        return $this->build($request)->get();
    }

    /**
     * One page of the cases `$scope` narrows to (one program's), filtered, and
     * sorted by the requested column — else in-flight first, soonest target first.
     *
     * @param  Closure(Builder<OnboardingCase>): void  $scope
     * @return LengthAwarePaginator<int, OnboardingCase>
     */
    public function paginate(Request $request, Closure $scope, string $defaultStatus = 'all'): LengthAwarePaginator
    {
        $query = $this->build($request, $scope, $defaultStatus);
        $sort = $this->sort($request);

        if ($sort !== null) {
            $direction = $this->direction($request);
            $query->reorder();

            $sort === 'employee'
                ? $query->orderBy(
                    Employee::query()->select('first_name')->whereColumn('employees.id', 'onboarding_cases.employee_id'),
                    $direction,
                )
                : $query->orderBy($sort, $direction);

            $query->orderByDesc('id');
        }

        return $query->paginate($this->perPage($request))->withQueryString();
    }

    /**
     * @param  (Closure(Builder<OnboardingCase>): void)|null  $scope
     * @return Builder<OnboardingCase>
     */
    public function build(Request $request, ?Closure $scope = null, string $defaultStatus = 'active'): Builder
    {
        $status = $this->status($request, $defaultStatus);
        $department = $request->integer('department');
        $search = $request->string('search')->toString();

        return OnboardingCase::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,employment_type,date_hired',
                'employee.department:id,name',
                'employee.position:id,title',
                'program:id,name',
            ])
            ->when($scope !== null, $scope)
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

    public function status(Request $request, string $default = 'active'): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : $default;
    }

    public function sort(Request $request): ?string
    {
        $sort = $request->string('sort')->toString();

        return in_array($sort, self::SORTS, true) ? $sort : null;
    }

    public function direction(Request $request): string
    {
        return $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
    }

    public function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', 15);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : 15;
    }
}
