<?php

namespace App\Queries;

use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class LeaveRequestsIndexQuery
{
    /**
     * Statuses the inbox may be filtered by. `upcoming` is approved leave that
     * has not started yet.
     *
     * @var list<string>
     */
    public const STATUSES = ['all', 'pending', 'approved', 'upcoming', 'rejected', 'cancelled'];

    /**
     * Build the filtered request list. No pagination — leave volume is modest and
     * the default view (pending) is naturally small; the list is action-oriented.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function get(Request $request): Collection
    {
        $status = $this->status($request);
        $type = $request->integer('type');
        $department = $request->integer('department');
        $search = $request->string('search')->toString();
        $today = now()->toDateString();

        return LeaveRequest::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'type:id,name,code,color,is_paid',
            ])
            ->when($status === 'upcoming', fn (Builder $query) => $query
                ->where('status', 'approved')
                ->whereDate('start_date', '>=', $today))
            ->when(in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($type > 0, fn (Builder $query) => $query->where('leave_type_id', $type))
            ->when($department > 0, fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $department)))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->search($search)))
            // Pending first (the queue that needs action), then most recent leave.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::STATUSES, true) ? $status : 'pending';
    }
}
