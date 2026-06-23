<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's result within a {@see PromotionReadinessRun}: the raw model
 * probability, its 0–100 readiness score, the Low/Medium/High tier, the top
 * contributing factors and a snapshot of the features sent to the model.
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
        'factors',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'float',
            'score' => 'decimal:2',
            'factors' => 'array',
            'features' => 'array',
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
