<?php

namespace App\Support\Performance;

use App\Models\PerformanceEvaluation;
use Illuminate\Support\Collection;

/**
 * The read HR actually needs on a review cycle: not "here are 240 appraisals",
 * but **how did this company rate itself**. Two questions:
 *
 *  - *Coverage* — who has been appraised, who is still open, who has not been
 *    started at all.
 *  - *Calibration* — how the results spread across the tenant's own rating
 *    bands, and whether one department is rating far softer or harder than the
 *    rest. Rating inflation is invisible one scorecard at a time and obvious
 *    the moment the distribution is on screen.
 *
 * Everything is derived from the evaluations already loaded for the page, and
 * read through each appraisal's **own** rating model — a cycle may run several
 * frameworks at once, so bands are grouped by the words they were reported in.
 */
class PerformanceCalibration
{
    /**
     * The band distribution for a set of appraisals, ordered from the highest cut
     * down. Only completed appraisals carry a band, so drafts sit outside it.
     *
     * @param  Collection<int, PerformanceEvaluation>  $evaluations
     * @return list<array{key: string, label: string, tone: string, min_percent: float, count: int, share: float}>
     */
    public function distribution(Collection $evaluations): array
    {
        $completed = $evaluations->filter(
            fn (PerformanceEvaluation $e): bool => $e->result_label !== null && $e->overall_percent !== null
        );

        if ($completed->isEmpty()) {
            return [];
        }

        $bands = [];

        foreach ($completed as $evaluation) {
            $band = collect($evaluation->bandList())
                ->firstWhere('key', $evaluation->result_band)
                ?? ['key' => (string) $evaluation->result_band, 'label' => (string) $evaluation->result_label, 'tone' => 'neutral', 'min_percent' => 0.0];

            $key = $band['label'];

            $bands[$key] ??= [
                'key' => $band['key'],
                'label' => $band['label'],
                'tone' => $band['tone'],
                'min_percent' => (float) $band['min_percent'],
                'count' => 0,
                'share' => 0.0,
            ];

            $bands[$key]['count']++;
            // A cycle can carry the same words at different cuts; report the
            // highest, so the ordering is the strictest reading of the label.
            $bands[$key]['min_percent'] = max($bands[$key]['min_percent'], (float) $band['min_percent']);
        }

        $total = $completed->count();

        $bands = array_map(function (array $band) use ($total): array {
            $band['share'] = round($band['count'] / $total * 100, 1);

            return $band;
        }, array_values($bands));

        usort($bands, fn (array $a, array $b): int => $b['min_percent'] <=> $a['min_percent']);

        return $bands;
    }

    /**
     * Per-department calibration: how many appraisals, how far through them, and
     * the average attainment — the view that exposes a soft or harsh rater.
     *
     * @param  Collection<int, PerformanceEvaluation>  $evaluations
     * @return list<array{department: string, total: int, completed: int, average_percent: float|null, top_band_share: float|null}>
     */
    public function byDepartment(Collection $evaluations): array
    {
        $rows = $evaluations
            ->groupBy(fn (PerformanceEvaluation $e): string => $e->employee?->department?->name ?? 'Unassigned')
            ->map(function (Collection $group, string $department): array {
                $completed = $group->filter(fn (PerformanceEvaluation $e): bool => $e->overall_percent !== null && $e->result_label !== null);

                return [
                    'department' => $department,
                    'total' => $group->count(),
                    'completed' => $completed->count(),
                    'average_percent' => $completed->isEmpty()
                        ? null
                        : round((float) $completed->avg(fn (PerformanceEvaluation $e): float => (float) $e->overall_percent), 1),
                    'top_band_share' => $completed->isEmpty()
                        ? null
                        : round($completed->filter(fn (PerformanceEvaluation $e): bool => $this->isTopBand($e))->count() / $completed->count() * 100, 1),
                ];
            })
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['department'], $b['department']));

        return $rows;
    }

    /**
     * Headline numbers for the cycle, including how much of the workforce it has
     * actually reached.
     *
     * @param  Collection<int, PerformanceEvaluation>  $evaluations
     * @return array<string, int|float|null>
     */
    public function summary(Collection $evaluations, int $eligible): array
    {
        $completed = $evaluations->filter(fn (PerformanceEvaluation $e): bool => $e->overall_percent !== null && in_array($e->status, ['submitted', 'acknowledged'], true));

        return [
            'total' => $evaluations->count(),
            'draft' => $evaluations->where('status', 'draft')->count(),
            'submitted' => $evaluations->where('status', 'submitted')->count(),
            'acknowledged' => $evaluations->where('status', 'acknowledged')->count(),
            'eligible' => $eligible,
            'coverage' => $eligible > 0 ? round(min($evaluations->count(), $eligible) / $eligible * 100, 1) : null,
            'average_percent' => $completed->isEmpty()
                ? null
                : round((float) $completed->avg(fn (PerformanceEvaluation $e): float => (float) $e->overall_percent), 1),
            'average_score' => $completed->isEmpty()
                ? null
                : round((float) $completed->avg(fn (PerformanceEvaluation $e): float => (float) $e->overall_score), 2),
        ];
    }

    /**
     * Whether an appraisal landed in the top band of its own rating model.
     */
    private function isTopBand(PerformanceEvaluation $evaluation): bool
    {
        $top = $evaluation->bandList()[0] ?? null;

        return $top !== null && $top['key'] === $evaluation->result_band;
    }
}
