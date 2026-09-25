<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's result within a {@see PerformanceForecastRun}: the model's
 * predicted next-period rating (0–100), the range four in five next ratings land
 * in, the Below / On track / Exceeds band and the confidence — the chance the next
 * rating lands in that band — plus a snapshot of the features sent to the model
 * and the completed appraisals it drew on (for the trajectory chart).
 */
class PerformanceForecast extends Model
{
    use BelongsToOrganization;

    /** Forecast bands, ordered low → high. */
    public const BANDS = ['below', 'on_track', 'exceeds'];

    /**
     * Where each band above the lowest begins, on the 0–100 scale. These mirror the
     * performance model's contract (`bands` in model/artifacts/performance/
     * feature_contract.json): the model names a forecast's band; this reads an
     * actual rating the same way, to check a forecast after the fact.
     */
    public const BAND_FLOORS = ['on_track' => 60.0, 'exceeds' => 80.0];

    protected $fillable = [
        'organization_id',
        'performance_forecast_run_id',
        'employee_id',
        'predicted_rating',
        'predicted_low',
        'predicted_high',
        'confidence',
        'band',
        'features',
        'history',
        'warnings',
    ];

    protected function casts(): array
    {
        return [
            'predicted_rating' => 'decimal:2',
            'predicted_low' => 'decimal:2',
            'predicted_high' => 'decimal:2',
            'confidence' => 'decimal:3',
            'features' => 'array',
            'history' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * The band a rating (0–100) falls in.
     */
    public static function bandFor(float $rating): string
    {
        return match (true) {
            $rating >= self::BAND_FLOORS['exceeds'] => 'exceeds',
            $rating >= self::BAND_FLOORS['on_track'] => 'on_track',
            default => 'below',
        };
    }

    /**
     * @return BelongsTo<PerformanceForecastRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PerformanceForecastRun::class, 'performance_forecast_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Highest predicted rating first.
     *
     * @param  Builder<PerformanceForecast>  $query
     */
    public function scopeRanked(Builder $query): void
    {
        $query->orderByDesc('predicted_rating');
    }
}
