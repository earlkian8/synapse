<?php

namespace App\Support\Reports\Reports;

use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Concerns\ReadsPredictionRun;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The latest promotion-readiness assessment as a report (ADR 0099): everyone it
 * scored, most ready first, with the appraisal record each score rests on — and the
 * workforce view the Promotion Readiness page used to draw: how the tiers split,
 * readiness by department, and how much history the scores rest on.
 */
class PromotionReadinessReport implements Report
{
    use BuildsReport, ReadsPredictionRun;

    private const TIER_LABELS = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    private const BASIS_LABELS = ['two_appraisals' => 'Two appraisals', 'latest_appraisal' => 'One appraisal'];

    public function key(): string
    {
        return 'promotion-readiness';
    }

    public function name(): string
    {
        return 'Promotion Readiness';
    }

    public function description(): string
    {
        return 'Everyone in the latest promotion-readiness assessment, most ready first, with the appraisals each score rests on.';
    }

    public function group(): string
    {
        return 'Analytics';
    }

    public function permission(): string
    {
        return 'analytics.promotion.view';
    }

    public function filters(): array
    {
        return [
            $this->departmentFilter(),
            $this->selectFilter('tier', 'Tier', self::TIER_LABELS, 'All tiers'),
            $this->searchFilter('Name or employee no…'),
        ];
    }

    public function columns(): array
    {
        return [
            ...$this->personColumns(),
            ['key' => 'score', 'label' => 'Readiness', 'align' => 'right', 'type' => 'number'],
            ['key' => 'tier', 'label' => 'Tier', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'latest', 'label' => 'Latest appraisal', 'align' => 'right', 'type' => 'text'],
            ['key' => 'change', 'label' => 'Change', 'align' => 'right', 'type' => 'text'],
            ['key' => 'basis', 'label' => 'Rests on', 'align' => 'left', 'type' => 'text'],
            ['key' => 'reason', 'label' => 'Moved most by', 'align' => 'left', 'type' => 'text'],
        ];
    }

    public function rows(array $params): Collection
    {
        $run = $this->latestRun(PromotionReadinessRun::class);

        if ($run === null) {
            return collect();
        }

        $query = PromotionReadinessScore::query()->where('promotion_readiness_run_id', $run->id)->ranked();
        $this->applyPersonFilters($query, $params, 'tier', 'tier');

        return $query->get()->map(function (PromotionReadinessScore $score): array {
            $features = $score->features ?? [];
            $top = collect($score->factors ?? [])->first();

            return [
                ...$this->personCells($score->employee),
                'score' => (int) round((float) $score->score),
                'tier' => self::TIER_LABELS[$score->tier] ?? $score->tier,
                'latest' => isset($features['rating_latest']) ? number_format((float) $features['rating_latest'], 1).'%' : '—',
                'change' => isset($features['rating_change'])
                    ? ((float) $features['rating_change'] >= 0 ? '+' : '−').number_format(abs((float) $features['rating_change']), 1).' pts'
                    : '—',
                'basis' => self::BASIS_LABELS[$score->basis] ?? '—',
                'reason' => $top === null ? '—' : (isset($score->explanation['baseline'])
                    ? "{$top['label']} {$this->points((float) $top['impact'])}"
                    : $top['label']),
            ];
        })->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        $run = $this->latestRun(PromotionReadinessRun::class);

        return [
            ['label' => 'Assessed', 'value' => number_format($rows->count())],
            ['label' => 'High', 'value' => number_format($rows->where('tier', self::TIER_LABELS['high'])->count())],
            ['label' => 'Medium', 'value' => number_format($rows->where('tier', self::TIER_LABELS['medium'])->count())],
            ['label' => 'Average readiness', 'value' => $rows->isEmpty() ? '—' : (string) round((float) $rows->avg('score'))],
            ['label' => 'Assessed on', 'value' => $run?->created_at?->toFormattedDateString() ?? '—'],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->donut('By readiness tier', collect(self::TIER_LABELS)
                ->mapWithKeys(fn (string $label): array => [$label => $rows->where('tier', $label)->count()])
                ->all()),
            $this->averagesBy('Average readiness by department', $rows, 'department', 'score'),
            $this->donut('What the scores rest on', collect(self::BASIS_LABELS)
                ->mapWithKeys(fn (string $label): array => [$label => $rows->where('basis', $label)->count()])
                ->all()),
        ];
    }
}
