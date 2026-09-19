<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Judges one attendance day: punches + the {@see DayRules} frozen onto it (the
 * shift, the holiday and — from ADR 0038 — the attendance policy) + a
 * {@see DayContext} → a {@see DayResult}. Pure: it never reads the database, the
 * clock, or the employee's current schedule, so every rule is table-testable.
 *
 * The shift's edges are instants worked out in the organisation's zone when the
 * day opened, so a night shift's 06:00 end is the next morning rather than sixteen
 * hours before it started (ADR 0036). How strictly arrival and departure are
 * judged depends on the schedule's type (ADR 0037): `fixed` against the shift's
 * own edges, `flexible` against the core window, `hours_only` against the hours.
 *
 * **Order of operations** ({@see evaluate()}), each step reading only what the
 * steps before it produced:
 *
 *  1. **Round** clock-in and clock-out punches on the local clock. The punches
 *     themselves are never changed — only the times the day is judged by.
 *  2. **Pair** the punches into work and break intervals. A stretch still open
 *     (clocked in, not out) counts nothing yet.
 *  3. **Clip** work before a fixed shift's start, when early clock-ins do not count.
 *  4. **Breaks** — pay back the paid part of a punched break; deduct the unpaid
 *     break nobody punched; note a break that ran over.
 *  5. **Late and undertime** against the shift, after grace (per day, or out of a
 *     monthly allowance). An over-long break is owed like leaving early.
 *     Approved official business (ADR 0039) forgives both, and makes the day
 *     worth at least the shift's hours.
 *  6. **Buckets** — overtime (daily, weekly, both, rest-day / holiday), regular,
 *     approved overtime, night, rest day, holiday. Overtime is approved outright
 *     unless the policy asks for approval; then up to what has been granted.
 *  7. **Thresholds** — very late or very short is a half day, or an absence.
 *  8. **Status and flags.** What capture recorded on the punches (ADR 0040)
 *     becomes flags here too — a punch off site, from a source the policy does
 *     not allow, out of order on a device, from a clock that was wrong, or
 *     written by the end-of-day job — so they are derived like every other flag
 *     and cannot drift from the punches.
 *
 * With the built-in fallback policy this reproduces the pre-policy numbers
 * exactly: no rounding, no clipping, breaks as punched, grace and required
 * minutes from the shift, overtime as whatever was worked beyond them.
 *
 * Time arithmetic is done on UNIX timestamps, and whole minutes are taken per
 * interval, as they always were, so no day moves by a rounding second.
 */
