<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Performance\ReviewWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's review of an appraisal (ADR 0072) — the employee's own
 * self-review, or a review from their manager, a peer or someone who reports to
 * them. The **relationship is derived** from the reporting line when the review
 * is asked for, never typed. A review is input to the appraisal: the evaluator
 * reads it, and it never sets the result ({@see ReviewWorkflow}).
 *
 * Status runs pending → submitted | declined | cancelled. Its ratings are rows
 * of {@see AppraisalReviewScore}, one per appraisal line answered.
 */
class AppraisalReview extends Model
{
    use BelongsToOrganization, HasHashid;

    /** Who a reviewer is to the person appraised. */
    public const RELATIONSHIPS = ['self', 'manager', 'peer', 'direct_report'];

    public const STATUSES = ['pending', 'submitted', 'declined', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'performance_evaluation_id',
        'reviewer_id',
        'relationship',
        'status',
        'requested_by',
        'due_on',
        'strengths',
        'improvements',
        'decline_reason',
        'submitted_at',
        'declined_at',
        'reminded_at',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'submitted_at' => 'datetime',
            'declined_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PerformanceEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(PerformanceEvaluation::class, 'performance_evaluation_id');
    }

    /**
     * The employee who writes it.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<AppraisalReviewScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(AppraisalReviewScore::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSelf(): bool
    {
        return $this->relationship === 'self';
    }

    /**
     * Whether the due date has passed without an answer.
     */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_on !== null && $this->due_on->lt(today());
    }

    /**
     * Limit to the reviews one employee is asked to write.
     *
     * @param  Builder<AppraisalReview>  $query
     */
    public function scopeByReviewer(Builder $query, Employee|int $reviewer): void
    {
        $query->where('reviewer_id', $reviewer instanceof Employee ? $reviewer->id : $reviewer);
    }

    /**
     * Limit to reviews still waiting for an answer.
     *
     * @param  Builder<AppraisalReview>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}
