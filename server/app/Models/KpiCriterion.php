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

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'weight',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
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
