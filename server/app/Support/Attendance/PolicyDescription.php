<?php

namespace App\Support\Attendance;

/**
 * An attendance policy in words (ADR 0038) — what each group of settings
 * amounts to, in a line.
 *
 * The server's copy of the policy editor's `groupSummaries()` /
 * `policyHeadline()` (`features/attendance-policy-config/constants.ts`), so the
 * assistant describes a policy in the same words its screen does. Where the
 * editor says it one way, this says it the same way; keep the two in step.
 *
 * Capture is summarised without its network ranges: the count is what anybody
 * asking needs, and the addresses are network configuration.
 */
final class PolicyDescription
{
    /**
     * What each group is called, in the order the editor shows them.
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        'lateness' => 'Lateness',
        'undertime' => 'Undertime',
        'rounding' => 'Rounding',
        'breaks' => 'Breaks',
        'overtime' => 'Overtime',
        'night' => 'Night differential',
        'missing_clock_out' => 'Missing clock-out',
        'reminders' => 'Reminders',
        'punch_windows' => 'Punch windows',
        'capture' => 'Capture',
    ];

    /**
     * Each group as "Lateness: 10m grace a day, half day past 2h".
     *
     * @return list<string>
     */
    public static function lines(AttendancePolicySettings $s): array
    {
        $groups = self::groups($s);

        return array_map(fn (string $key): string => self::GROUPS[$key].': '.$groups[$key], array_keys(self::GROUPS));
    }

    /**
     * What each group of settings amounts to.
     *
     * @return array<string, string>
     */
    public static function groups(AttendancePolicySettings $s): array
    {
        $lateness = ! $s->lateEnabled
            ? 'Nobody is marked late'
            : implode(', ', array_filter([
                match (true) {
                    $s->graceMode === 'monthly_allowance' => self::duration($s->monthlyGraceMinutes).' of grace a month',
                    $s->graceMinutes === null => 'Each schedule’s grace',
                    default => self::duration($s->graceMinutes).' grace a day',
                },
                $s->lateHalfDayAfterMinutes !== null ? 'half day past '.self::duration($s->lateHalfDayAfterMinutes) : null,
                $s->lateAbsentAfterMinutes !== null ? 'absent past '.self::duration($s->lateAbsentAfterMinutes) : null,
            ]));

        $undertime = implode(', ', array_filter([
            $s->undertimeBasis === 'hours' ? 'Short by the hours worked' : 'Short by leaving before the shift ends',
            $s->undertimeHalfDayBelowMinutes !== null ? 'half day under '.self::duration($s->undertimeHalfDayBelowMinutes) : null,
            $s->minimumMinutesForPresent !== null ? 'absent under '.self::duration($s->minimumMinutesForPresent) : null,
        ]));

        $rounding = $s->roundingMode === 'none'
            ? 'Exact times'
            : sprintf(
                '%s %d minutes, %s',
                match ($s->roundingMode) {
                    'nearest' => 'Nearest',
                    'up' => 'Up to the',
                    default => 'Down to the',
                },
                $s->roundingUnit,
                match ($s->roundingApplyTo) {
                    'in' => 'clock-in only',
                    'out' => 'clock-out only',
                    default => 'in and out',
                },
            );

        $breaks = implode(', ', array_filter([
            match (true) {
                $s->autoDeductBreakMinutes > 0 => self::duration($s->autoDeductBreakMinutes).' unpaid after '.self::duration($s->autoDeductAfterWorkedMinutes).' if none is punched',
                $s->paidBreakMinutes > 0 => self::duration($s->paidBreakMinutes).' of a break is paid',
                default => 'Breaks count as punched',
            },
            $s->maxBreakMinutes !== null ? 'flagged past '.self::duration($s->maxBreakMinutes) : null,
        ]));

        $overtime = $s->overtimeBasis === 'none'
            ? 'No overtime'
            : implode(', ', array_filter([
                $s->overtimeBasis !== 'weekly'
                    ? ($s->overtimeDailyAfterMinutes === null ? 'After the day’s hours' : 'After '.self::duration($s->overtimeDailyAfterMinutes).' a day')
                    : null,
                $s->overtimeBasis !== 'daily' ? 'after '.self::duration($s->overtimeWeeklyAfterMinutes).' a week' : null,
                $s->overtimeMinBlockMinutes > 0 ? 'at least '.self::duration($s->overtimeMinBlockMinutes).' at a time' : null,
                $s->overtimeRequiresApproval ? 'needs approval' : null,
                $s->overtimeRestDayAllOvertime ? 'all of a rest day' : null,
                $s->overtimeHolidayAllOvertime ? 'all of a holiday' : null,
            ]));

        $capture = implode(', ', array_filter([
            count($s->allowedSources).' '.(count($s->allowedSources) === 1 ? 'way' : 'ways').' to punch ('.implode(', ', $s->allowedSources).')',
            $s->selfieRequired ? 'selfie required' : null,
            match ($s->geofence) {
                'flag' => 'flagged off site',
                'block' => 'on site only',
                default => null,
            },
            $s->webIpAllowlist !== [] ? 'web punches from '.count($s->webIpAllowlist).' office '.(count($s->webIpAllowlist) === 1 ? 'network' : 'networks').' only' : null,
        ]));

        return [
            'lateness' => $lateness,
            'undertime' => $undertime,
            'rounding' => $rounding,
            'breaks' => $breaks,
            'overtime' => $overtime,
            'night' => $s->nightEnabled ? "{$s->nightStart}–{$s->nightEnd}" : 'No night differential',
            'missing_clock_out' => match ($s->missingClockOutAction) {
                'auto_close_at_shift_end' => 'Close it at the shift’s end',
                'auto_close_after_minutes' => 'Close it '.self::duration($s->missingClockOutAfterMinutes).' after the shift',
                default => 'Leave it open for HR',
            },
            'reminders' => $s->clockInReminderAfterMinutes === null
                ? 'No reminders'
                : 'Remind '.self::duration($s->clockInReminderAfterMinutes).' into the shift',
            'punch_windows' => 'Clock in up to '.self::duration($s->earlyClockInMinutes).' early; shifts up to '.self::duration($s->maxShiftSpanMinutes),
            'capture' => $capture,
        ];
    }

    /**
     * The handful of rules a policy leads with — what sets it apart.
     *
     * @return list<string>
     */
    public static function headline(AttendancePolicySettings $s): array
    {
        $groups = self::groups($s);

        return array_values(array_filter([
            $groups['overtime'],
            $s->autoDeductBreakMinutes > 0 ? $groups['breaks'] : null,
            $s->nightEnabled ? 'Night '.$groups['night'] : null,
            $s->roundingMode !== 'none' ? $groups['rounding'] : null,
            ! $s->lateEnabled ? $groups['lateness'] : null,
        ]));
    }

    /**
     * "1h 30m" for a minute count, as the screens write it.
     */
    public static function duration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return "{$rest}m";
        }

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }
}
