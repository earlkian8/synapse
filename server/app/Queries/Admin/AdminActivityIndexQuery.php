<?php

namespace App\Queries\Admin;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Queries\Admin\Concerns\ReadsListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The admin activity log (ADR 0085): newest first by default, filtered by what
 * happened and who did it.
 */
class AdminActivityIndexQuery
{
    use ReadsListFilters;

    public const SORTABLE = ['created_at', 'event'];

    // The event filter stands in for a status here (see event()).
    public const STATUSES = ['all'];

    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * @return LengthAwarePaginator<int, AdminActivityLog>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        [$sort, $direction] = $this->sort($request);
        $event = $this->event($request);
        $adminId = $this->adminId($request);

        return AdminActivityLog::query()
            ->search($this->search($request))
            ->when($event !== 'all', fn (Builder $query) => $query->where('event', $event))
            ->when($adminId !== null, fn (Builder $query) => $query->where('admin_id', $adminId))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    public function event(Request $request): string
    {
        $event = $request->string('event')->toString();

        return array_key_exists($event, AdminActivityLog::EVENTS) ? $event : 'all';
    }

    public function adminId(Request $request): ?int
    {
        $id = $request->integer('admin_id');

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{total: int, today: int, failed_sign_ins_7d: int, admins_active_7d: int}
     */
    public function stats(): array
    {
        $week = now()->subDays(7);

        return [
            'total' => AdminActivityLog::query()->count(),
            'today' => AdminActivityLog::query()->where('created_at', '>=', now()->startOfDay())->count(),
            'failed_sign_ins_7d' => AdminActivityLog::query()
                ->whereIn('event', ['sign_in_failed', 'two_factor_failed'])
                ->where('created_at', '>=', $week)
                ->count(),
            'admins_active_7d' => Admin::query()->where('last_login_at', '>=', $week)->count(),
        ];
    }
}
