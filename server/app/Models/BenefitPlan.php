<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company-Setup benefit plan (ERD §7): a benefit the organisation offers — HMO,
 * life insurance, retirement, wellness — with its provider, the employee /
 * employer share per period, and a billing frequency. Employees are tied to plans
 * through {@see BenefitEnrollment}.
 */
class BenefitPlan extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** The benefit categories a plan can fall under. */
    public const CATEGORIES = ['hmo', 'insurance', 'retirement', 'wellness', 'other'];

    /** How often the plan's cost recurs. */
    public const FREQUENCIES = ['monthly', 'quarterly', 'annual', 'one_time'];

    /** Divisor to normalise a plan's cost to a monthly equivalent for rollups. */
    private const MONTHLY_DIVISOR = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12, 'one_time' => 0];

    protected $fillable = [
        'organization_id',
        'name',
        'category',
        'provider',
        'description',
        'employee_cost',
        'employer_cost',
        'frequency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'employee_cost' => 'decimal:2',
            'employer_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BenefitEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(BenefitEnrollment::class);
    }

    /**
     * Normalise a per-period amount to its monthly equivalent (one-time costs are
     * not recurring, so they contribute 0 to a monthly rollup).
     */
    public function toMonthly(float $amount): float
    {
        $divisor = self::MONTHLY_DIVISOR[$this->frequency] ?? 1;

        return $divisor === 0 ? 0.0 : round($amount / $divisor, 2);
    }

    /**
     * Active plans first, then by category and name — the catalogue ordering.
     *
     * @param  Builder<BenefitPlan>  $query
     */
    public function scopeCatalogueOrder(Builder $query): void
    {
        $query->orderByDesc('is_active')->orderBy('category')->orderBy('name');
    }
}