class AttendanceCalculator
{
    /**
     * Evaluate a day from its punches (any order), its rules and its context.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     */
    public static function evaluate(Collection $punches, DayRules $rules, DayContext $context): DayResult
    {
        $policy = $rules->policy;
        $punches = $punches->sortBy(['punched_at', 'id'])->values();

        $firstIn = self::immutable($punches->firstWhere('type', 'clock_in')?->punched_at);
        $lastOut = self::immutable($punches->where('type', 'clock_out')->last()?->punched_at);

        if ($punches->isEmpty()) {
            return self::officialBusinessCovers($rules, $context)
                ? new DayResult(
                    status: 'present',
                    flags: ['official_business'],
                    workedMinutes: $rules->requiredMinutes,
                    regularMinutes: $rules->requiredMinutes,
                )
                : new DayResult(status: self::noPunchStatus($rules, $context->onApprovedLeave));
        }

        // 1. Round.
        $events = self::round($punches, $policy, $context->timezone);
        $judgedIn = self::firstOf($events, 'clock_in');
        $judgedOut = self::lastOf($events, 'clock_out');
        $closed = $lastOut !== null;

        // 2. Pair.
        [$work, $breaks] = self::intervals($events);

        // 3. Clip early minutes off a fixed shift.
        if (! $policy->overtimeCountEarlyClockIn && $rules->type === 'fixed' && $context->scheduledStart !== null) {
            $work = self::clipBefore($work, $context->scheduledStart->getTimestamp());
        }

        $worked = self::sumMinutes($work);
        $break = self::sumMinutes($breaks);
        $flags = [];

        // 4. Breaks.
        $worked += min($break, $policy->paidBreakMinutes);

        if ($break === 0 && $closed && $policy->autoDeductBreakMinutes > 0
            && $worked >= $policy->autoDeductAfterWorkedMinutes && $worked > 0) {
            $break = min($policy->autoDeductBreakMinutes, $worked);
            $worked -= $break;
            $flags[] = 'break_deducted';
        }

        $breakExcess = $policy->maxBreakMinutes !== null ? max(0, $break - $policy->maxBreakMinutes) : 0;

        if ($breakExcess > 0) {
            $flags[] = 'break_exceeded';
        }

        // 5. Late and undertime.
        [$late, $excused] = self::late($rules, $context, $judgedIn);
        $undertime = self::undertime($rules, $worked, $closed ? $judgedOut : null, $context->scheduledEnd)
            + ($closed ? $breakExcess : 0);

        // A day on official business is a full working day (ADR 0039): the part of
        // it spent away from the clock is neither late nor short, and the day is
        // worth at least the shift's hours.
        if (self::officialBusinessCovers($rules, $context)) {
            [$late, $excused, $undertime] = [0, 0, 0];
            $worked = $closed ? max($worked, $rules->requiredMinutes) : $worked;
            $flags[] = 'official_business';
        }

        if ($context->remoteWork) {
            $flags[] = 'remote_work';
        }

        array_push($flags, ...self::captureFlags($punches, $rules, $context));

        // 6. Buckets.
        $overtime = self::overtime($rules, $context, $worked);
        $regular = $worked - $overtime;
        $approved = self::approvedOvertime($policy, $context, $overtime);
        $night = $policy->nightEnabled ? min($worked, self::nightMinutes($work, $policy, $context->timezone)) : 0;
        $restDay = $rules->isWorkingDay ? 0 : $worked;
        $holiday = $rules->isNonWorkingHoliday() ? $worked : 0;

        // Awaiting approval only until somebody decides: a rejected request, or
        // one that granted less than was worked, is a decision too.
        if ($overtime > $approved && $context->grantedOvertimeMinutes === null) {
            $flags[] = 'unapproved_overtime';
        }

        if ($restDay > 0) {
            $flags[] = 'rest_day_worked';
        }

        if ($holiday > 0) {
            $flags[] = 'holiday_worked';
        }

        if ($late > 0) {
            $flags[] = 'late';
        }

        if ($undertime > 0) {
            $flags[] = 'undertime';
        }

        // 7 & 8. Thresholds, status.
        $status = match (true) {
            $firstIn !== null && ! $closed => 'incomplete',
            default => self::judgedStatus($rules, $late, $undertime, $worked, $flags),
        };

        if ($status === 'half_day') {
            $flags[] = 'half_day';
        }

        // Still open when the end-of-day job closed the day (ADR 0041): the
        // policy left it for HR rather than closing it.
        if ($status === 'incomplete' && $context->closed) {
            $flags[] = 'missing_clock_out';
        }

        return new DayResult(
            status: $status,
            flags: array_values(array_unique($flags)),
            firstInAt: $firstIn,
            lastOutAt: $lastOut,
            workedMinutes: $worked,
            breakMinutes: $break,
            lateMinutes: $late,
            excusedLateMinutes: $excused,
            undertimeMinutes: $undertime,
            regularMinutes: $regular,
            overtimeMinutes: $overtime,
            approvedOvertimeMinutes: $approved,
            nightMinutes: $night,
            restDayMinutes: $restDay,
            holidayMinutes: $holiday,
        );
    }

