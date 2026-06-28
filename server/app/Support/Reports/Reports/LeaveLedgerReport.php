<?php

namespace App\Support\Reports\Reports;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The leave ledger: every leave request that starts within a period, with its type,
 * duration and approval state. The audit record of who was off, when, and on whose
 * approval.
 */
class LeaveLedgerReport implements Report
{
    use BuildsReport;

    private const STATUS_LABELS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public function key(): string
    {
        return 'leave-ledger';
    }

    public function name(): string
    {
        return 'Leave Ledger';
    }

    public function description(): string
    {
        return 'Leave requests starting within a period, by type and approval status.';
    }

    public function group(): string
    {
        return 'Leave';
    }

    public function permission(): string
    {
        return 'leave.view';
    }

    public function filters(): array
    {
        return [
            $this->dateRangeFilter('Period', now()->startOfMonth(), now()->endOfMonth()),
            $this->selectFilter('status', 'Status', self::STATUS_LABELS, 'All statuses'),
            $this->selectFilter('type', 'Leave type', $this->leaveTypeOptions(), 'All types'),
            $this->searchFilter('Employee name…'),
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee_no', 'label' => 'Employee No', 'align' => 'left', 'type' => 'text'],
            ['key' => 'full_name', 'label' => 'Employee', 'align' => 'left', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'type' => 'text'],
            ['key' => 'type', 'label' => 'Leave type', 'align' => 'left', 'type' => 'text'],
            ['key' => 'start_date', 'label' => 'From', 'align' => 'left', 'type' => 'date'],
            ['key' => 'end_date', 'label' => 'To', 'align' => 'left', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Days', 'align' => 'right', 'type' => 'number'],
            ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'type' => 'badge'],
        ];
    }

    public function rows(array $params): Collection
    {
        $query = LeaveRequest::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id',
                'employee.department:id,name',
                'type:id,name',
            ])
            ->whereDate('start_date', '>=', $params['start'])
            ->whereDate('start_date', '<=', $params['end']);

        if ($params['status'] !== 'all') {
            $query->where('status', $params['status']);
        }

        if ($params['type'] !== 'all') {
            $query->where('leave_type_id', (int) $params['type']);
        }

        if ($params['search'] !== '') {
            $query->whereHas('employee', fn (Builder $employee) => $employee->search($params['search']));
        }

        return $query
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (LeaveRequest $request): array => [
                'employee_no' => $request->employee?->employee_no ?? '—',
                'full_name' => $request->employee?->full_name ?? '—',
                'department' => $request->employee?->department?->name ?? '—',
                'type' => $request->type?->name ?? '—',
                'start_date' => $request->start_date?->toDateString() ?? '',
                'end_date' => $request->end_date?->toDateString() ?? '',
                'days' => (float) $request->days,
                'status' => self::STATUS_LABELS[$request->status] ?? $request->status,
            ])
            ->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        return [
            ['label' => 'Requests', 'value' => number_format($rows->count())],
            ['label' => 'Total days', 'value' => number_format((float) $rows->sum('days'), 1)],
            ['label' => 'Approved', 'value' => number_format($rows->where('status', 'Approved')->count())],
            ['label' => 'Pending', 'value' => number_format($rows->where('status', 'Pending')->count())],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->donut('By status', $rows->groupBy('status')->map->count()->all()),
            $this->barsFromCounts('Days by type', $rows->groupBy('type')->map(fn (Collection $group): int => (int) round($group->sum('days')))->all()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function leaveTypeOptions(): array
    {
        return LeaveType::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();
    }
}
