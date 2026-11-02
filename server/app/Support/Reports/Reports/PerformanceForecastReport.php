<?php

namespace App\Support\Reports\Reports;

use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Concerns\ReadsPredictionRun;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The latest performance forecast as a report (ADR 0099): everyone it forecast,
 * highest first, with the range each next rating is likely to land in and the chance
 * of its band — and the workforce view the Performance Forecast page used to draw:
 * how the bands split and the forecasts by department.
 */
class PerformanceForecastReport implements Report
{
    use BuildsReport, ReadsPredictionRun;

    private const BAND_LABELS = ['exceeds' => 'Exceeds', 'on_track' => 'On track', 'below' => 'Below target'];

    public function key(): string
    {
        return 'performance-forecast';
    }

    public function name(): string
    {
        return 'Performance Forecast';
    }

    public function description(): string
    {
        return 'Everyone in the latest performance forecast, highest first, with the range each next rating is likely to land in.';
    }

    public function group(): string
    {
        return 'Analytics';
    }

    public function permission(): string
    {
        return 'analytics.performance.view';
    }

    public function filters(): array
    {
        return [
            $this->departmentFilter(),
            $this->selectFilter('band', 'Band', self::BAND_LABELS, 'All bands'),
            $this->searchFilter('Name or employee no…'),
        ];
    }

    public function columns(): array
    {
        return [
            ...$this->personColumns(),
            ['key' => 'forecast', 'label' => 'Forecast', 'align' => 'right', 'type' => 'number'],
            ['key' => 'range', 'label' => 'Likely range', 'align' => 'right', 'type' => 'text'],
            ['key' => 'band', 'label' => 'Band', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'chance', 'label' => 'Chance of band', 'align' => 'right', 'type' => 'text'],
            ['key' => 'latest', 'label' => 'Latest appraisal', 'align' => 'right', 'type' => 'text'],
        ];
    }

    public function rows(array $params): Collection
    {
        $run = $this->latestRun(PerformanceForecastRun::class);

        if ($run === null) {
            return collect();
        }

        $query = PerformanceForecast::query()->where('performance_forecast_run_id', $run->id)->ranked();
        $this->applyPersonFilters($query, $params, 'band', 'band');

        return $query->get()->map(function (PerformanceForecast $forecast): array {
            $latest = $forecast->features['rating_latest'] ?? null;

            return [
                ...$this->personCells($forecast->employee),
                'forecast' => round((float) $forecast->predicted_rating, 1),
                'range' => $forecast->predicted_low !== null && $forecast->predicted_high !== null
                    ? round((float) $forecast->predicted_low).'–'.round((float) $forecast->predicted_high)
                    : '—',
                'band' => self::BAND_LABELS[$forecast->band] ?? $forecast->band,
                'chance' => round((float) $forecast->confidence * 100).'%',
                'latest' => $latest === null ? '—' : number_format((float) $latest, 1).'%',
            ];
        })->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        $run = $this->latestRun(PerformanceForecastRun::class);
        $run?->loadMissing('targetPeriod:id,name');

        return [
            ['label' => 'Forecast', 'value' => number_format($rows->count())],
            ['label' => 'Exceeds', 'value' => number_format($rows->where('band', self::BAND_LABELS['exceeds'])->count())],
            ['label' => 'Below target', 'value' => number_format($rows->where('band', self::BAND_LABELS['below'])->count())],
            ['label' => 'Average forecast', 'value' => $rows->isEmpty() ? '—' : number_format((float) $rows->avg('forecast'), 1)],
            ['label' => 'Forecasting', 'value' => $run?->targetPeriod?->name ?? ($run?->created_at?->toFormattedDateString() ?? '—')],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->donut('By band', collect(self::BAND_LABELS)
                ->mapWithKeys(fn (string $label): array => [$label => $rows->where('band', $label)->count()])
                ->all()),
            $this->averagesBy('Average forecast by department', $rows, 'department', 'forecast'),
        ];
    }
}
