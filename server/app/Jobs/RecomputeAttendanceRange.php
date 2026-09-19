<?php

namespace App\Jobs;

use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceInputs;
use App\Support\Attendance\DayCloser;
use App\Support\HolidayCalendar;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bring recorded days in line after something they depend on changed (ADR 0041)
 * — leave approved, cancelled or moved; a holiday added, moved or removed; an
 * assignment or a roster entry written. Dispatched by {@see AttendanceInputs}.
 *
 * What it may change is narrow on purpose, because a day keeps the rules it was
 * judged by (ADR 0036, 0038):
 *
 *  - **Every day in range is re-evaluated**, so what is read live — approved
 *    leave, approved requests — reaches it: a leave approval turns `absent` into
 *    `on_leave`.
 *  - **The holiday is re-read from the calendar**, for every day: a holiday is a
 *    fact about the date, not a rule the day was judged by.
 *  - **A day that records nothing a person did** — the end-of-day job's absence,
 *    an approval's day opened ahead of time ({@see AttendanceRecord::isPlaceholder()})
 *    — is re-judged by the plan as it now stands: a new assignment that makes the
 *    date a rest day makes that absence a day off.
 *  - **A day somebody punched keeps its schedule and policy.** Re-applying the
 *    current ones stays HR's explicit action.
 *  - **Working days the job has already closed that now have no record** — a rest
 *    day that became a working day — get one, as the job would have written;
 *    never before the first date it closed.
 *  - **Nothing in a locked period moves** (ADR 0039).
 *
 * It needs no worker: it is dispatched to run once the response has gone, in the
 * same process — for the reason SystemNotification pins its channels to `sync`:
 * the app is often served without `queue:work`, and a recompute that silently
 * never runs is worse than one that runs late. It is idempotent, so a deployment
 * that does queue it loses nothing.
 */
class RecomputeAttendanceRange implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @param  list<int>|null  $employeeIds  Everybody when null.
     */
    public function __construct(
        public int $organizationId,
        public ?array $employeeIds,
        public string $from,
        public string $to,
        public string $reason,
    ) {}

    public function handle(Tenancy $tenancy, AttendanceClock $clock, DayCloser $closer): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null || $this->to < $this->from) {
            return;
        }

        $tenancy->runFor($organization, function () use ($organization, $clock, $closer): void {
            $holidays = HolidayCalendar::inRange(CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
            $locked = $clock->lockedPeriodsBetween($this->from, $this->to);
            $changed = 0;

            AttendanceRecord::query()
                ->whereBetween('work_date', [$this->from, $this->to])
                ->when($this->employeeIds !== null, fn (Builder $query) => $query->whereIn('employee_id', $this->employeeIds))
                ->with('employee')
                ->orderBy('work_date')
                ->orderBy('id')
                ->chunk(200, function (Collection $records) use ($clock, $holidays, $locked, &$changed): void {
                    foreach ($records as $record) {
                        /** @var AttendanceRecord $record */
                        $date = $record->work_date->toDateString();

                        if ($locked->contains(fn (AttendancePeriod $period): bool => $period->covers($date))) {
                            continue;
                        }

                        if ($record->employee !== null && $record->isPlaceholder()) {
                            $changed += $clock->reapplySchedule($record, $holidays) ? 1 : 0;

                            continue;
                        }

                        $clock->syncHoliday($record, $holidays[$date] ?? null);
                        $clock->evaluate($record);

                        if ($record->isDirty()) {
                            $changed++;
                            $record->save();
                        }
                    }
                });

            $materialised = $this->materialiseClosedDates($organization, $closer);

            if ($changed + $materialised > 0) {
                ActivityLogger::log(
                    event: 'updated',
                    description: "Brought attendance in line after {$this->reason}: "
                        .($changed > 0 ? "{$changed} ".str('day')->plural($changed).' re-judged' : '')
                        .($changed > 0 && $materialised > 0 ? ', ' : '')
                        .($materialised > 0 ? "{$materialised} ".str('day')->plural($materialised).' recorded' : ''),
                    properties: ['from' => $this->from, 'to' => $this->to, 'employees' => $this->employeeIds, 'changed' => $changed, 'recorded' => $materialised],
                    logName: 'attendance',
                    subjectLabel: 'Attendance',
                );
            }
        });
    }

    /**
     * Write the records the end-of-day job would have, for dates in range it has
     * already closed — never before the first date it closed, so a change of
     * plan does not back-fill the history from before the job ran.
     */
    private function materialiseClosedDates(Organization $organization, DayCloser $closer): int
    {
        $closedFrom = $organization->attendance_closed_from?->toDateString();
        $closedThrough = $organization->attendance_closed_through?->toDateString();

        if ($closedFrom === null || $closedThrough === null) {
            return 0;
        }

        $from = max($this->from, $closedFrom);
        $to = min($this->to, $closedThrough);

        if ($to < $from) {
            return 0;
        }

        $employees = $this->employeeIds === null ? null : Employee::query()
            ->whereIn('id', $this->employeeIds)
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->get();

        return $closer->materialise($from, $to, $employees);
    }
}
