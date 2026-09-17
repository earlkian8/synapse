<?php

namespace App\Support\Attendance;

use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Illuminate\Support\Facades\DB;

/**
 * Writes a schedule's day pattern (ADR 0037) — the one place a template's cycle
 * is saved, so Company Setup, the assistant and any future importer agree.
 *
 * It also keeps the pre-pattern columns (`start_time`, `end_time`, `work_days`)
 * as a faithful summary of the pattern: the first working day's first segment,
 * its last segment's end, and the weekday names that are not rest days. Those
 * columns are still read by the employee screens and the assistant, and a stale
 * summary would have them disagree with the roster. They go when those readers
 * move to {@see ShiftResolver}.
 */
class SchedulePatternWriter
{
    /**
     * Replace a schedule's cycle with the given days, and refresh the summary.
     *
     * @param  list<array<string, mixed>>  $days  One entry per day of the cycle, in order.
     */
    public function write(WorkSchedule $schedule, array $days): void
    {
        DB::transaction(function () use ($schedule, $days): void {
            $schedule->days()->delete();

            $rows = [];

            foreach (array_values($days) as $index => $day) {
                $isRestDay = (bool) ($day['is_rest_day'] ?? false);
                $segments = $isRestDay ? [] : $this->segments($day['segments'] ?? []);

                $rows[] = [
                    'organization_id' => $schedule->organization_id,
                    'work_schedule_id' => $schedule->id,
                    'day_index' => $index + 1,
                    'is_rest_day' => $isRestDay || $segments === [],
                    'segments' => $segments,
                    'required_minutes' => $this->requiredMinutes($day, $segments),
                    'core_start' => $isRestDay ? null : $this->time($day['core_start'] ?? null),
                    'core_end' => $isRestDay ? null : $this->time($day['core_end'] ?? null),
                    'earliest_start' => $isRestDay ? null : $this->time($day['earliest_start'] ?? null),
                    'latest_end' => $isRestDay ? null : $this->time($day['latest_end'] ?? null),
                    'unpaid_break_minutes' => max(0, (int) ($day['unpaid_break_minutes'] ?? 0)),
                ];
            }

            foreach ($rows as $row) {
                WorkScheduleDay::create($row);
            }

            $schedule->setRelation('days', $schedule->days()->get());
            $this->syncSummary($schedule);
        });
    }

    /**
     * Refresh the schedule's legacy summary columns from its pattern.
     */
    public function syncSummary(WorkSchedule $schedule): void
    {
        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $days = $schedule->patternDays();

        $working = $days->filter(fn (WorkScheduleDay $day): bool => ! $day->is_rest_day);
        $first = $working->first();
        $segments = is_array($first?->segments) ? array_values($first->segments) : [];

        // A rotation has no weekday names to give; its summary keeps the hours and
        // leaves the days to the resolver.
        $workDays = $schedule->isRotating()
            ? []
            : $working
                ->keys()
                ->map(fn (int $index): ?string => $names[$index - 1] ?? null)
                ->filter()
                ->values()
                ->all();

        $schedule->forceFill([
            'start_time' => $segments === [] ? null : $segments[0]['start'],
            'end_time' => $segments === [] ? null : $segments[array_key_last($segments)]['end'],
            'work_days' => $workDays,
            'required_hours' => round(($first?->required_minutes ?? DayRules::DEFAULT_REQUIRED_MINUTES) / 60, 2),
        ])->save();
    }

    /**
     * The day's target, in minutes: what was asked for, or how long its segments
     * actually run when nothing was.
     *
     * @param  array<string, mixed>  $day
     * @param  list<array{start: string, end: string}>  $segments
     */
    private function requiredMinutes(array $day, array $segments): int
    {
        if (isset($day['required_minutes']) && $day['required_minutes'] !== '' && $day['required_minutes'] !== null) {
            return max(0, (int) $day['required_minutes']);
        }

        $total = 0;

        foreach ($segments as $segment) {
            $start = $this->minuteOfDay($segment['start']);
            $end = $this->minuteOfDay($segment['end']);

            $total += $end <= $start ? 1440 - $start + $end : $end - $start;
        }

        return $total ?: DayRules::DEFAULT_REQUIRED_MINUTES;
    }

    /**
     * Well-formed "HH:MM" segment pairs, in the order they were given.
     *
     * @param  mixed  $segments
     * @return list<array{start: string, end: string}>
     */
    private function segments($segments): array
    {
        if (! is_array($segments)) {
            return [];
        }

        $out = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $start = $this->time($segment['start'] ?? null);
            $end = $this->time($segment['end'] ?? null);

            if ($start !== null && $end !== null) {
                $out[] = ['start' => $start, 'end' => $end];
            }
        }

        return $out;
    }

    /**
     * A submitted time as "HH:MM", or null when it is blank.
     */
    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 5);
    }

    /**
     * "HH:MM" as minutes past midnight.
     */
    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }
}
