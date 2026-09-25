<?php

namespace App\Support\Ml;

use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;

/**
 * How a forecast run did, once the period it forecast has completed appraisals
 * (ADR 0045). Every forecast promised a range that four in five next ratings land
 * in and a band with a stated chance of being right; this checks those promises
 * against this organisation's own results — the only test of the reference-trained
 * model that says anything about *this* workforce.
 */
class ForecastTrackRecord
{
    /**
     * The run's track record, or null when it forecast no period.
     *
     * @return array{checked: int, forecasts: int, mean_error: ?float, within_range: ?float, band_right: ?float, expected_band_right: ?float, actuals: array<int, float>}|null
     */
    public function for(PerformanceForecastRun $run): ?array
    {
        if ($run->target_period_id === null) {
            return null;
        }

        $forecasts = $run->relationLoaded('forecasts') ? $run->forecasts : $run->forecasts()->get();

        /** @var array<int, float> $actuals */
        $actuals = PerformanceEvaluation::query()
            ->completed()
            ->where('evaluation_period_id', $run->target_period_id)
            ->whereIn('employee_id', $forecasts->pluck('employee_id'))
            ->orderBy('id')
            ->get(['employee_id', 'overall_percent'])
            ->mapWithKeys(fn (PerformanceEvaluation $e): array => [$e->employee_id => round((float) $e->overall_percent, 2)])
            ->all();

        $checked = $forecasts->filter(fn (PerformanceForecast $f): bool => isset($actuals[$f->employee_id]));
        $n = $checked->count();

        if ($n === 0) {
            return ['checked' => 0, 'forecasts' => $forecasts->count(), 'mean_error' => null, 'within_range' => null,
                'band_right' => null, 'expected_band_right' => null, 'actuals' => []];
        }

        $errors = $checked->map(fn (PerformanceForecast $f): float => abs($actuals[$f->employee_id] - (float) $f->predicted_rating));
        $ranged = $checked->filter(fn (PerformanceForecast $f): bool => $f->predicted_low !== null && $f->predicted_high !== null);
        $inside = $ranged->filter(fn (PerformanceForecast $f): bool => $actuals[$f->employee_id] >= (float) $f->predicted_low
            && $actuals[$f->employee_id] <= (float) $f->predicted_high);
        $bandRight = $checked->filter(fn (PerformanceForecast $f): bool => PerformanceForecast::bandFor($actuals[$f->employee_id]) === $f->band);

        return [
            'checked' => $n,
            'forecasts' => $forecasts->count(),
            'mean_error' => round($errors->avg(), 1),
            'within_range' => $ranged->isEmpty() ? null : round($inside->count() / $ranged->count(), 3),
            'band_right' => round($bandRight->count() / $n, 3),
            // What the forecasts' own confidence said to expect.
            'expected_band_right' => round($checked->avg(fn (PerformanceForecast $f): float => (float) $f->confidence), 3),
            'actuals' => $actuals,
        ];
    }
}
