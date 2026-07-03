<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup KPI criterion (ERD §2): a weighted dimension a performance
 * evaluation scores an employee against — Quality of Work, Productivity,
 * Teamwork… `weight` is the relative weight the overall score is averaged by.
 * Archivable so retiring a criterion keeps historical evaluations intact.
 */
class KpiCriterion extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** The rating-scale kinds a criterion can be measured on. */
    public const SCALE_TYPES = ['points', 'percentage', 'scale'];

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'weight',
        'scale_type',
        'scale_min',
        'scale_max',
        'scale_levels',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'scale_min' => 'decimal:2',
            'scale_max' => 'decimal:2',
            'scale_levels' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The scale fields to snapshot onto a {@see PerformanceScore} when seeding an
     * evaluation, so the criterion's measurement method stays stable per-appraisal.
     *
     * @return array<string, mixed>
     */
    public function scaleSnapshot(): array
    {
        return [
            'scale_type' => $this->scale_type,
            'scale_min' => $this->scale_min,
            'scale_max' => $this->scale_max,
            'scale_levels' => $this->scale_levels,
        ];
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
     * Active first, then by name — the catalogue ordering.
     *
     * @param  Builder<KpiCriterion>  $query
     */
    public function scopeCatalogueOrder(Builder $query): void
    {
        $query->orderByDesc('is_active')->orderBy('name');
    }
}
