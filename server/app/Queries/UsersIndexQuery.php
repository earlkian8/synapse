<?php

namespace App\Queries;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class UsersIndexQuery
{
    /**
     * Columns that may be sorted on from the client.
     *
     * @var list<string>
     */
    public const SORTABLE = ['first_name', 'last_name', 'email', 'employee_id', 'last_login_at', 'created_at'];

    /**
     * Statuses the index may be filtered by.
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'active', 'inactive', 'archived', 'unverified'];

    /**
     * Allowed page sizes.
     *
     * @var list<int>
     */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    /**
     * Build the filtered, sorted and paginated user listing for the request.
     *
     * @return LengthAwarePaginator<int, User>
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
     * @return Builder<User>
     */
    public function build(Request $request): Builder
    {
        $status = $this->status($request);
        [$sort, $direction] = $this->sort($request);

        return User::query()
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($status === 'unverified', fn (Builder $query) => $query->whereNull('email_verified_at'))
            ->search($request->string('search')->toString())
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'all';
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
