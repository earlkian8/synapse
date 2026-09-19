<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\ActivityLogger;
use App\Support\HolidayCalendar;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Closes attendance days (ADR 0041) — what `attendance:close-day` runs, hourly,
 * for each organisation on its own clock.
 *
 * A date is closed in three steps, each only once nobody can still change it by
 * punching:
 *
 *  1. **Materialise.** Everybody due to work that date who has no record gets
 *     one — absent, on leave, a holiday, or present on official business — with
 *     its snapshot, once their shift has ended (after that no clock-in can open
 *     the day). So every past working day is a record, and the board, the report
 *     and the payroll summary read it rather than guess.
 *  2. **Handle forgotten clock-outs** as each day's policy says
 *     ({@see AttendanceClock::closeForgottenDay()}), once the shift can no longer
 *     claim a punch — its `max_shift_span_minutes` since the clock-in. A night
 *     shift therefore closes the morning after.
 *  3. **Tell people.** When nothing about the date is still waiting, the
 *     organisation's `attendance_closed_through` moves to it and one digest of
 *     its exceptions goes to each recipient.
 *
 * Sign-off needs no step of its own: a day the job auto-closed carries
 * `auto_closed`, a review flag, so it is `pending` by derivation (ADR 0039).
 *
 * Dates are walked from the day after the last one closed (at most
 * {@see CATCH_UP_DAYS} back, so a scheduler that stopped for a week catches up
 * without rewriting history), up to yesterday on the organisation's calendar.
 * Idempotent: a closed date is never walked again, a record is never written
 * twice, a day already closed is not closed again.
 */
class DayCloser
{
    /** How far back the job reaches for dates it has not closed yet. */
    public const CATCH_UP_DAYS = 7;

