<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review's rating on one criterion of the appraisal (ADR 0072). It points at
 * the appraisal's own line, so it is read on that line's frozen scale — a
 * reviewer rates "Proficient" on exactly the levels the evaluator does.
 */
class AppraisalReviewScore extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'appraisal_review_id',
        'performance_score_id',
        'score',
        'remarks',
    ];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<AppraisalReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(AppraisalReview::class, 'appraisal_review_id');
    }

    /**
     * The appraisal line this answers.
     *
     * @return BelongsTo<PerformanceScore, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PerformanceScore::class, 'performance_score_id');
    }
}
