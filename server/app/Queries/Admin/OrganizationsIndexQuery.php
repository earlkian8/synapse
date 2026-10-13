<?php

namespace App\Queries\Admin;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Queries\Admin\Concerns\ReadsListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Every organisation on the platform, for the admin portal (ADR 0085).
 *
 * The portal binds no tenant, so the organisation scope is already a no-op here;
 * it is lifted explicitly anyway, so a count can never quietly shrink to one
 * company if a tenant were ever bound on the way in.
 */
class OrganizationsIndexQuery
{
    use ReadsListFilters;

    public const SORTABLE = ['name', 'created_at', 'members_count', 'employees_count', 'last_activity_at'];

    public const STATUSES = ['all', 'ready', 'setup', 'archived'];

    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * @return LengthAwarePaginator<int, Organization>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        [$sort, $direction] = $this->sort($request);

        return self::withMeasures(Organization::query())
            ->search($this->search($request))
            ->tap(fn (Builder $query) => match ($this->status($request)) {
                'ready' => $query->whereNotNull('setup_completed_at'),
                'setup' => $query->whereNull('setup_completed_at'),
                'archived' => $query->onlyTrashed(),
                default => $query,
            })
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    /**
     * Members, employees and the last thing anybody did inside, on each row.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public static function withMeasures(Builder $query): Builder
    {
        return $query
            ->select('organizations.*')
            ->withCount([
                'members',
                'employees' => fn (Builder $employees) => $employees->withoutGlobalScope(OrganizationScope::class),
            ])
            ->addSelect(['last_activity_at' => ActivityLog::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->selectRaw('max(created_at)')
                ->whereColumn('activity_logs.organization_id', 'organizations.id'),
            ])
            ->withCasts(['last_activity_at' => 'datetime']);
    }

    /**
     * @return array{total: int, ready: int, in_setup: int, archived: int, this_month: int}
     */
    public function stats(): array
    {
        return [
            'total' => Organization::query()->count(),
            'ready' => Organization::query()->whereNotNull('setup_completed_at')->count(),
            'in_setup' => Organization::query()->whereNull('setup_completed_at')->count(),
            'archived' => Organization::onlyTrashed()->count(),
            'this_month' => Organization::query()->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }
}
