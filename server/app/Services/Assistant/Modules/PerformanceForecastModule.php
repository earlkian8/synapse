<?php

namespace App\Services\Assistant\Modules;

use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Models\User;
use App\Services\Assistant\Security\UntrustedText;
use App\Support\Ml\ForecastTrackRecord;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlClient;
use App\Support\Ml\PerformanceForecaster;
use App\Support\Ml\PredictionWording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Performance Forecast capability (ADR 0058): each employee's next appraisal as
 * the latest forecast projected it — the likely rating, the range four in five
 * land in, the band and the chance it is right — and, once that period is
 * appraised, how the forecast did. "Who is forecast below target?", "what is
 * Ana's outlook?", "how accurate was the last forecast?".
 *
 * Runs through {@see PerformanceForecaster}, the screen's own path; the check
 * against actual results is {@see ForecastTrackRecord}, the page's own, so a
 * verdict is withheld below the same number of checked forecasts. Everything
 * shared with the other predictive surfaces is {@see PredictiveModule}.
 */
class PerformanceForecastModule extends PredictiveModule
{
    /** Below this many checked forecasts, the page withholds a verdict. */
    private const FAIR_VERDICT_AT = 20;

    public function __construct(
        ModelGraduation $graduation,
        MlClient $ml,
        private readonly PerformanceForecaster $forecaster,
        private readonly ForecastTrackRecord $trackRecord,
    ) {
        parent::__construct($graduation, $ml);
    }

    public function key(): string
    {
        return 'performance-forecast';
    }

    protected function surface(): string
    {
        return 'performance';
    }

    protected function noun(): string
    {
        return 'forecast';
    }

    protected function subject(): string
    {
        return 'performance forecast';
    }

    protected function declines(): bool
    {
        return true;
    }

    protected function names(): array
    {
        return [
            'summary' => 'performance_forecast_summary',
            'find' => 'find_performance_forecasts',
            'get' => 'get_performance_forecast',
            'status' => 'get_forecast_model_status',
            'run' => 'run_performance_forecast',
            'delete' => 'delete_performance_forecast',
            'train' => 'train_forecast_model',
            'switch' => 'switch_forecast_model',
        ];
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        PERFORMANCE FORECAST — each employee's next appraisal as the latest forecast projected it from their last completed one: a rating on 0–100, the range four in five next ratings land in, a band (Exceeds, On track, Below target) and the chance that band is right. People with nothing to forecast from are left out, with the reason. Once the period is appraised, the forecast is checked against what happened.
        - performance_forecast_summary for the picture and how the forecast did; find_performance_forecasts for who (by band, department, name, or declined: true); get_performance_forecast for one person. Deleting a forecast and switching the model wait for the user's confirmation.
        - A forecast is a planning aid for reviews and coaching, never a rating: do not present it as someone's result.
        TXT;
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['performance forecast', 'forecast', 'forecasts', 'performance outlook', 'outlook', 'below target', 'next appraisal', 'next review'];
    }

    protected function runs(): Builder
    {
        return PerformanceForecastRun::query();
    }

    protected function scoresOf(Model $run): HasMany
    {
        /** @var PerformanceForecastRun $run */
        return $run->forecasts();
    }

    protected function tierColumn(): string
    {
        return 'band';
    }

    protected function tiers(): array
    {
        return PredictionWording::BANDS;
    }

    protected function counts(Model $run): array
    {
        return ['Exceeds' => (int) $run->exceeds_count, 'On track' => (int) $run->on_track_count, 'Below target' => (int) $run->below_count];
    }

    protected function headline(Model $run): array
    {
        /** @var PerformanceForecastRun $run */
        $period = $run->targetPeriod()->first(['id', 'name']);

        return array_values(array_filter([
            $period !== null ? 'Forecast for '.(UntrustedText::clean($period->name, 80) ?? 'the next review cycle') : 'There was no open review cycle to forecast',
            $run->average_rating !== null ? 'Average forecast '.PredictionWording::number((float) $run->average_rating).'%' : null,
            $run->average_confidence !== null ? 'Average chance the band is right '.PredictionWording::percent((float) $run->average_confidence) : null,
            $this->howItDid($run),
        ]));
    }

    protected function rowMeta(Model $score, Model $run): array
    {
        /** @var PerformanceForecast $score */
        $last = $score->features['rating_latest'] ?? null;

        return [
            PredictionWording::forecast($score),
            $last !== null ? 'Last appraisal '.PredictionWording::number((float) $last).'%' : null,
        ];
    }

    protected function detail(Model $score, Model $run, ?Model $previous): array
    {
        /** @var PerformanceForecast $score */
        /** @var PerformanceForecastRun $run */
        $actual = $run->target_period_id === null ? null : PerformanceEvaluation::query()
            ->completed()
            ->where('evaluation_period_id', $run->target_period_id)
            ->where('employee_id', $score->employee_id)
            ->orderBy('id')
            ->value('overall_percent');

        $inRange = $actual !== null && $score->predicted_low !== null && $score->predicted_high !== null
            && (float) $actual >= (float) $score->predicted_low && (float) $actual <= (float) $score->predicted_high;

        return [
            PredictionWording::forecast($score).' — '.(PredictionWording::BAND_MEANING[$score->band] ?? ''),
            ($score->history ?? []) !== [] ? 'Appraisals it rests on: '.collect($score->history)->map(fn (array $a): string => (UntrustedText::clean($a['label'] ?? null, 60) ?? 'Appraisal').' '.PredictionWording::number((float) ($a['rating'] ?? 0)).'%')->implode(', ') : null,
            $actual !== null ? 'Actual result: '.PredictionWording::number((float) $actual).'%'.($inRange ? ', inside the forecast range' : ', outside the forecast range') : null,
            ...collect($score->warnings ?? [])->take(2)->map(fn (mixed $w): ?string => UntrustedText::clean(is_scalar($w) ? (string) $w : null, 200))->all(),
            $previous !== null ? 'Previous forecast: '.PredictionWording::forecast($previous) : null,
        ];
    }

    protected function execute(User $user): Model
    {
        return $this->forecaster->run($user, self::CHANNEL);
    }

    protected function forget(Model $run): void
    {
        /** @var PerformanceForecastRun $run */
        $this->forecaster->delete($run, self::CHANNEL);
    }

    /**
     * How the run did against the period's completed appraisals, as the page's
     * track-record card says it — with no verdict on too few.
     */
    private function howItDid(PerformanceForecastRun $run): ?string
    {
        $record = $this->trackRecord->for($run);

        if ($record === null) {
            return null;
        }

        if ($record['checked'] === 0) {
            return 'Not checked yet: the period has no completed appraisals';
        }

        if ($record['checked'] < self::FAIR_VERDICT_AT) {
            return "Checked against {$record['checked']} completed appraisals — too few to judge yet (a fair verdict needs about ".self::FAIR_VERDICT_AT.')';
        }

        return "Checked against {$record['checked']} completed appraisals: off by ".PredictionWording::number((float) $record['mean_error']).' points on average'
            .($record['within_range'] !== null ? '; '.PredictionWording::percent((float) $record['within_range']).' landed inside their range (it promised about 80%)' : '')
            .'; band right '.PredictionWording::percent((float) $record['band_right']).' of the time (it expected '.PredictionWording::percent((float) $record['expected_band_right']).')';
    }
}
