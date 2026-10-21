<?php

namespace App\Queries\Admin;

use App\Models\ProblemReport;
use App\Queries\Admin\Concerns\ReadsListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Problem reports from every organisation (ADR 0096), the open ones first by
 * default: what the admins still owe somebody an answer on.
 */
class ProblemReportsIndexQuery
{
    use ReadsListFilters;

    public const SORTABLE = ['created_at', 'status_changed_at', 'impact'];

    // `open` (new or investigating) is the default, so it comes first.
    public const STATUSES = ['open', 'all', 'new', 'investigating', 'resolved', 'closed'];

    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * @return LengthAwarePaginator<int, ProblemReport>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        [$sort, $direction] = $this->sort($request);
        $category = $this->choice($request, 'category', ProblemReport::CATEGORIES);
        $impact = $this->choice($request, 'impact', ProblemReport::IMPACTS);
        $platform = $this->choice($request, 'platform', ProblemReport::PLATFORMS);

        $query = ProblemReport::query()
            ->with(['user', 'organization'])
            ->withCount('attachments')
            ->search($this->search($request))
            ->tap(fn (Builder $query) => match ($this->status($request)) {
                'all' => $query,
                'open' => $query->open(),
                default => $query->where('status', $this->status($request)),
            })
            ->when($category !== 'all', fn (Builder $query) => $query->where('category', $category))
            ->when($impact !== 'all', fn (Builder $query) => $query->where('impact', $impact))
            ->when($platform !== 'all', fn (Builder $query) => $query->where('platform', $platform));

        if ($sort === 'impact') {
            // Most in the way first when descending: blocked, workaround, minor.
            $query->orderByRaw(
                "case impact when 'blocked' then 3 when 'workaround' then 2 else 1 end ".($direction === 'asc' ? 'asc' : 'desc'),
            )->orderByDesc('created_at');
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    /**
     * One of a fixed set of values, or `all`.
     *
     * @param  array<string, mixed>  $values
     */
    public function choice(Request $request, string $key, array $values): string
    {
        $value = $request->string($key)->toString();

        return array_key_exists($value, $values) ? $value : 'all';
    }

    /**
     * @return array{new: int, investigating: int, blocked: int, resolved_30d: int, total: int}
     */
    public function stats(): array
    {
        return [
            'new' => ProblemReport::query()->where('status', 'new')->count(),
            'investigating' => ProblemReport::query()->where('status', 'investigating')->count(),
            'blocked' => ProblemReport::query()->open()->where('impact', 'blocked')->count(),
            'resolved_30d' => ProblemReport::query()->where('resolved_at', '>=', now()->subDays(30))->count(),
            'total' => ProblemReport::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function allFilters(Request $request): array
    {
        return [
            ...$this->filters($request),
            'category' => $this->choice($request, 'category', ProblemReport::CATEGORIES),
            'impact' => $this->choice($request, 'impact', ProblemReport::IMPACTS),
            'platform' => $this->choice($request, 'platform', ProblemReport::PLATFORMS),
        ];
    }
}
