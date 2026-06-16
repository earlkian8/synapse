<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Derives an {@see AttendanceRecord}'s summary from its punch events and the
 * employee's {@see WorkSchedule}: worked / break minutes, lateness, undertime,
 * overtime and the daily status. Pure and side-effect-free apart from mutating
 * the passed record's attributes (the caller persists).
 *
 * Time arithmetic is done on UNIX timestamps so it is agnostic to the app's
 * mutable/immutable date setting (the app uses {@see CarbonImmutable}).
 */
class AttendanceCalculator
{
    /**
     * Recompute every derived field on the record from its (chronological)
     * punches. Does not save — the caller does.
     */
    public static function recompute(AttendanceRecord $record, ?WorkSchedule $schedule, bool $onApprovedLeave = false): void
    {
        /** @var Collection<int, AttendancePunch> $punches */
        $punches = $record->punches instanceof Collection
            ? $record->punches->sortBy(['punched_at', 'id'])->values()
            : collect();

        $firstIn = $punches->firstWhere('type', 'clock_in')?->punched_at;
        $lastOut = $punches->where('type', 'clock_out')->last()?->punched_at;

        [$worked, $break] = self::accumulate($punches);

        $scheduledStart = self::scheduledMoment($record->work_date, $record->scheduled_start);
        $scheduledEnd = self::scheduledMoment($record->work_date, $record->scheduled_end);
        $grace = (int) ($schedule?->grace_minutes ?? 0);
        $requiredMinutes = (int) round((float) ($schedule?->required_hours ?? 8) * 60);

        $late = ($firstIn && $scheduledStart)
            ? max(0, self::minutesBetween($scheduledStart->addMinutes($grace), $firstIn))
            : 0;

        $undertime = ($lastOut && $scheduledEnd && $lastOut->lt($scheduledEnd))
            ? self::minutesBetween($lastOut, $scheduledEnd)
            : 0;

        $overtime = max(0, $worked - $requiredMinutes);

        $record->first_in_at = $firstIn;
        $record->last_out_at = $lastOut;
        $record->worked_minutes = $worked;
        $record->break_minutes = $break;
        $record->late_minutes = $late;
        $record->undertime_minutes = $undertime;
        $record->overtime_minutes = $overtime;
        $record->status = self::status($record, $schedule, $onApprovedLeave, $punches->isNotEmpty());
    }

    /**
     * Walk the punches as a state machine, accumulating on-the-clock minutes
     * (excluding breaks) and break minutes. Returns [worked, break].
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array{0: int, 1: int}
     */
    private static function accumulate(Collection $punches): array
    {
        $worked = 0;
        $break = 0;
        $prev = null;
        $onClock = false;
        $onBreak = false;

        foreach ($punches as $punch) {
            if ($prev !== null) {
                $minutes = self::minutesBetween($prev, $punch->punched_at);

                if ($onClock && $onBreak) {
                    $break += $minutes;
                } elseif ($onClock) {
                    $worked += $minutes;
                }
            }

            match ($punch->type) {
                'clock_in' => $onClock = true,
                'clock_out' => [$onClock, $onBreak] = [false, false],
                'break_start' => $onBreak = true,
                'break_end' => $onBreak = false,
                default => null,
            };

            $prev = $punch->punched_at;
        }

        return [$worked, $break];
    }

    /**
     * Derive the daily status. No-punch days resolve to day_off / on_leave /
     * absent; an open day (clocked in, never out) is incomplete; otherwise the
     * late / undertime flags drive present vs late vs undertime.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     */
    private static function status(AttendanceRecord $record, ?WorkSchedule $schedule, bool $onApprovedLeave, bool $hasPunches): string
    {
        if (! $hasPunches) {
            if ($onApprovedLeave) {
                return 'on_leave';
            }

            if (! self::isWorkingDay($record->work_date, $schedule)) {
                return 'day_off';
            }

            return 'absent';
        }

        if ($record->first_in_at && ! $record->last_out_at) {
            return 'incomplete';
        }

        if ($record->late_minutes > 0) {
            return 'late';
        }

        if ($record->undertime_minutes > 0) {
            return 'undertime';
        }

        return 'present';
    }

    /**
     * Whether the given date is a scheduled working day. Falls back to Mon–Fri
     * when no schedule (or no work_days) is set. Work days are stored as short
     * names, e.g. ["Mon","Tue",...].
     */
    public static function isWorkingDay(CarbonInterface $date, ?WorkSchedule $schedule): bool
    {
        $days = $schedule?->work_days;

        if (! is_array($days) || $days === []) {
            return ! $date->isWeekend();
        }

        return in_array($date->format('D'), $days, true);
    }

    /**
     * Combine the record's date with a stored "HH:MM[:SS]" time into a moment.
     */
    private static function scheduledMoment(CarbonInterface $date, ?string $time): ?CarbonInterface
    {
        $time = trim((string) $time);

        return $time === '' ? null : $date->copy()->setTimeFromTimeString($time);
    }

    /**
     * Whole minutes between two moments (b − a), clamped at zero.
     */
    private static function minutesBetween(CarbonInterface $a, CarbonInterface $b): int
    {
        return max(0, intdiv($b->getTimestamp() - $a->getTimestamp(), 60));
    }
}
