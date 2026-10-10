<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something points are spent on (ADR 0071): a cost, and stock when there is only
 * so much of it (null: as many as are asked for). Retired or archived, it is no
 * longer offered; the requests made for it stay.
 */
class Reward extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'cost',
        'stock',
        'is_active',
    ];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'cost' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<RewardRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    /**
     * Rewards that can be asked for: offered, and not out of stock.
     *
     * @param  Builder<Reward>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->where(fn (Builder $query) => $query->whereNull('stock')->orWhere('stock', '>', 0));
    }
}
