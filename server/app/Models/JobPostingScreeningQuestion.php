<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\JobPostingScreeningQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One yes/no question a {@see JobPosting} asks every applicant — the generic
 * complement to the posting's `min_years_experience`/`skills` criteria, for
 * anything those don't cover (a licence, a certification, shift availability).
 * Answers land in `JobApplication::$screening_answers`, keyed by this row's id.
 */
class JobPostingScreeningQuestion extends Model
{
    /** @use HasFactory<JobPostingScreeningQuestionFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'job_posting_id',
        'label',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
