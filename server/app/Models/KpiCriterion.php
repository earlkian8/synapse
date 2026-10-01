<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\KpiCriterionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup KPI criterion (ERD §2): a dimension performance is measured
 * on — Quality of Work, Productivity, Teamwork… It is the tenant's **catalogue**
 * entry: it names the thing and the {@see RatingScale} it is measured on, and
 * carries a default weight that a framework can override.
 *
 * A criterion does not decide an appraisal on its own — a {@see ReviewTemplate}
 * puts it in a section, at a weight, for a population. Archivable so retiring one
 * keeps historical evaluations intact.
 */
class KpiCriterion extends Model
{
    /** @use HasFactory<KpiCriterionFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'weight',
        'rating_scale_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The scale this criterion is measured on.
     *
     * @return BelongsTo<RatingScale, $this>
     */
    public function ratingScale(): BelongsTo
    {
        return $this->belongsTo(RatingScale::class);
    }

    /**
     * The framework items drawing from this criterion.
     *
     * @return HasMany<ReviewTemplateItem, $this>
     */
    public function templateItems(): HasMany
    {
        return $this->hasMany(ReviewTemplateItem::class);
    }

    /**
     * The evaluation score lines typed by this criterion.
     *
     * @return HasMany<PerformanceScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(PerformanceScore::class);
    }

    /**
     * Limit to criteria still in use for new evaluations.
     *
     * @param  Builder<KpiCriterion>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active first, then the tenant's own ordering, then by name.
     *
     * @param  Builder<KpiCriterion>  $query
     */
    public function scopeCatalogueOrder(Builder $query): void
    {
        $query->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name');
    }
}
