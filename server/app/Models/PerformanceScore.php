<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Performance\RatingScales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a {@see PerformanceEvaluation}: the rating given on a single
 * criterion, and everything needed to read that rating without the framework.
 *
 * A line is a **snapshot**. Its label, description, section (key, name and the
 * section's weight), its own weight within that section, and the rating scale it
 * was measured on are all frozen when the scorecard is seeded — so retuning a
 * framework, retiring a criterion or changing a scale never rewrites a past
 * appraisal, and the scorer can rebuild the whole result from the lines alone.
 */
class PerformanceScore extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'performance_evaluation_id',
        'kpi_criterion_id',
        'review_template_item_id',
        'label',
        'section_key',
        'section_name',
        'section_weight',
        'description',
        'weight',
        'scale_type',
        'scale_name',
        'scale_min',
        'scale_max',
        'scale_levels',
        'score',
        'remarks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'section_weight' => 'decimal:2',
            'scale_min' => 'decimal:2',
            'scale_max' => 'decimal:2',
            'scale_levels' => 'array',
            'score' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The frozen scale, as the plain array {@see RatingScales} reads.
     *
     * @return array{type: string, min: float, max: float, step: float, levels: list<array{value: float, label: string, description: string|null}>|null}
     */
    public function scale(): array
    {
        return RatingScales::normalize([
            'type' => $this->scale_type,
            'min' => $this->scale_min,
            'max' => $this->scale_max,
            'levels' => $this->scale_levels,
        ]);
    }

    /**
     * Whether a raw rating is a value this line's own scale can take.
     */
    public function acceptsScore(float $value): bool
    {
        return RatingScales::accepts($value, $this->scale());
    }

    /**
     * The rating in its own scale's language — "4", "82%", "Proficient".
     */
    public function formattedScore(): string
    {
        return RatingScales::format(
            $this->score === null ? null : (float) $this->score,
            $this->scale(),
        );
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
