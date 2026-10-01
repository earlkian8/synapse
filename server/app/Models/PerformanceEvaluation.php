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
            'overall_percent' => 'decimal:2',
            'template_sections' => 'array',
            'template_bands' => 'array',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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
     * Limit to the appraisals of one review cycle.
     *
     * @param  Builder<PerformanceEvaluation>  $query
     */
    public function scopeForPeriod(Builder $query, ?int $periodId): void
    {
        $query->when($periodId !== null, fn (Builder $q) => $q->where('evaluation_period_id', $periodId));
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
}
