<?php

namespace App\Queries\Admin;

use App\Models\Admin;
use App\Queries\Admin\Concerns\ReadsListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Admin accounts, for super admins (ADR 0085).
 */
class AdminsIndexQuery
{
    use ReadsListFilters;

    public const SORTABLE = ['name', 'email', 'role', 'last_login_at', 'created_at'];

    public const STATUSES = ['all', 'active', 'inactive', 'no_two_factor'];

    public const DEFAULT_SORT = 'name';

    public const DEFAULT_DIRECTION = 'asc';

    /**
     * @return LengthAwarePaginator<int, Admin>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        [$sort, $direction] = $this->sort($request);
        $role = $this->role($request);

        return Admin::query()
            ->with('creator')
            ->search($this->search($request))
            ->when($role !== 'all', fn (Builder $query) => $query->where('role', $role))
            ->tap(fn (Builder $query) => match ($this->status($request)) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                'no_two_factor' => $query->whereNull('two_factor_confirmed_at'),
                default => $query,
            })
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    public function role(Request $request): string
    {
        $role = $request->string('role')->toString();

        return array_key_exists($role, Admin::ROLES) ? $role : 'all';
    }

    /**
     * @return array{total: int, active: int, super_admins: int, without_two_factor: int}
     */
    public function stats(): array
    {
        return [
            'total' => Admin::query()->count(),
            'active' => Admin::query()->where('is_active', true)->count(),
            'super_admins' => Admin::query()->where('role', Admin::ROLE_SUPER_ADMIN)->count(),
            'without_two_factor' => Admin::query()->whereNull('two_factor_confirmed_at')->count(),
        ];
    }
}