    /**
     * Recompute every derived field on a record from its (loaded) punches, its
     * rules and the context the caller gathered. A thin adapter over
     * {@see evaluate()}. Does not save — the caller does.
     */
    public static function recompute(AttendanceRecord $record, DayRules $rules, DayContext $context): void
    {
        /** @var Collection<int, AttendancePunch> $punches */
        $punches = $record->punches instanceof Collection ? $record->punches : collect();

        self::evaluate($punches, $rules, $context)->applyTo($record);
    }

    /**
     * How much of the day's overtime is approved. All of it, unless the policy
     * wants approval — then what has been granted (approved overtime requests,
     * or HR signing the day off), never more than was worked (ADR 0039).
     */
    private static function approvedOvertime(AttendancePolicySettings $policy, DayContext $context, int $overtime): int
    {
        if (! $policy->overtimeRequiresApproval) {
            return $overtime;
        }

        return min($overtime, max(0, $context->grantedOvertimeMinutes ?? 0));
    }

    /**
     * What the punches' capture says about the day (ADR 0040, ADR 0041):
     *
     *  - `outside_geofence` — a punch was not shown to be on site, while the
     *    policy checks where people punch. A day of approved remote work or
     *    official business is exempt: being elsewhere was the point.
     *  - `source_not_allowed` — a device sent a punch from a source the policy
     *    does not allow. A person's punch like that is refused; a device's is
     *    recorded, and flagged.
     *  - `device_sequence_anomaly` — a device's punch breaks the day's order (in
     *    twice, out without in, a break outside the shift). Recorded as it came.
     *  - `clock_skew` — the clock that stamped a punch was further off the
     *    server's than the policy tolerates.
     *  - `auto_closed` — the end-of-day job wrote the clock-out.
     *
     * @param  Collection<int, AttendancePunch>  $punches  Sorted.
     * @return list<string>
     */
    private static function captureFlags(Collection $punches, DayRules $rules, DayContext $context): array
    {
        $policy = $rules->policy;
        $flags = [];
        $excused = $context->remoteWork || $context->officialBusiness;

        if ($policy->geofence !== 'off' && ! $excused && $punches->contains(fn (AttendancePunch $punch): bool => $punch->within_geofence === false)) {
            $flags[] = 'outside_geofence';
        }

        if ($punches->contains(fn (AttendancePunch $punch): bool => in_array($punch->source, AttendancePunch::CAPTURE_SOURCES, true)
            && ! in_array($punch->source, $policy->allowedSources, true))) {
            $flags[] = 'source_not_allowed';
        }

        if (self::deviceBrokeTheOrder($punches)) {
            $flags[] = 'device_sequence_anomaly';
        }

        if ($punches->contains(fn (AttendancePunch $punch): bool => $punch->clock_skew_seconds !== null
            && abs((int) $punch->clock_skew_seconds) > $policy->maxClockSkewMinutes * 60)) {
            $flags[] = 'clock_skew';
        }

        if ($punches->contains(fn (AttendancePunch $punch): bool => $punch->source === 'system')) {
            $flags[] = 'auto_closed';
        }

        return $flags;
    }

    /**
     * Whether a punch a device sent breaks the order a day is punched in. Only a
     * device's can: a person's punch that would is refused before it is written.
     *
     * @param  Collection<int, AttendancePunch>  $punches  Sorted.
     */
    private static function deviceBrokeTheOrder(Collection $punches): bool
    {
        $onClock = false;
        $onBreak = false;

        foreach ($punches as $punch) {
            $valid = match ($punch->type) {
                'clock_in' => ! $onClock,
                'clock_out' => $onClock,
                'break_start' => $onClock && ! $onBreak,
                'break_end' => $onBreak,
                default => true,
            };

            if (! $valid && ($punch->attendance_device_id !== null || $punch->source === 'biometric')) {
                return true;
            }

            match ($punch->type) {
                'clock_in' => $onClock = true,
                'clock_out' => [$onClock, $onBreak] = [false, false],
                'break_start' => $onBreak = $onClock,
                'break_end' => $onBreak = false,
                default => null,
            };
        }

        return false;
    }

