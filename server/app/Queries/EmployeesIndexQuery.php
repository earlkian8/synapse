<?php

namespace App\Queries;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class EmployeesIndexQuery
{
    /**
     * Columns that may be sorted on from the client.
     *
     * @var list<string>
     */
    public const SORTABLE = ['employee_no', 'first_name', 'last_name', 'date_hired', 'created_at'];

    /**
     * Statuses the index may be filtered by (employment_status + archived).
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'active', 'on_leave', 'suspended', 'resigned', 'terminated', 'archived'];

    /**
     * Employment types the index may be filtered by.
     *
     * @var list<string>
     */
    public const TYPES = ['all', 'regular', 'probationary', 'contractual', 'part_time'];

    /**
     * Allowed page sizes.
     *
     * @var list<int>
     */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    /**
     * Build the filtered, sorted and paginated employee listing.
     *
     * @return LengthAwarePaginator<int, Employee>
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
     * @return Builder<Employee>
     */
    public function build(Request $request): Builder
    {
        $status = $this->status($request);
        $type = $this->type($request);
        $department = $request->integer('department');
        [$sort, $direction] = $this->sort($request);

        return Employee::query()
            ->with([
                'department:id,name,code',
                'position:id,title',
                'manager:id,first_name,middle_name,last_name,suffix,employee_no',
                'workSchedule:id,name',
                'user:id,email',
                // Feeds Employee::appAccess() for the directory's Access column —
                // eager-loaded so deriving it per row costs no extra query.
                'invitations',
            ])
            ->withCount(['documents', 'certifications'])
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when(
                $status !== 'all' && $status !== 'archived',
                fn (Builder $query) => $query->where('employment_status', $status),
            )
            ->when($type !== 'all', fn (Builder $query) => $query->where('employment_type', $type))
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
