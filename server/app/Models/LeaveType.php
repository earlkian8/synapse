<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of leave an organisation grants (Vacation, Sick, …), configured under
 * Company Setup. Carries the default annual entitlement and the policy flags
 * (paid, half-day allowed, needs approval). Archived rather than hard-deleted so
 * filed requests keep a valid type. See ADR 0009.
 */
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use BelongsToOrganization, HasFactory, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'color',
        'default_days',
        'is_paid',
        'allow_half_day',
        'requires_approval',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_days' => 'decimal:1',
            'is_paid' => 'boolean',
            'allow_half_day' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasMany<LeaveBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Free-text search across name and code.
     *
     * @param  Builder<LeaveType>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $query) use ($needle, $like) {
            $query->where('name', $like, $needle)
                ->orWhere('code', $like, $needle);
        });
    }
}
