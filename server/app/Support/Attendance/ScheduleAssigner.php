<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts an employee on a schedule from a date (ADR 0037) — the one writer of
 * {@see EmployeeScheduleAssignment}, so the employee screens, the roster's bulk
 * action and the assistant all leave the same history behind.
 *
 * Two invariants it keeps, which is why nothing else may write the table:
 *
 *  - **Ranges never overlap.** An assignment starting today closes whatever was
 *    open the day before, rather than sitting alongside it. A later assignment
 *    that already covers the new date is trimmed or, if it is wholly inside the
 *    new one, removed.
 *  - **`employees.work_schedule_id` stays true.** The denormalised pointer is
 *    what the employee screens still read, so it is set to whichever assignment
 *    covers the organisation's today — not simply to the one just made, which
 *    may start next month.
 */
class ScheduleAssigner
{
    /**
     * Assign a schedule to an employee from `$effectiveFrom` onwards, returning
     * the assignment written.
     */
    public function assign(
        Employee $employee,
        WorkSchedule $schedule,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        int $cycleOffset = 0,
        ?int $assignedBy = null,
    ): EmployeeScheduleAssignment {
        return DB::transaction(function () use ($employee, $schedule, $effectiveFrom, $effectiveTo, $cycleOffset, $assignedBy): EmployeeScheduleAssignment {
            $this->clearRange($employee, $effectiveFrom, $effectiveTo);

            $assignment = EmployeeScheduleAssignment::create([
                'employee_id' => $employee->id,
                'work_schedule_id' => $schedule->id,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'cycle_offset' => max(0, $cycleOffset),
                'assigned_by' => $assignedBy,
            ]);

            $this->syncCurrentPointer($employee);

            return $assignment;
        });
    }

    /**
     * Withdraw an assignment. The pointer is refreshed, so removing the one in
     * force today drops the employee back to their department's default.
     */
    public function withdraw(EmployeeScheduleAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment): void {
            $employee = $assignment->employee;
            $assignment->delete();

            if ($employee !== null) {
                $this->syncCurrentPointer($employee);
            }
        });
    }

    /**
     * Point `employees.work_schedule_id` at whichever assignment covers the
     * organisation's today, or null it when none does.
     */
    public function syncCurrentPointer(Employee $employee): void
    {
        $current = EmployeeScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->covering(OrganizationClock::today())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        $employee->forceFill(['work_schedule_id' => $current?->work_schedule_id])->save();
    }

    /**
     * Make room for a new assignment over [$from, $to]: close anything open that
     * starts before it, trim anything that starts inside it, and drop anything it
     * swallows whole. A bounded new range dropped into the middle of a longer one
     * splits it, so the old schedule resumes when the new one ends — which is what
     * a fortnight on another shift means.
     */
    private function clearRange(Employee $employee, string $from, ?string $to): void
    {
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();

        $existing = EmployeeScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->overlapping($from, $to ?? '9999-12-31')
            ->get();

        foreach ($existing as $assignment) {
            $startsBefore = $assignment->effective_from->toDateString() < $from;

            if (! $startsBefore) {
                // Starts on or after the new range. Keep only the part that
                // survives beyond it; an open-ended new range keeps nothing.
                if ($to === null || ($assignment->effective_to !== null && $assignment->effective_to->toDateString() <= $to)) {
                    $assignment->delete();

                    continue;
                }

                $assignment->forceFill([
                    'effective_from' => CarbonImmutable::parse($to)->addDay()->toDateString(),
                ])->save();

                continue;
            }

            $resumesOn = $to !== null
                && ($assignment->effective_to === null || $assignment->effective_to->toDateString() > $to)
                    ? CarbonImmutable::parse($to)->addDay()->toDateString()
                    : null;
            $resumesUntil = $assignment->effective_to?->toDateString();

            $assignment->forceFill(['effective_to' => $dayBefore])->save();

            if ($resumesOn !== null) {
                EmployeeScheduleAssignment::create([
                    'employee_id' => $assignment->employee_id,
                    'work_schedule_id' => $assignment->work_schedule_id,
                    'effective_from' => $resumesOn,
                    'effective_to' => $resumesUntil,
                    'cycle_offset' => $assignment->cycle_offset,
                    'assigned_by' => $assignment->assigned_by,
                ]);
            }
        }
    }
}