    /**
     * Whether approved official business makes this a full working day — only a
     * working day, and never one approved leave or a holiday already excuses.
     */
    private static function officialBusinessCovers(DayRules $rules, DayContext $context): bool
    {
        return $context->officialBusiness
            && $rules->isWorkingDay
            && ! $rules->isNonWorkingHoliday()
            && ! $context->onApprovedLeave;
    }

    /**
     * What a day with no punches is. Excused by approved leave; otherwise a
     * holiday nobody is expected to work; otherwise a rest day; and only then an
     * absence. A `special_working` holiday is an ordinary working day.
     *
     * Public because the roster queries synthesise a status for days that have no
     * record, and they must reach the same verdict a record would.
     */
    public static function noPunchStatus(DayRules $rules, bool $onApprovedLeave): string
    {
        return match (true) {
            $onApprovedLeave => 'on_leave',
            $rules->isNonWorkingHoliday() => 'holiday',
            ! $rules->isWorkingDay => 'day_off',
            default => 'absent',
        };
    }

    /**
     * The status of a closed day with punches. The policy's thresholds come
     * first — they only ever apply to a working day, since a rest day asks for no
     * hours to fall short of — then late, then undertime.
     *
     * @param  list<string>  $flags  Gains `late_absent` / `below_minimum` when a threshold is the reason.
     */
    private static function judgedStatus(DayRules $rules, int $late, int $undertime, int $worked, array &$flags): string
    {
        $policy = $rules->policy;

        if ($rules->isWorkingDay) {
            if ($policy->lateAbsentAfterMinutes !== null && $late > $policy->lateAbsentAfterMinutes) {
                $flags[] = 'late_absent';

                return 'absent';
            }

            if ($policy->minimumMinutesForPresent !== null && $worked < $policy->minimumMinutesForPresent) {
                $flags[] = 'below_minimum';

                return 'absent';
            }

            if (($policy->lateHalfDayAfterMinutes !== null && $late > $policy->lateHalfDayAfterMinutes)
                || ($policy->undertimeHalfDayBelowMinutes !== null && $worked < $policy->undertimeHalfDayBelowMinutes)) {
                return 'half_day';
            }
        }

        return match (true) {
            $late > 0 => 'late',
            $undertime > 0 => 'undertime',
            default => 'present',
        };
    }

    /**
     * How late the arrival was, and how much lateness grace forgave — by the
     * schedule's type and the policy's grace mode. Returns [late, excused].
     *
     *  - `fixed` — against the shift's start.
     *  - `flexible` — against the core window opening: arriving at 09:45 for a
     *    10:00 core is not late, however the shift is written.
     *  - `hours_only`, or a policy that does not judge lateness — never.
     *
     * Grace `per_day` forgives up to N minutes each day; a `monthly_allowance`
     * forgives from one pool for the month, so the day that exhausts it is the
     * first one that counts.
     *
     * @return array{0: int, 1: int}
     */
    private static function late(DayRules $rules, DayContext $context, ?int $firstIn): array
    {
        $policy = $rules->policy;

        if ($firstIn === null || $rules->type === 'hours_only' || ! $policy->lateEnabled) {
            return [0, 0];
        }

        $against = $rules->type === 'flexible' ? $rules->coreStartAt : $context->scheduledStart;

        if ($against === null) {
            return [0, 0];
        }

        $raw = max(0, intdiv($firstIn - $against->getTimestamp(), 60));

        $allowance = $policy->graceMode === 'monthly_allowance'
            ? max(0, $policy->monthlyGraceMinutes - $context->monthExcusedLateMinutesBefore)
            : $rules->graceMinutes();

        $excused = min($raw, $allowance);

        return [$raw - $excused, $excused];
    }

