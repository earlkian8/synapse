<?php

namespace App\Support\Attendance;

use App\Jobs\RecomputeAttendanceRange;
use App\Models\AttendanceRecord;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ShiftRosterEntry;
use App\Support\OrganizationClock;
use App\Support\Setup\HolidayWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Watches what a recorded attendance day depends on but does not own (ADR 0041),
 * and queues {@see RecomputeAttendanceRange} over the dates a change touches:
 *
 *  - **leave** approved, cancelled, deleted, or moved while approved;
 *  - **holidays** created, moved, deleted or restored — every year's occurrence
 *    for a repeating one, back to the first recorded day;
 *  - **schedule assignments** and **roster entries** written or removed — the
 *    dates they covered before and after.
 *
 * Watching the models rather than their controllers catches every path at once:
 * the web, the mobile API, the assistant and whatever comes next. A work
 * location or its fence changing is deliberately not watched: a punch was judged
 * where it was made.
 */
class AttendanceInputs
{
    /**
     * Register the watchers. Called once, from the service provider.
     */
    public static function watch(): void
    {
        LeaveRequest::saved(function (LeaveRequest $leave): void {
            $approved = $leave->status === 'approved';
            $wasApproved = $leave->getOriginal('status') === 'approved' && ! $leave->wasRecentlyCreated;

            if (! $approved && ! $wasApproved) {
                return;
            }

            if (! $leave->wasRecentlyCreated && ! $leave->wasChanged(['status', 'start_date', 'end_date', 'is_half_day'])) {
                return;
            }

            self::queue($leave, [$leave->employee_id], [
                self::date($leave->getOriginal('start_date')), self::date($leave->getOriginal('end_date')),
                self::date($leave->start_date), self::date($leave->end_date),
            ], 'a leave change');
        });

        LeaveRequest::deleted(function (LeaveRequest $leave): void {
            if ($leave->status === 'approved') {
                self::queue($leave, [$leave->employee_id], [self::date($leave->start_date), self::date($leave->end_date)], 'a leave change');
            }
        });

        $holiday = fn (Holiday $holiday) => self::holidayChanged($holiday);

        Holiday::saved($holiday);
        Holiday::deleted($holiday);
        Holiday::restored($holiday);

        $assignment = function (EmployeeScheduleAssignment $assignment): void {
            $today = OrganizationClock::today();

            self::queue($assignment, [$assignment->employee_id], [
                self::date($assignment->getOriginal('effective_from')),
                self::date($assignment->getOriginal('effective_to')) ?? (self::date($assignment->getOriginal('effective_from')) !== null ? $today : null),
                self::date($assignment->effective_from),
                self::date($assignment->effective_to) ?? $today,
            ], 'a schedule assignment changed');
        };

        EmployeeScheduleAssignment::saved($assignment);
        EmployeeScheduleAssignment::deleted($assignment);

        $roster = function (ShiftRosterEntry $entry): void {
            self::queue($entry, [$entry->employee_id], [self::date($entry->getOriginal('date')), self::date($entry->date)], 'a roster change');
        };

        ShiftRosterEntry::saved($roster);
        ShiftRosterEntry::deleted($roster);
    }

    /**
     * A holiday was added, moved, re-ruled, re-sited, archived or restored:
     * queue every date it fell on before or falls on now, in every year
     * attendance has been recorded through next year — Holy Week lands on a
     * different date each year (ADR 0078). Saving fires this even when only its
     * sites changed ({@see HolidayWorkflow} saves before it syncs them), and the
     * recompute runs after the response, by when the sites are written.
     */
    public static function holidayChanged(Holiday $holiday): void
    {
        $before = (new Holiday)->forceFill(array_intersect_key(
            $holiday->getOriginal(),
            array_flip(['date', 'is_recurring', 'recurrence', 'rule']),
        ));

        foreach (self::years() as $year) {
            $dates = array_filter([
                $holiday->occurrenceIn($year)?->toDateString(),
                $holiday->wasRecentlyCreated ? null : $before->occurrenceIn($year)?->toDateString(),
            ]);

            foreach (array_unique($dates) as $date) {
                self::queue($holiday, null, [$date], "the holiday \"{$holiday->name}\" changed");
            }
        }
    }

    /**
     * Queue the recompute over the span of the dates given.
     *
     * @param  list<int>|null  $employeeIds
     * @param  list<?string>  $dates
     */
    private static function queue(Model $model, ?array $employeeIds, array $dates, string $reason): void
    {
        $dates = array_values(array_filter($dates));
        $organizationId = $model->getAttribute('organization_id');

        if ($dates === [] || $organizationId === null) {
            return;
        }

        RecomputeAttendanceRange::dispatchAfterResponse(
            (int) $organizationId,
            $employeeIds,
            min($dates),
            max(min($dates), max($dates)),
            $reason,
        );
    }

    /**
     * Every year attendance has been recorded in, through next year.
     *
     * @return list<int>
     */
    private static function years(): array
    {
        $first = AttendanceRecord::query()->min('work_date');
        $to = (int) substr(OrganizationClock::today(), 0, 4) + 1;
        $from = $first !== null ? (int) substr((string) $first, 0, 4) : $to - 1;

        return range(min($from, $to), $to);
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10))->toDateString();
    }
}
