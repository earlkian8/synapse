<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weighted line of a {@see ReviewTemplate}: what is measured, which section
 * it belongs to, how much of that section it carries, and the
 * {@see RatingScale} it is measured on. It may draw from the
 * {@see KpiCriterion} catalogue or be written straight into the framework.
 *
 * `weight` is the item's share **of its section**, not of the whole appraisal —
 * the section's own weight decides how much the section counts for.
 */
class ReviewTemplateItem extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'review_template_id',
        'kpi_criterion_id',
        'rating_scale_id',
        'section_key',
        'name',
        'description',
        'weight',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReviewTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReviewTemplate::class, 'review_template_id');
    }

    /**
     * @return BelongsTo<KpiCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(KpiCriterion::class, 'kpi_criterion_id');
    }

    /**
     * @return BelongsTo<RatingScale, $this>
     */
    public function ratingScale(): BelongsTo
    {
        return $this->belongsTo(RatingScale::class);
    }
}