    /**
     * How much of the day was left owing.
     *
     *  - `fixed` — the minutes between leaving and the shift's end.
     *  - `flexible` — whichever is worse: leaving before the core window closes,
     *    or falling short of the day's hours.
     *  - `hours_only`, or a policy judging undertime on hours alone — the hours,
     *    whenever they were worked.
     *
     * An open day (no clock-out yet) is not short until it is closed.
     */
    private static function undertime(DayRules $rules, int $worked, ?int $lastOut, ?CarbonImmutable $scheduledEnd): int
    {
        if ($lastOut === null) {
            return 0;
        }

        $short = max(0, $rules->requiredMinutes - $worked);

        if ($rules->policy->undertimeBasis === 'hours') {
            return $short;
        }

        return match ($rules->type) {
            'hours_only' => $short,
            'flexible' => max(
                $rules->coreEndAt !== null && $lastOut < $rules->coreEndAt->getTimestamp()
                    ? intdiv($rules->coreEndAt->getTimestamp() - $lastOut, 60)
                    : 0,
                $short,
            ),
            default => $scheduledEnd !== null && $lastOut < $scheduledEnd->getTimestamp()
                ? intdiv($scheduledEnd->getTimestamp() - $lastOut, 60)
                : 0,
        };
    }

    /**
     * The day's overtime under the policy's basis.
     *
     *  - A rest day or non-working holiday the policy calls "all overtime" is
     *    exactly that.
     *  - `daily` — beyond the policy's daily threshold (or the day's required
     *    minutes).
     *  - `weekly` — the part of today's regular minutes that carries the week past
     *    its threshold. Earlier days' *regular* minutes are what count towards it,
     *    so under `daily_and_weekly` a minute already paid as daily overtime is
     *    never counted a second time.
     *
     * Less than the minimum block is no overtime at all.
     */
    private static function overtime(DayRules $rules, DayContext $context, int $worked): int
    {
        $policy = $rules->policy;

        if ($policy->overtimeBasis === 'none' || $worked <= 0) {
            return 0;
        }

        if ((! $rules->isWorkingDay && $policy->overtimeRestDayAllOvertime)
            || ($rules->isNonWorkingHoliday() && $policy->overtimeHolidayAllOvertime)) {
            return self::block($worked, $policy);
        }

        $daily = in_array($policy->overtimeBasis, ['daily', 'daily_and_weekly'], true)
            ? max(0, $worked - $rules->dailyOvertimeAfter())
            : 0;

        $weekly = 0;

        if ($policy->needsWeekContext()) {
            $candidate = $worked - $daily;
            $weekly = max(0, min($candidate, $context->weekRegularMinutesBefore + $candidate - $policy->overtimeWeeklyAfterMinutes));
        }

        return self::block($daily + $weekly, $policy);
    }

    private static function block(int $overtime, AttendancePolicySettings $policy): int
    {
        return $overtime < $policy->overtimeMinBlockMinutes ? 0 : $overtime;
    }

    /**
     * Minutes of work inside the night window, read on the local clock. Each
     * work interval is checked against the windows that open the evening before
     * it, on its own date and on the next, so a shift across midnight is counted
     * once and in full.
     *
     * @param  list<array{0: int, 1: int}>  $work
     */
    private static function nightMinutes(array $work, AttendancePolicySettings $policy, string $timezone): int
    {
        $total = 0;

        foreach ($work as [$start, $end]) {
            $from = CarbonImmutable::createFromTimestamp($start, $timezone)->startOfDay()->subDay();
            $until = CarbonImmutable::createFromTimestamp($end, $timezone)->startOfDay();
            $seconds = 0;

            for ($day = $from; $day->lte($until); $day = $day->addDay()) {
                $windowStart = self::at($day, $policy->nightStart, $timezone);
                $windowEnd = self::at($day, $policy->nightEnd, $timezone);

                if ($windowEnd->lte($windowStart)) {
                    $windowEnd = self::at($day->addDay(), $policy->nightEnd, $timezone);
                }

                $seconds += max(0, min($end, $windowEnd->getTimestamp()) - max($start, $windowStart->getTimestamp()));
            }

            $total += intdiv($seconds, 60);
        }

        return $total;
    }

