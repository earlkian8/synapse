<?php

namespace App\Queries;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Collection;

/**
 * Computes leave balances. Only the **entitlement** is stored (a
 * {@see LeaveBalance} row, or the type's default when none exists); **used** and
 * **pending** days are always derived from approved / pending
 * {@see LeaveRequest}s, so a balance can never drift. A request is counted in the
 * year of its start date.
 */
class LeaveBalanceService
{
    /**
     * Per-type balance rows for a set of employees in one year, keyed by employee id.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, LeaveType>  $types
     * @return array<int, list<array<string, mixed>>>
     */
    public function forEmployees(Collection $employees, Collection $types, int $year): array
    {
        $employeeIds = $employees->pluck('id')->all();

        if ($employeeIds === [] || $types->isEmpty()) {
            return [];
        }

        $entitlements = $this->entitlements($employeeIds, $year);
        $usage = $this->usage($employeeIds, $year);

        return $employees->mapWithKeys(fn ($employee) => [
            $employee->id => $types->map(fn (LeaveType $type) => $this->compose(
                $type,
                $entitlements[$employee->id][$type->id] ?? null,
                $usage[$employee->id][$type->id] ?? [],
            ))->values()->all(),
        ])->all();
    }

    /**
     * A single balance snapshot for one employee + type + year (used by the
     * review drawer to show the impact of approving a request).
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $employeeId, LeaveType $type, int $year): array
    {
        $entitled = LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->value('entitled_days');

        $usage = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $type->id)
            ->whereYear('start_date', $year)
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('status, sum(days) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return $this->compose($type, $entitled !== null ? (float) $entitled : null, $usage);
    }

    /**
     * Stored entitlements as [employee_id][leave_type_id] => entitled_days.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<int, float>>
     */
    private function entitlements(array $employeeIds, int $year): array
    {
        $map = [];

        LeaveBalance::whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->get(['employee_id', 'leave_type_id', 'entitled_days'])
            ->each(function (LeaveBalance $balance) use (&$map) {
                $map[$balance->employee_id][$balance->leave_type_id] = (float) $balance->entitled_days;
            });

        return $map;
    }

    /**
     * Approved/pending day sums as [employee_id][leave_type_id][status] => days.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<int, array<string, float>>>
     */
    private function usage(array $employeeIds, int $year): array
    {
        $map = [];

        LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereYear('start_date', $year)
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('employee_id, leave_type_id, status, sum(days) as total')
            ->groupBy('employee_id', 'leave_type_id', 'status')
            ->get()
            ->each(function ($row) use (&$map) {
                $map[$row->employee_id][$row->leave_type_id][$row->status] = (float) $row->total;
            });

        return $map;
    }

    /**
     * Build a balance row from an entitlement and a {status => days} usage map.
     *
     * @param  array<string, float>  $usage
     * @return array<string, mixed>
     */
    private function compose(LeaveType $type, ?float $entitled, array $usage): array
    {
        $hasOverride = $entitled !== null;
        $entitled ??= (float) $type->default_days;
        $used = $usage['approved'] ?? 0.0;
        $pending = $usage['pending'] ?? 0.0;

        return [
            'leave_type_id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'color' => $type->color,
            'entitled' => round($entitled, 1),
            'used' => round($used, 1),
            'pending' => round($pending, 1),
            'remaining' => round($entitled - $used, 1),
            'is_custom' => $hasOverride, // an explicit allocation, distinct from the type default
        ];
    }
}
