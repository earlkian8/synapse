<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a {@see PerformanceEvaluation}: the rating (1–5) given on a single
 * {@see KpiCriterion}, with the weight it carries. The criterion's name and
 * weight are snapshot here (`label` / `weight`) so an archived criterion still
 * renders and the evaluation's weighting stays stable.
 */
class PerformanceScore extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'performance_evaluation_id',
        'kpi_criterion_id',
        'label',
        'weight',
        'score',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'score' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PerformanceEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(PerformanceEvaluation::class, 'performance_evaluation_id');
    }

    /**
     * @return BelongsTo<KpiCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(KpiCriterion::class, 'kpi_criterion_id');
    }
}
