<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\PerformanceScorer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's appraisal for an {@see EvaluationPeriod} (ERD §8): who evaluated
 * them, the derived weighted overall score, a status lifecycle (draft →
 * submitted → acknowledged), and remarks. The per-criterion breakdown lives in
 * {@see PerformanceScore}; the overall score is derived from it
 * (see {@see PerformanceScorer}). One row per employee
 * per period.
 */
class PerformanceEvaluation extends Model
{
    use BelongsToOrganization, HasHashid;

    /** The lifecycle of an evaluation. */
    public const STATUSES = ['draft', 'submitted', 'acknowledged'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'evaluation_period_id',
        'evaluator_id',
        'overall_score',
        'status',
        'submitted_at',
        'acknowledged_at',
        'remarks',
        'ai_insights',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'ai_insights' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<EvaluationPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(EvaluationPeriod::class, 'evaluation_period_id');
    }

    /**
     * The user who conducted the appraisal.
     *
     * @return BelongsTo<User, $this>
     */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    /**
     * @return HasMany<PerformanceScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(PerformanceScore::class);
    }

    /**
     * Whether the evaluation's scores may still be edited (only while a draft).
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Newest evaluations first.
     *
     * @param  Builder<PerformanceEvaluation>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->latest('id');
    }
}
