<?php

namespace App\Support\Reports;

use App\Models\AttritionRiskRun;
use App\Models\PerformanceForecastRun;
use App\Models\PromotionReadinessRun;

/**
 * Decision-support signals pulled from the *persisted* ML model runs (attrition,
 * promotion readiness, performance forecast). Reading the stored summaries — not the
 * live inference service — keeps signals available even when the model API is offline,
 * and means a report never blocks on a network call.
 *
 * These ride alongside the relevant reports as headline chips, and feed the LLM
 * insights so the "why" can lean on the models, not just the descriptive numbers.
 */
class MlSignals
{
    /** Report groups that get ML signals attached. */
    private const SIGNAL_GROUPS = ['Workforce', 'Attendance'];

    /**
     * The signals relevant to a report group (empty for groups without a model tie-in).
     *
     * @return list<array<string, mixed>>
     */
    public function forGroup(string $group): array
    {
        if (! in_array($group, self::SIGNAL_GROUPS, true)) {
            return [];
        }

        return array_values(array_filter([
            $this->attrition(),
            $this->promotion(),
            $this->forecast(),
        ]));
    }

    /**
     * Latest attrition-risk run summary.
     *
     * @return array<string, mixed>|null
     */
    public function attrition(): ?array
    {
        $run = AttritionRiskRun::query()->latestFirst()->first();

        if ($run === null) {
            return null;
        }

        $total = $run->high_count + $run->medium_count + $run->low_count;

        return [
            'key' => 'attrition',
            'label' => 'Attrition risk',
            'tone' => 'rose',
            'href' => '/analytics/attrition',
            'value' => $run->high_count.' high-risk',
            'detail' => 'of '.$total.' assessed · '.($run->created_at?->diffForHumans() ?? 'recently'),
            'breakdown' => ['High' => $run->high_count, 'Medium' => $run->medium_count, 'Low' => $run->low_count],
        ];
    }

    /**
     * Latest promotion-readiness run summary.
     *
     * @return array<string, mixed>|null
     */
    public function promotion(): ?array
    {
        $run = PromotionReadinessRun::query()->latestFirst()->first();

        if ($run === null) {
            return null;
        }

        $total = $run->high_count + $run->medium_count + $run->low_count;

        return [
            'key' => 'promotion',
            'label' => 'Promotion readiness',
            'tone' => 'teal',
            'href' => '/analytics/promotion-readiness',
            'value' => $run->high_count.' ready',
            'detail' => 'of '.$total.' assessed · '.($run->created_at?->diffForHumans() ?? 'recently'),
            'breakdown' => ['Ready' => $run->high_count, 'Developing' => $run->medium_count, 'Not yet' => $run->low_count],
        ];
    }

    /**
     * Latest performance-forecast run summary.
     *
     * @return array<string, mixed>|null
     */
    public function forecast(): ?array
    {
        $run = PerformanceForecastRun::query()->latestFirst()->first();

        if ($run === null) {
            return null;
        }

        return [
            'key' => 'forecast',
            'label' => 'Performance outlook',
            'tone' => 'amber',
            'href' => '/analytics/performance-forecast',
            'value' => $run->below_count.' below track',
            'detail' => $run->exceeds_count.' exceeding · '.($run->created_at?->diffForHumans() ?? 'recently'),
            'breakdown' => ['Exceeds' => $run->exceeds_count, 'On track' => $run->on_track_count, 'Below' => $run->below_count],
        ];
    }
}
