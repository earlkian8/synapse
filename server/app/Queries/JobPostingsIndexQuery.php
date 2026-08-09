<?php

namespace App\Queries;

use App\Models\JobPosting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class JobPostingsIndexQuery
{
    /**
     * Columns that may be sorted on from the client.
     *
     * @var list<string>
     */
    public const SORTABLE = ['title', 'status', 'openings', 'closing_date', 'created_at'];

    /**
     * Statuses the index may be filtered by.
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'draft', 'open', 'closed', 'filled'];

    /**
     * Allowed page sizes.
     *
     * @var list<int>
     */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    /**
     * Build the filtered, sorted and paginated postings listing.
     *
     * @return LengthAwarePaginator<int, JobPosting>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return $this->build($request)
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    /**
     * @return Builder<JobPosting>
     */
    public function build(Request $request): Builder
    {
        $status = $this->status($request);
        $department = $request->integer('department');
        [$sort, $direction] = $this->sort($request);

        return JobPosting::query()
            ->with([
                'department:id,name,code',
                'position:id,title',
                'postedBy:id,first_name,middle_name,last_name,suffix',
            ])
            ->withCount([
                'applications',
                'applications as open_count' => fn (Builder $query) => $query->open(),
                'applications as hired_count' => fn (Builder $query) => $query->where('stage', 'hired'),
            ])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($department > 0, fn (Builder $query) => $query->where('department_id', $department))
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
