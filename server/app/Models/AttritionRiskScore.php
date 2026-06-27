<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's result within an {@see AttritionRiskRun}: the raw model
 * probability of leaving, its 0–100 risk score, the Low/Medium/High tier, a
 * confidence (share of the model's key inputs grounded in real HR data) and a
 * snapshot of the features sent to the model.
 *
 * The Random-Forest attrition model exposes no per-instance factor
 * contributions, so — like {@see PerformanceForecast} — the confidence and the
 * feature snapshot stand in for the "why".
 */
class AttritionRiskScore extends Model
{
    use BelongsToOrganization;

    /** Risk tiers, ordered low → high. */
    public const TIERS = ['low', 'medium', 'high'];

    protected $fillable = [
        'organization_id',
        'attrition_risk_run_id',
        'employee_id',
        'probability',
        'score',
        'tier',
        'confidence',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'float',
            'score' => 'decimal:2',
            'confidence' => 'decimal:3',
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
