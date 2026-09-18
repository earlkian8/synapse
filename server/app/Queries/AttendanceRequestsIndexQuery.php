<?php

namespace App\Queries;

use App\Models\AttendanceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The attendance request inbox (ADR 0039): requests filtered by status, type,
 * department and a search on the employee — pending first by default, oldest
 * waiting at the top, because that is the order a reviewer should clear them in.
 */
class AttendanceRequestsIndexQuery
{
    /** Statuses the inbox can be filtered by (plus `all`). */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'all'];

    /** The most a list shows before it asks for a narrower filter. */
    private const LIMIT = 200;

    /**
     * @return Collection<int, AttendanceRequest>
     */
    public function get(Request $request): Collection
    {
        $status = $this->status($request);
        $type = $this->type($request);
        $department = $request->integer('department') ?: null;

        return AttendanceRequest::query()
            ->with([
                'employee:id,user_id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
            ])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($department, fn (Builder $query) => $query->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('department_id', $department),
            ))
            ->search($request->string('search')->toString())
            ->when(
                $status === 'pending',
                fn (Builder $query) => $query->orderBy('created_at')->orderBy('id'),
                fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            )
            ->limit(self::LIMIT)
            ->get();
    }

    public function status(Request $request): string
    {
        $status = $request->string('request_status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'pending';
    }

    public function type(Request $request): ?string
    {
        $type = $request->string('request_type')->toString();

        return in_array($type, AttendanceRequest::TYPES, true) ? $type : null;
    }
}
