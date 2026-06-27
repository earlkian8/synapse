<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch attrition-risk assessment: every active employee scored through the
 * Random-Forest attrition model at a point in time, with a summary (tier counts +
 * average risk + average confidence). Addressed by hashid. The per-employee
 * breakdown lives in {@see AttritionRiskScore}.
 */
class AttritionRiskRun extends Model
{
    use BelongsToOrganization, HasHashid;

    public const STATUSES = ['completed', 'failed'];

    protected $fillable = [
        'organization_id',
        'generated_by',
        'status',
        'model_version',
        'employees_scored',
        'high_count',
        'medium_count',
        'low_count',
        'average_score',
        'average_confidence',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'employees_scored' => 'integer',
            'high_count' => 'integer',
            'medium_count' => 'integer',
            'low_count' => 'integer',
            'average_score' => 'decimal:2',
            'average_confidence' => 'decimal:3',
        ];
    }

    /**
     * @return HasMany<AttritionRiskScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(AttritionRiskScore::class);
    }

    /**
     * The user who triggered the assessment.
     *
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Newest runs first.
     *
     * @param  Builder<AttritionRiskRun>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->latest('id');
    }
}
