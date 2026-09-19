<?php

namespace App\Support\Attendance;

use App\Jobs\RecomputeAttendanceRange;
use App\Models\AttendanceRecord;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ShiftRosterEntry;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Watches what a recorded attendance day depends on but does not own (ADR 0041),
 * and queues {@see RecomputeAttendanceRange} over the dates a change touches:
 *
 *  - **leave** approved, cancelled, deleted, or moved while approved;
 *  - **holidays** created, moved, deleted or restored — every year's occurrence
 *    for a recurring one, back to the first recorded day;
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

        $holiday = function (Holiday $holiday): void {
            $dates = [self::date($holiday->date), self::date($holiday->getOriginal('date'))];

            foreach (array_unique(array_filter($dates)) as $date) {
                foreach ($holiday->is_recurring || $holiday->getOriginal('is_recurring') ? self::everyYear($date) : [$date] as $occurrence) {
                    self::queue($holiday, null, [$occurrence], "the holiday \"{$holiday->name}\" changed");
                }
            }
        };

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
     * A recurring holiday's date in every year attendance has been recorded,
     * through next year.
     *
     * @return list<string>
     */
    private static function everyYear(string $date): array
    {
        $first = AttendanceRecord::query()->min('work_date');
        $from = $first !== null ? (int) substr((string) $first, 0, 4) : (int) substr(OrganizationClock::today(), 0, 4);
        $to = (int) substr(OrganizationClock::today(), 0, 4) + 1;
        [, $month, $day] = explode('-', $date);

        $out = [];

        for ($year = $from; $year <= $to; $year++) {
            // 29 February only lands on leap years.
            if (checkdate((int) $month, (int) $day, $year)) {
                $out[] = sprintf('%04d-%s-%s', $year, $month, $day);
            }
        }

        return $out;
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10))->toDateString();
    }
}
