<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's result within a {@see PerformanceForecastRun}: the model's
 * predicted next-period rating (0–100), the confidence in it, the Below / On
 * track / Exceeds band, a snapshot of the features sent to the model and the
 * employee's recent actual ratings (for the trajectory chart).
 */
class PerformanceForecast extends Model
{
    use BelongsToOrganization;

    /** Forecast bands, ordered low → high. */
    public const BANDS = ['below', 'on_track', 'exceeds'];

    protected $fillable = [
        'organization_id',
        'performance_forecast_run_id',
        'employee_id',
        'predicted_rating',
        'confidence',
        'band',
        'features',
        'history',
    ];

    protected function casts(): array
    {
        return [
            'predicted_rating' => 'decimal:2',
            'confidence' => 'decimal:3',
            'features' => 'array',
            'history' => 'array',
        ];
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
