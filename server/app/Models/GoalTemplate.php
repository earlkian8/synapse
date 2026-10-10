<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An entry in the goal library (ADR 0073, Company Setup → Performance
 * Framework): wording and a target a goal can start from. Setting a goal from
 * it **copies** them, so retuning the library never moves a goal already set.
 */
class GoalTemplate extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'measure',
        'start_value',
        'target_value',
        'unit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_value' => 'decimal:2',
            'target_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The goals set from it.
     *
     * @return HasMany<PerformanceGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class);
    }

    /**
     * @param  Builder<GoalTemplate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
