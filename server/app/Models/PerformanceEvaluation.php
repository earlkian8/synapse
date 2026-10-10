<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\RatingModel;
use App\Support\Performance\ScoreResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's appraisal for an {@see EvaluationPeriod} (ERD §8), conducted
 * against a {@see ReviewTemplate}. It carries the framework snapshot the result
 * was produced under — the framework's name, its sections and its rating model —
 * so retuning the framework later never rewrites this appraisal.
 *
 * The result is stored three ways, all derived by {@see PerformanceScorer} and
 * never trusted from the client: `overall_percent` is attainment on 0–100 (the
 * canonical figure), `result_band` / `result_label` are what the tenant's rating
 * model calls it, and `overall_score` is the 1–5 projection everything outside
 * Performance reads. Status runs draft → submitted → acknowledged.
 *
 * A submitted appraisal is **shared** with the employee (`shared_at`) at once,
 * unless an open {@see CalibrationSession} holds it back (ADR 0073); only then can
 * it be acknowledged — by the employee themselves, or by HR on their behalf
 * (`acknowledged_by` says which). When calibration moves the rating,
 * `result_band` / `result_label` carry the calibrated band and `scored_band` /
 * `scored_label` keep what the scorecard gave.
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
        'review_template_id',
        'template_name',
        'template_sections',
        'template_bands',
        'result_display',
        'evaluator_id',
        'overall_score',
        'overall_percent',
        'result_band',
        'result_label',
        'scored_band',
        'scored_label',
        'calibrated_at',
        'status',
        'submitted_at',
        'shared_at',
        'acknowledged_at',
        'acknowledged_by',
        'employee_comment',
        'remarks',
        'ai_insights',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'decimal:2',
            'overall_percent' => 'decimal:2',
            'template_sections' => 'array',
            'template_bands' => 'array',
            'submitted_at' => 'datetime',
            'shared_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'calibrated_at' => 'datetime',
            'ai_insights' => 'array',
        ];
    }

    /**
     * The rating model this appraisal is reported in — the snapshot taken when it
     * was opened, falling back to the standard model for evaluations that predate
     * frameworks.
     *
     * @return list<array{key: string, label: string, min_percent: float, description: string|null, tone: string}>
     */
    public function bandList(): array
    {
        $bands = RatingModel::normalize($this->template_bands ?? []);

        return $bands === [] ? RatingModel::defaultBands() : $bands;
    }

    /**
     * Write a freshly derived result onto the appraisal. The one place the four
     * result columns are set, so they can never drift apart.
     */
    public function applyResult(ScoreResult $result): void
    {
        $this->overall_percent = $result->percent;
        $this->overall_score = $result->normalized;
        $this->result_band = $result->band['key'] ?? null;
        $this->result_label = $result->band['label'] ?? null;
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
     * The framework this appraisal was opened from (null once it is deleted — the
     * snapshot on the row is what actually decides the result).
     *
     * @return BelongsTo<ReviewTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReviewTemplate::class, 'review_template_id');
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
     * Who recorded the acknowledgement — the employee's own account, or HR's
     * when it was recorded on their behalf.
     *
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * The reviews asked of the people around this appraisal (ADR 0072).
     *
     * @return HasMany<AppraisalReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(AppraisalReview::class);
    }

    /**
     * Every band move calibration made on this appraisal, oldest first.
     *
     * @return HasMany<CalibrationAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(CalibrationAdjustment::class)->orderBy('id');
    }

    /**
     * @return HasMany<PerformanceScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(PerformanceScore::class);
    }

    /**
     * The scorecard in reading order: section by section, then item by item.
     *
     * @return HasMany<PerformanceScore, $this>
     */
    public function scorecard(): HasMany
    {
        return $this->scores()->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether the evaluation's scores may still be edited (only while a draft).
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Whether the employee may see the result (and so acknowledge it).
     */
    public function isShared(): bool
    {
        return $this->shared_at !== null && $this->status !== 'draft';
    }

    /**
     * Whether the employee acknowledged it from their own account, rather than
     * HR recording it for them.
     */
    public function acknowledgedByEmployee(): bool
    {
        return $this->acknowledged_by !== null
            && $this->acknowledged_by === $this->employee?->user_id;
    }

    /**
     * Whether calibration moved the rating away from what the scorecard gave.
     */
    public function isCalibrated(): bool
    {
        return $this->scored_band !== null;
    }

    /**
     * The band the scorecard itself gave — the calibrated one's origin, or the
     * rating when nothing moved it.
     */
    public function scoredBandKey(): ?string
    {
        return $this->scored_band ?? $this->result_band;
    }

    /**
     * Whether `$user` is the person appraised — the one person who never
     * conducts this appraisal.
     */
    public function isAbout(?User $user): bool
    {
        return $user !== null
            && $this->employee_id !== null
            && Employee::query()->whereKey($this->employee_id)->where('user_id', $user->id)->exists();
    }

    /**
     * Limit to the appraisals of one review cycle.
     *
     * @param  Builder<PerformanceEvaluation>  $query
     */
    public function scopeForPeriod(Builder $query, ?int $periodId): void
    {
        $query->when($periodId !== null, fn (Builder $q) => $q->where('evaluation_period_id', $periodId));
    }

    /**
     * Limit to the appraisals of one person.
     *
     * @param  Builder<PerformanceEvaluation>  $query
     */
    public function scopeForEmployee(Builder $query, Employee|int $employee): void
    {
        $query->where('employee_id', $employee instanceof Employee ? $employee->id : $employee);
    }

    /**
     * Limit to appraisals whose employee works in one of these departments
     * (null: no limit).
     *
     * @param  Builder<PerformanceEvaluation>  $query
     * @param  list<int>|null  $departmentIds
     */
    public function scopeInDepartments(Builder $query, ?array $departmentIds): void
    {
        $query->when($departmentIds !== null, fn (Builder $q) => $q->whereHas(
            'employee',
            fn (Builder $e) => $e->whereIn('department_id', $departmentIds),
        ));
    }

    /**
     * Limit to appraisals with a final result on record.
     *
     * @param  Builder<PerformanceEvaluation>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->whereIn('status', ['submitted', 'acknowledged'])->whereNotNull('overall_percent');
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

    /**
     * Search by the employee or the cycle, so "maria" and "maria q3" both
     * narrow it. Free-text fields are left out on purpose: they can hold
     * personal detail that a match would reveal (ADR 0069).
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(fn (Builder $query) => $query
            ->whereHas('employee', fn (Builder $q) => $q->search($term))
            ->orWhereHas('period', fn (Builder $q) => $q->where('name', $like, $needle)));
    }
}
