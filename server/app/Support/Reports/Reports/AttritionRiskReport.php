<?php

namespace App\Support\Reports\Reports;

use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Concerns\ReadsPredictionRun;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The latest attrition-risk assessment as a report (ADR 0099): everyone it scored,
 * highest risk first, with the strongest reason raising each score — and the
 * workforce view the Attrition Risk page used to draw: how the tiers split, risk by
 * department, and which inputs raise risk for the most people. Pay is never a
 * column; "Monthly salary" can only appear as the name of a reason.
 */
class AttritionRiskReport implements Report
{
    use BuildsReport, ReadsPredictionRun;

    private const TIER_LABELS = ['high' => 'High risk', 'medium' => 'At watch', 'low' => 'Stable'];

    public function key(): string
    {
        return 'attrition-risk';
    }

    public function name(): string
    {
        return 'Attrition Risk';
    }

    public function description(): string
    {
        return 'Everyone in the latest attrition-risk assessment, highest risk first, with what raises each score most.';
    }

    public function group(): string
    {
        return 'Analytics';
    }

    public function permission(): string
    {
        return 'analytics.attrition.view';
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
            ['key' => 'score', 'label' => 'Risk', 'align' => 'right', 'type' => 'number'],
            ['key' => 'tier', 'label' => 'Tier', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'reason', 'label' => 'Raised most by', 'align' => 'left', 'type' => 'text'],
            ['key' => 'confidence', 'label' => 'Inputs on record', 'align' => 'right', 'type' => 'text'],
        ];
    }

    /** @var array<string, Collection<int, AttritionRiskScore>> The scores read per filter set. */
    private array $scores = [];

    public function rows(array $params): Collection
    {
        return $this->scores($params)->map(fn (AttritionRiskScore $score): array => [
            ...$this->personCells($score->employee),
            'score' => (int) round((float) $score->score),
            'tier' => self::TIER_LABELS[$score->tier] ?? $score->tier,
            'reason' => $this->reason($score),
            'confidence' => round((float) $score->confidence * 100).'%',
        ])->values();
    }

    /**
     * The latest run's scores under the filters, read once for the rows and charts.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, AttritionRiskScore>
     */
    private function scores(array $params): Collection
    {
        return $this->scores[md5(serialize($params))] ??= (function () use ($params): Collection {
            $run = $this->latestRun(AttritionRiskRun::class);

            if ($run === null) {
                return collect();
            }

            $query = AttritionRiskScore::query()->where('attrition_risk_run_id', $run->id)->ranked();
            $this->applyPersonFilters($query, $params, 'tier', 'tier');

            return $query->get();
        })();
    }

    /**
     * The strongest input raising the score, with its points when the run has them.
     */
    private function reason(AttritionRiskScore $score): string
    {
        $top = collect($score->factors ?? [])->firstWhere('direction', 'up');

        if ($top === null) {
            return '—';
        }

        return isset($score->explanation['baseline'])
            ? "{$top['label']} {$this->points((float) $top['impact'] * 100)}"
            : $top['label'];
    }

    public function summary(Collection $rows, array $params): array
    {
        $run = $this->latestRun(AttritionRiskRun::class);

        return [
            ['label' => 'Assessed', 'value' => number_format($rows->count())],
            ['label' => 'High risk', 'value' => number_format($rows->where('tier', self::TIER_LABELS['high'])->count())],
            ['label' => 'At watch', 'value' => number_format($rows->where('tier', self::TIER_LABELS['medium'])->count())],
            ['label' => 'Average risk', 'value' => $rows->isEmpty() ? '—' : (string) round((float) $rows->avg('score'))],
            ['label' => 'Assessed on', 'value' => $run?->created_at?->toFormattedDateString() ?? '—'],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->donut('By risk tier', collect(self::TIER_LABELS)
                ->mapWithKeys(fn (string $label): array => [$label => $rows->where('tier', $label)->count()])
                ->all()),
            $this->averagesBy('Average risk by department', $rows, 'department', 'score'),
            $this->bars('What raises risk for the most people', $this->scores($params)
                ->flatMap(fn (AttritionRiskScore $score): array => collect($score->factors ?? [])->where('direction', 'up')->pluck('label')->all())
                ->countBy()
                ->sortDesc()
                ->take(6)
                ->map(fn (int $count, string $label): array => ['label' => $label, 'value' => $count])
                ->values()
                ->all()),
        ];
    }
}
