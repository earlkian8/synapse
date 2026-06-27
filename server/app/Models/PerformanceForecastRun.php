<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch performance-forecast run: every active employee's next-period rating
 * projected through the Gradient-Boosting performance model at a point in time,
 * with a summary (band counts + average rating & confidence) and the evaluation
 * period it targets. Addressed by hashid. The per-employee breakdown lives in
 * {@see PerformanceForecast}.
 */
class PerformanceForecastRun extends Model
{
    use BelongsToOrganization, HasHashid;

    public const STATUSES = ['completed', 'failed'];

    protected $fillable = [
        'organization_id',
        'generated_by',
        'target_period_id',
        'status',
        'model_version',
        'employees_scored',
        'exceeds_count',
        'on_track_count',
        'below_count',
        'average_rating',
        'average_confidence',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'employees_scored' => 'integer',
            'exceeds_count' => 'integer',
            'on_track_count' => 'integer',
            'below_count' => 'integer',
            'average_rating' => 'decimal:2',
            'average_confidence' => 'decimal:3',
        ];
    }

    /**
     * @return HasMany<PerformanceForecast, $this>
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(PerformanceForecast::class);
    }

    /**
     * The user who triggered the forecast.
     *
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * The evaluation period being forecast (nullable).
     *
     * @return BelongsTo<EvaluationPeriod, $this>
     */
    public function targetPeriod(): BelongsTo
    {
        return $this->belongsTo(EvaluationPeriod::class, 'target_period_id');
    }

    /**
     * Newest runs first.
     *
     * @param  Builder<PerformanceForecastRun>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->latest('id');
    }
}
