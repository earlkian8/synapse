<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's result within an {@see AttritionRiskRun}: the model's probability
 * of leaving, its 0–100 risk score, the Stable / At watch / High risk tier, the
 * confidence (how much of the employee's own record fed the score), the factors
 * behind it when the model can attribute them, and a snapshot of the features sent.
 */
class AttritionRiskScore extends Model
{
    use BelongsToOrganization;

    /** Risk tiers, ordered low → high (Stable, At watch, High risk). */
    public const TIERS = ['low', 'medium', 'high'];

    protected $fillable = [
        'organization_id',
        'attrition_risk_run_id',
        'employee_id',
        'probability',
        'score',
        'tier',
        'confidence',
        'factors',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'float',
            'score' => 'decimal:2',
            'confidence' => 'decimal:3',
            'factors' => 'array',
            'features' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AttritionRiskRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AttritionRiskRun::class, 'attrition_risk_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Highest risk first.
     *
     * @param  Builder<AttritionRiskScore>  $query
     */
    public function scopeRanked(Builder $query): void
    {
        $query->orderByDesc('score');
    }
}