    /**
     * The day's punches as [type, timestamp] events, with clock-ins and
     * clock-outs rounded as the policy says — on the organisation's clock, and
     * never earlier than the event before them, so rounding cannot reorder a day.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return list<array{0: string, 1: int}>
     */
    private static function round(Collection $punches, AttendancePolicySettings $policy, string $timezone): array
    {
        $events = [];
        $floor = null;

        foreach ($punches as $punch) {
            $at = $punch->punched_at->getTimestamp();

            $applies = $policy->roundingMode !== 'none' && match ($punch->type) {
                'clock_in' => in_array($policy->roundingApplyTo, ['in', 'both'], true),
                'clock_out' => in_array($policy->roundingApplyTo, ['out', 'both'], true),
                default => false,
            };

            if ($applies) {
                $at = self::roundInstant($at, $policy->roundingMode, $policy->roundingUnit, $timezone);
            }

            if ($floor !== null && $at < $floor) {
                $at = $floor;
            }

            $events[] = [$punch->type, $at];
            $floor = $at;
        }

        return $events;
    }

    /**
     * Round an instant to the unit on the local clock: "nearest", "up" or "down".
     */
    private static function roundInstant(int $at, string $mode, int $unit, string $timezone): int
    {
        $offset = CarbonImmutable::createFromTimestamp($at, $timezone)->getOffset();
        $local = $at + $offset;
        $step = $unit * 60;

        $rounded = match ($mode) {
            'up' => (int) ceil($local / $step) * $step,
            'down' => intdiv($local, $step) * $step,
            default => (int) round($local / $step) * $step,
        };

        return $rounded - $offset;
    }

    /**
     * Walk the events as a state machine and return the [start, end] timestamps
     * of every on-the-clock stretch and every break. A stretch nobody has closed
     * yet is left out, as it always was: an open day counts what is finished.
     *
     * @param  list<array{0: string, 1: int}>  $events
     * @return array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>}
     */
    private static function intervals(array $events): array
    {
        $work = [];
        $breaks = [];
        $prev = null;
        $onClock = false;
        $onBreak = false;

        foreach ($events as [$type, $at]) {
            if ($prev !== null && $onClock) {
                if ($onBreak) {
                    $breaks[] = [$prev, $at];
                } else {
                    $work[] = [$prev, $at];
                }
            }

            match ($type) {
                'clock_in' => $onClock = true,
                'clock_out' => [$onClock, $onBreak] = [false, false],
                'break_start' => $onBreak = true,
                'break_end' => $onBreak = false,
                default => null,
            };

            $prev = $at;
        }

        return [$work, $breaks];
    }

    /**
     * Drop the part of each interval that falls before an instant.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function clipBefore(array $intervals, int $at): array
    {
        $out = [];

        foreach ($intervals as [$start, $end]) {
            if ($end > $at) {
                $out[] = [max($start, $at), $end];
            }
        }

        return $out;
    }

    /**
     * Whole minutes across intervals, taken interval by interval.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private static function sumMinutes(array $intervals): int
    {
        $total = 0;

        foreach ($intervals as [$start, $end]) {
            $total += max(0, intdiv($end - $start, 60));
        }

        return $total;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $events
     */
    private static function firstOf(array $events, string $type): ?int
    {
        foreach ($events as [$eventType, $at]) {
            if ($eventType === $type) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $events
     */
    private static function lastOf(array $events, string $type): ?int
    {
        $found = null;

        foreach ($events as [$eventType, $at]) {
            if ($eventType === $type) {
                $found = $at;
            }
        }

        return $found;
    }

    /**
     * A wall-clock reading on a local date, as an instant.
     */
    private static function at(CarbonImmutable $day, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString().' '.$time, $timezone);
    }

    private static function immutable(?CarbonInterface $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc();
    }
}
