<?php

namespace App\Support\Performance;

use App\Models\PerformanceGoal;

/**
 * How far along a goal is, and what a person's goals add up to (ADR 0073). Pure
 * math, shared by the screens, the scorecard's decision support and the
 * assistant, so a goal reads the same percentage everywhere.
 *
 * Progress is the distance travelled from the start value toward the target, as
 * 0–100. The span is signed, so a goal to *bring a number down* ("defects from 40
 * to 10") works the same way as one to bring it up.
 */
class GoalProgress
{
    /**
     * Progress from `$start` toward `$target`, as 0–100, clamped. A goal whose
     * target is its start is done the moment it is set.
     */
    public static function percent(float $start, float $target, float $current): float
    {
        $span = $target - $start;

        if ($span == 0.0) {
            return $current == $target ? 100.0 : 0.0;
        }

        return round(max(0.0, min(1.0, ($current - $start) / $span)) * 100, 1);
    }

    /**
     * The weight-averaged progress of a set of goals (dropped goals already left
     * out by the caller), as 0–100 — or null when there are none. An achieved
     * goal counts in full whatever its last check-in said.
     *
     * @param  iterable<int, PerformanceGoal>  $goals
     */
    public static function attainment(iterable $goals): ?float
    {
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($goals as $goal) {
            if ($goal->status === 'dropped') {
                continue;
            }

            $weight = max(0.0, (float) $goal->weight) ?: 1.0;
            $progress = $goal->status === 'achieved' ? 100.0 : $goal->progress();

            $weighted += $progress * $weight;
            $weights += $weight;
        }

        return $weights > 0 ? round($weighted / $weights, 1) : null;
    }

    /**
     * A value in the goal's own terms — "72%", "31 deals", "PHP 1,200,000".
     */
    public static function format(float $value, string $measure, ?string $unit): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');

        if ($measure === 'percent') {
            return $number.'%';
        }

        return $unit === null || $unit === '' ? $number : "{$number} {$unit}";
    }
}
