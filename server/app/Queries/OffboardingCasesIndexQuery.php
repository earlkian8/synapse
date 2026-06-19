<?php

namespace App\Queries;

use App\Models\OffboardingCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class OffboardingCasesIndexQuery
{
    /**
     * Statuses the board may be filtered by (`active` covers initiated + clearance).
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'active', 'initiated', 'clearance', 'completed', 'cancelled'];

    /**
     * Build the filtered offboarding-case list (no pagination — the board shows
     * cards, and exit volume is small relative to, say, applications).
     *
     * @return Collection<int, OffboardingCase>
     */
    public function get(Request $request): Collection
    {
        return $this->build($request)->get();
    }

    /**
     * @return Builder<OffboardingCase>
     */
    public function build(Request $request): Builder
    {
        $status = $this->status($request);
        $department = $request->integer('department');
        $type = $request->string('type')->toString();
        $search = $request->string('search')->toString();

        return OffboardingCase::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,employment_type,employment_status,date_hired',
                'employee.department:id,name',
                'employee.position:id,title',
            ])
            ->withCount([
                'clearanceItems as items_count',
                'clearanceItems as cleared_items_count' => fn (Builder $query) => $query->where('status', 'cleared'),
                'clearanceItems as flagged_items_count' => fn (Builder $query) => $query->where('status', 'flagged'),
            ])
            ->when($status === 'active', fn (Builder $query) => $query->whereIn('status', ['initiated', 'clearance']))
            ->when(in_array($status, ['initiated', 'clearance', 'completed', 'cancelled'], true), fn (Builder $query) => $query->where('status', $status))
            ->when(in_array($type, OffboardingCase::TYPES, true), fn (Builder $query) => $query->where('type', $type))
            ->when($department > 0, fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $department)))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->search($search)))
            ->orderByRaw("case when status in ('initiated', 'clearance') then 0 else 1 end")
            ->orderByRaw('last_working_day is null')
            ->orderBy('last_working_day')
            ->orderByDesc('id');
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'active';
    }
}
