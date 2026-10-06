<?php

namespace App\Support\Search\Sources;

use App\Models\LeaveRequest;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Leave requests, found by the employee or the leave type and opened in their
 * review drawer on Leave. The inbox shows pending requests by default, so the
 * link asks for every status and the employee's number — the request is then
 * on the list for the drawer to open. The reason given is never searched.
 */
class LeaveRequestSource extends RecordSource
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave requests';
    }

    protected function permission(): string
    {
        return 'leave.view';
    }

    protected function query(): Builder
    {
        return LeaveRequest::query()
            ->select(['id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'status'])
            // An archived employee's requests stay behind (archiving does not
            // cascade), but the employee is not shown anywhere — so neither are they.
            ->whereHas('employee')
            ->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no', 'type:id,name']);
    }

    protected function order(Builder $query): void
    {
        $query->orderByDesc('start_date')->orderByDesc('id');
    }

    /**
     * @param  LeaveRequest  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        $dates = $row->start_date->isSameDay($row->end_date)
            ? $row->start_date->format('M j, Y')
            : $row->start_date->format('M j').' – '.$row->end_date->format('M j, Y');

        return new SearchResult(
            id: 'leave:'.$row->id,
            title: $row->employee->full_name,
            subtitle: trim(($row->type?->name ?? 'Leave').' · '.$dates),
            hint: Str::headline((string) $row->status),
            href: route('leave.index', ['status' => 'all', 'search' => $row->employee->employee_no, 'open' => $row->hashid], absolute: false),
        );
    }
}
