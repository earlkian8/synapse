<?php

namespace App\Queries;

use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RolesIndexQuery
{
    /**
     * Columns that may be sorted on from the client.
     *
     * @var list<string>
     */
    public const SORTABLE = ['label', 'name', 'created_at'];

    /**
     * Types the index may be filtered by.
     *
     * @var list<string>
     */
    public const TYPES = ['all', 'system', 'custom'];

    /**
     * Allowed page sizes.
     *
     * @var list<int>
     */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    /**
     * Build the filtered, sorted and paginated role listing.
     *
     * @return LengthAwarePaginator<int, Role>
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
     * @return Builder<Role>
     */
    public function build(Request $request): Builder
    {
        $type = $this->type($request);
        [$sort, $direction] = $this->sort($request);

        return Role::query()
            ->withCount(['permissions', 'users'])
            ->with('permissions:id,name')
            ->when($type === 'system', fn (Builder $query) => $query->where('is_system', true))
            ->when($type === 'custom', fn (Builder $query) => $query->where('is_system', false))
            ->search($request->string('search')->toString())
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function type(Request $request): string
    {
        $type = $request->string('type')->toString();

        return in_array($type, self::TYPES, true) ? $type : 'all';
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
