<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup award type (ERD §2): a kind of recognition the organisation
 * gives — Employee of the Month, Perfect Attendance, Spot Award… Employees receive
 * it through {@see EmployeeAward}. Archivable so retiring one keeps historical
 * awards intact.
 */
class AwardType extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'color',
        'points',
        'accepts_nominations',
        'is_active',
    ];

    /** Mirrors the column defaults (ADR 0071). */
    protected $attributes = [
        'points' => 0,
        'accepts_nominations' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'points' => 'integer',
            'accepts_nominations' => 'boolean',
        ];
    }

    /**
     * The awards given of this type.
     *
     * @return HasMany<EmployeeAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(EmployeeAward::class);
    }

    /**
     * Types colleagues can nominate for: still given out, and open to
     * nominations (ADR 0071).
     *
     * @param  Builder<AwardType>  $query
     */
    public function scopeNominatable(Builder $query): void
    {
        $query->where('is_active', true)->where('accepts_nominations', true);
    }

    /**
     * Limit to types still offered for new awards.
     *
     * @param  Builder<AwardType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active first, then by name — the catalogue ordering.
     *
     * @param  Builder<AwardType>  $query
     */
    public function scopeCatalogueOrder(Builder $query): void
    {
        $query->orderByDesc('is_active')->orderBy('name');
    }
}