    /** Employment statuses that are due at work — suspended and separated people are not marked absent. */
    public const WORKING_STATUSES = ['active', 'on_leave'];

    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly ShiftResolver $shifts = new ShiftResolver,
        private readonly PolicyResolver $policies = new PolicyResolver,
    ) {}

    /**
     * Close what can be closed for the bound organisation.
     *
     * @return array{dates: int, materialised: int, auto_closed: int, flagged: int, digests: int}
     */
    public function close(Organization $organization): array
    {
        $report = ['dates' => 0, 'materialised' => 0, 'auto_closed' => 0, 'flagged' => 0, 'digests' => 0];

        $yesterday = CarbonImmutable::parse(OrganizationClock::today())->subDay();
        $floor = $yesterday->subDays(self::CATCH_UP_DAYS - 1);
        $closedThrough = $organization->attendance_closed_through !== null
            ? CarbonImmutable::parse($organization->attendance_closed_through->toDateString())
            : null;

        $from = $closedThrough !== null ? $closedThrough->addDay()->max($floor) : $yesterday;
        $advancing = true;

        for ($day = $from; $day->lte($yesterday); $day = $day->addDay()) {
            $date = $day->toDateString();
            $done = $this->closeDate($date, $report);

            if ($done && $advancing) {
                $organization->forceFill([
                    'attendance_closed_from' => $organization->attendance_closed_from ?? $date,
                    'attendance_closed_through' => $date,
                ])->save();
                $report['dates']++;
                $report['digests'] += $this->digest($date);
            } else {
                // Dates close in order: a later one's digest waits for this one.
                $advancing = false;
            }
        }

        return $report;
    }

    /**
     * Write the record of every working day nobody punched, for some people
     * across a range — the end-of-day job's first step, and how a change of plan
     * reaches dates already closed ({@see RecomputeAttendanceRange}). Only days
     * whose shift has ended; returns how many were written.
     *
     * @param  Collection<int, Employee>|null  $employees  Everybody due at work when null.
     */
    public function materialise(string $from, string $to, ?Collection $employees = null): int
    {
        $employees ??= $this->workforce($to);

        if ($employees->isEmpty()) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $shifts = $this->shifts->forMany($employees, $from, $to);
        $policies = $this->policies->forMany($employees, $shifts);
        $holidays = HolidayCalendar::inRange(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
        $locked = $this->clock->lockedPeriodsBetween($from, $to);

        $existing = AttendanceRecord::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('work_date', [$from, $to])
            ->get(['employee_id', 'work_date'])
            ->map(fn (AttendanceRecord $record): string => $record->employee_id.'|'.$record->work_date->toDateString())
            ->flip();

        $written = 0;

        foreach ($employees as $employee) {
            foreach ($shifts[$employee->id] ?? [] as $date => $shift) {
                if (! $shift->isWorkingDay
                    || $existing->has($employee->id.'|'.$date)
                    || ! $this->employedOn($employee, $date)
                    || ! $this->hasEnded($shift, $now)
                    || $locked->contains(fn ($period): bool => $period->covers($date))) {
                    continue;
                }

                $this->clock->materialise($employee, $shift, $holidays[$date] ?? null, $policies[$employee->id][$date] ?? null);
                $written++;
            }
        }

        return $written;
    }

    /**
     * Close one date as far as it can be closed now; whether nothing about it is
     * still waiting.
     *
     * @param  array<string, int>  $report
     */
    private function closeDate(string $date, array &$report): bool
    {
        if ($this->clock->lockedPeriodsBetween($date, $date)->isNotEmpty()) {
            // A locked period is final already.
            return true;
        }

        $employees = $this->workforce($date);
        $shifts = $this->shifts->forMany($employees, $date, $date);
        $now = CarbonImmutable::now();

        $report['materialised'] += $this->materialise($date, $date, $employees);

        // Somebody due at work whose shift has not ended and who has no record
        // yet could still clock in to this date.
        $recorded = AttendanceRecord::query()->whereDate('work_date', $date)->pluck('employee_id')->flip();

        $waiting = $employees->contains(function (Employee $employee) use ($shifts, $date, $now, $recorded): bool {
            $shift = $shifts[$employee->id][$date] ?? null;

            return $shift !== null
                && $shift->isWorkingDay
                && ! $recorded->has($employee->id)
                && $this->employedOn($employee, $date)
                && ! $this->hasEnded($shift, $now);
        });

        $open = AttendanceRecord::query()
            ->whereDate('work_date', $date)
            ->whereNotNull('first_in_at')
            ->whereNull('last_out_at')
            ->whereNull('closed_at')
            ->get();

        foreach ($open as $record) {
            $span = $this->clock->policyOf($record)->maxShiftSpanMinutes;

            if ($now->lt(CarbonImmutable::instance($record->first_in_at)->addMinutes($span))) {
                $waiting = true;

                continue;
            }

            try {
                $outcome = $this->clock->closeForgottenDay($record);
            } catch (AttendanceException) {
                continue;
            }

            $report[$outcome === 'auto_closed' ? 'auto_closed' : 'flagged']++;

            ActivityLogger::log(
                event: 'updated',
                description: $outcome === 'auto_closed'
                    ? "Closed {$record->employee?->full_name}'s day on {$record->work_date->format('M j')} automatically: no clock-out was recorded"
                    : "Left {$record->employee?->full_name}'s day on {$record->work_date->format('M j')} open for HR: no clock-out was recorded",
                subject: $record,
                logName: 'attendance',
                subjectLabel: $record->employee?->full_name ?? 'employee',
            );
        }

        return ! $waiting;
    }

    /**
     * Send one digest of a closed date's exceptions to each person who should
     * hear about them: everybody holding `attendance.view` gets the whole
     * company's, and a manager who does not gets their own reports'. Nothing is
     * sent for a date with nothing to report. Returns how many were sent.
     */
    private function digest(string $date): int
    {
        $records = AttendanceRecord::query()
            ->with('employee:id,first_name,middle_name,last_name,suffix,manager_id,user_id')
            ->whereDate('work_date', $date)
            ->get()
            ->filter(fn (AttendanceRecord $record): bool => $this->exceptionsOf($record) !== []);

        if ($records->isEmpty()) {
            return 0;
        }

        $label = CarbonImmutable::parse($date)->format('D, M j');
        $url = '/attendance?date='.$date;

        $sent = Notifier::toPermission(
            'attendance.view',
            "Attendance exceptions for {$label}",
            $this->summarise($records),
            url: $url,
            level: 'warning',
            category: 'attendance',
        );

        // Managers who cannot see the board hear about their own people.
        $viewers = Notifier::holdersOf('attendance.view')->pluck('id');

        $byManager = $records->groupBy(fn (AttendanceRecord $record): ?int => $record->employee?->manager_id)->forget('');
        $managers = Employee::query()->whereIn('id', $byManager->keys())->whereNotNull('user_id')->with('user')->get();

        foreach ($managers as $manager) {
            if ($manager->user === null || ! $manager->user->is_active || $viewers->contains($manager->user_id)) {
                continue;
            }

            $sent += Notifier::toUser(
                $manager->user,
                "Your team's attendance for {$label}",
                $this->summarise($byManager->get($manager->id)),
                url: '/attendance/me',
                level: 'warning',
                category: 'attendance',
            );
        }

        return $sent;
    }

    /**
     * What is wrong with a closed day, as the digest counts it.
     *
     * @return list<string>
     */
    private function exceptionsOf(AttendanceRecord $record): array
    {
        $flags = $record->flags ?? [];
        $out = [];

        if ($record->status === 'absent' && $record->first_in_at === null) {
            $out[] = 'absent';
        }

        foreach (array_keys(self::DIGEST_FLAGS) as $flag) {
            if (in_array($flag, $flags, true)) {
                $out[] = $flag;
            }
        }

        return $out;
    }

    /** The flags a digest reports, and how it counts them. */
    private const DIGEST_FLAGS = [
        'missing_clock_out' => 'still missing a clock-out',
        'auto_closed' => 'closed automatically',
        'outside_geofence' => 'punched away from the site',
        'device_sequence_anomaly' => 'with device punches out of order',
        'clock_skew' => 'stamped by a clock that was off',
    ];

    /**
     * "2 absent without leave (Ana Cruz, Ben Reyes); 1 closed automatically
     * (Carla Diaz)."
     *
     * @param  SupportCollection<int, AttendanceRecord>  $records
     */
    private function summarise(SupportCollection $records): string
    {
        $lines = [];
        $kinds = ['absent' => 'absent without leave', ...self::DIGEST_FLAGS];

        foreach ($kinds as $kind => $words) {
            $names = $records
                ->filter(fn (AttendanceRecord $record): bool => in_array($kind, $this->exceptionsOf($record), true))
                ->map(fn (AttendanceRecord $record): string => $record->employee?->full_name ?? 'Someone')
                ->values();

            if ($names->isEmpty()) {
                continue;
            }

            $shown = $names->take(3)->implode(', ').($names->count() > 3 ? ' and '.($names->count() - 3).' more' : '');
            $lines[] = "{$names->count()} {$words} ({$shown})";
        }

        return ucfirst(implode('; ', $lines)).'.';
    }

    /**
     * The people who are due at work on or around a date — employed, and not
     * suspended or separated.
     *
     * @return Collection<int, Employee>
     */
    private function workforce(string $date): Collection
    {
        return Employee::query()
            ->whereIn('employment_status', self::WORKING_STATUSES)
            ->where(fn (Builder $query) => $query->whereNull('date_hired')->orWhereDate('date_hired', '<=', $date))
            ->get();
    }

    private function employedOn(Employee $employee, string $date): bool
    {
        return $employee->date_hired === null || $employee->date_hired->toDateString() <= $date;
    }

    /**
     * Whether a shift is over — the point after which no clock-in can open its
     * day. A shift with no end of its own (hours only) is over when its date is.
     */
    private function hasEnded(ResolvedShift $shift, CarbonImmutable $now): bool
    {
        $end = $shift->endsAt() ?? OrganizationClock::at(CarbonImmutable::parse($shift->date)->addDay()->toDateString(), '00:00');

        return $now->gte($end);
    }
}
