<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's result within a {@see PromotionReadinessRun}: the calibrated
 * probability (the share of reference employees with this record who were promoted
 * within the year), the 0–100 readiness score (where that probability sits among
 * the reference workforce), the Low/Medium/High tier, the history it rests on
 * (`basis`: one appraisal or two), the factors in readiness points, and snapshots of
 * the features sent and the appraisals they came from.
 */
class PromotionReadinessScore extends Model
{
    use BelongsToOrganization;

    /** Readiness tiers, ordered low → high. */
    public const TIERS = ['low', 'medium', 'high'];

    protected $fillable = [
        'organization_id',
        'promotion_readiness_run_id',
        'employee_id',
        'probability',
        'score',
        'tier',
        'basis',
        'factors',
        'features',
        'history',
        'warnings',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'float',
            'score' => 'decimal:2',
            'factors' => 'array',
            'features' => 'array',
            'history' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PromotionReadinessRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PromotionReadinessRun::class, 'promotion_readiness_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Highest readiness first.
     *
     * @param  Builder<PromotionReadinessScore>  $query
     */
    public function scopeRanked(Builder $query): void
    {
        $query->orderByDesc('score');
    }
}
