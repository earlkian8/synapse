<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The policy editor's worked example (ADR 0038): one sample day, judged by the
 * settings on screen, before they are saved.
 *
 * It runs the real {@see AttendanceCalculator::evaluate()} rather than a copy of
 * it in the browser, so what the editor promises is exactly what the board will
 * do — there is no second set of rules to drift. Nothing is written: the punches
 * are unsaved models and the day is never stored.
 *
 * The sample is a fixed shift on the organisation's clock. The date is the
 * organisation's today, so a night window or a rounding step is read in the zone
 * the company actually keeps.
 */
class WorkedExample
{
    /**
     * @param  array{shift_start: string, shift_end: string, required_minutes: int, grace_minutes: int, day: string, time_in: string, time_out: ?string, break_start: ?string, break_end: ?string, week_regular_minutes_before?: int, month_excused_late_minutes_before?: int}  $sample
     * @return array<string, mixed>
     */
    public static function evaluate(AttendancePolicySettings $settings, array $sample): array
    {
        $date = OrganizationClock::today();
        $isWorkingDay = $sample['day'] !== 'rest_day';

        $shift = new ResolvedShift(
            date: $date,
            type: 'fixed',
            isWorkingDay: $isWorkingDay,
            segments: $isWorkingDay ? [['start' => $sample['shift_start'], 'end' => $sample['shift_end']]] : [],
            requiredMinutes: $isWorkingDay ? (int) $sample['required_minutes'] : 0,
            graceMinutes: (int) $sample['grace_minutes'],
            unpaidBreakMinutes: 0,
            source: 'fallback',
        );

        $rules = new DayRules(
            graceMinutes: $shift->graceMinutes,
            requiredMinutes: $shift->requiredMinutes,
            isWorkingDay: $shift->isWorkingDay,
            holidayType: $sample['day'] === 'holiday' ? 'regular' : null,
            holidayName: $sample['day'] === 'holiday' ? 'Sample holiday' : null,
            segments: $shift->segments,
            policy: $settings,
        );

        $punches = self::punches($date, [
            'clock_in' => $sample['time_in'],
            'break_start' => $sample['break_start'] ?? null,
            'break_end' => $sample['break_end'] ?? null,
            'clock_out' => $sample['time_out'] ?? null,
        ]);

        $result = AttendanceCalculator::evaluate($punches, $rules, new DayContext(
            // A rest day has no shift to be late for or leave early from — exactly
            // as a real one's record has no shift instants.
            scheduledStart: $shift->startsAt(),
            scheduledEnd: $shift->endsAt(),
            timezone: OrganizationClock::timezone(),
            weekRegularMinutesBefore: (int) ($sample['week_regular_minutes_before'] ?? 0),
            monthExcusedLateMinutesBefore: (int) ($sample['month_excused_late_minutes_before'] ?? 0),
        ));

        return $result->toArray();
    }

    /**
     * The sample's punches as unsaved models, each reading on the organisation's
     * clock — one earlier than the reading before it is the next morning, the same
     * rule HR's manual entry follows.
     *
     * @param  array<string, ?string>  $times
     * @return Collection<int, AttendancePunch>
     */
    private static function punches(string $date, array $times): Collection
    {
        $next = CarbonImmutable::parse($date)->addDay()->toDateString();
        $previous = null;
        $punches = collect();

        foreach ($times as $type => $time) {
            if ($time === null || trim($time) === '') {
                continue;
            }

            $at = OrganizationClock::at($date, $time);

            if ($previous !== null && $at->lt($previous)) {
                $at = OrganizationClock::at($next, $time);
            }

            $punch = new AttendancePunch(['type' => $type, 'punched_at' => $at]);
            $punch->id = $punches->count() + 1;
            $punches->push($punch);
            $previous = $at;
        }

        return $punches;
    }
}
