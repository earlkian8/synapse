<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\RatingScales;
use Database\Factories\RatingScaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable measurement instrument (ADR 0028): a numeric range, a 0–100
 * percentage, or an ordered set of named levels with behavioural anchors. A
 * criterion or a framework item points at one instead of carrying its own
 * bounds, so "how we rate things" is defined once per tenant and can be changed
 * in one place.
 *
 * The reading of a scale — what a raw score means on it, how it is formatted —
 * lives in {@see RatingScales}, because a scale also has to be read from the
 * snapshot frozen onto a score line, where there is no model.
 */
class RatingScale extends Model
{
    /** @use HasFactory<RatingScaleFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    /** The kinds of instrument a scale can be. */
    public const TYPES = RatingScales::TYPES;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'type',
        'min',
        'max',
        'step',
        'levels',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'min' => 'decimal:2',
            'max' => 'decimal:2',
            'step' => 'decimal:2',
            'levels' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The scale as the plain array {@see RatingScales} and the score-line
     * snapshot both speak.
     *
     * @return array{type: string, min: float, max: float, step: float, levels: list<array{value: float, label: string, description: string|null}>|null}
     */
    public function definition(): array
    {
        return RatingScales::normalize([
            'type' => $this->type,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'levels' => $this->levels,
        ]);
    }

    /**
     * The columns frozen onto a {@see PerformanceScore} when a scorecard is
     * seeded, so the measurement an appraisal used never changes underneath it.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $definition = $this->definition();

        return [
            'scale_type' => $definition['type'],
            'scale_name' => $this->name,
            'scale_min' => $definition['min'],
            'scale_max' => $definition['max'],
            'scale_levels' => $definition['levels'],
        ];
    }

    /**
     * The criteria measured with this scale.
     *
     * @return HasMany<KpiCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(KpiCriterion::class);
    }

    /**
     * The framework items measured with this scale.
     *
     * @return HasMany<ReviewTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReviewTemplateItem::class);
    }

    /**
     * The tenant's preferred scale first, then by name — the catalogue ordering.
     *
     * @param  Builder<RatingScale>  $query
     */
    public function scopeCatalogueOrder(Builder $query): void
    {
        $query->orderByDesc('is_default')->orderBy('name');
    }
}
