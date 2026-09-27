<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch promotion-readiness assessment: every active employee scored through
 * the Logistic-Regression promotion model at a point in time, with a summary
 * (tier counts + average readiness). Addressed by hashid. The per-employee
 * breakdown lives in {@see PromotionReadinessScore}.
 */
class PromotionReadinessRun extends Model
{
    use BelongsToOrganization, HasHashid;

    public const STATUSES = ['completed', 'failed'];

    protected $fillable = [
        'organization_id',
        'generated_by',
        'status',
        'model_version',
        'local_model_id',
        'employees_scored',
        'high_count',
        'medium_count',
        'low_count',
        'average_score',
        'unassessed',
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
            'unassessed' => 'array',
        ];
    }

    /**
     * @return HasMany<PromotionReadinessScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(PromotionReadinessScore::class);
    }

    /**
     * The organisation's own model that scored this run, or null for the general
     * model (ADR 0046).
     *
     * @return BelongsTo<LocalModel, $this>
     */
    public function localModel(): BelongsTo
    {
        return $this->belongsTo(LocalModel::class);
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
     * @param  Builder<PromotionReadinessRun>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->latest('id');
    }
}
