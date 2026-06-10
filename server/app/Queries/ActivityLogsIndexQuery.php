<?php

namespace App\Queries;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ActivityLogsIndexQuery
{
    /**
     * Columns that may be sorted on from the client.
     *
     * @var list<string>
     */
    public const SORTABLE = ['event', 'created_at'];

    /**
     * Events the index may be filtered by.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'all',
        'created',
        'updated',
        'activated',
        'deactivated',
        'password_reset',
        'archived',
        'restored',
        'deleted',
    ];

    /**
     * Allowed page sizes.
     *
     * @var list<int>
     */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    /**
     * Build the filtered, sorted and paginated log listing for the request.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return $this->build($request)
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    /**
     * Compose the base query from the request filters.
     *
     * @return Builder<ActivityLog>
     */
    public function build(Request $request): Builder
    {
        $event = $this->event($request);
        [$sort, $direction] = $this->sort($request);

        return ActivityLog::query()
            ->with('causer')
            ->when($event !== 'all', fn (Builder $query) => $query->where('event', $event))
            ->search($request->string('search')->toString())
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function event(Request $request): string
    {
        $event = $request->string('event')->toString();

        return in_array($event, self::EVENTS, true) ? $event : 'all';
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function sort(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';

        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        return [$sort, $direction];
    }

    public function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', 10);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : 10;
    }
}
