<?php

namespace App\Support\Leave;

use App\Http\Requests\Leave\StoreLeaveBalanceRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Queries\LeaveBalanceService;
use App\Support\ActivityLogger;

/**
 * Setting how many days of each leave type somebody is entitled to in a year
 * (ADR 0059).
 *
 * The Leave balances screen and the assistant both come through here, so an
 * allocation is stored and recorded the same way whoever set it. Validation is
 * {@see StoreLeaveBalanceRequest}, which runs first. What a person has *left*
 * is never stored: {@see LeaveBalanceService} derives it from the allocation
 * and the requests, so changing one never rewrites history.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class LeaveEntitlements
{
    /**
     * @param  list<array{leave_type_id: int, entitled_days: float|int|string}>  $rows
     */
    public function set(Employee $employee, int $year, array $rows, string $channel = ''): void
    {
        foreach ($rows as $row) {
            LeaveBalance::updateOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $row['leave_type_id'], 'year' => $year],
                ['entitled_days' => $row['entitled_days']],
            );
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Set {$year} leave entitlements for {$employee->full_name}{$channel}",
            subject: $employee,
            properties: ['year' => $year, 'balances' => $rows],
            logName: 'leave',
            subjectLabel: $employee->full_name,
        );
    }
}
