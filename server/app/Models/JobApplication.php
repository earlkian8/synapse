<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * How long an open application may sit untouched before it counts as stalled.
     */
    public const STALL_DAYS = 14;

    protected $fillable = [
        'organization_id',
        'job_posting_id',
        'applicant_id',
        'recruitment_pipeline_stage_id',
        'rating',
        'expected_salary',
        'cover_note',
        'rejected_reason',
        'screening_answers',
        'hired_employee_id',
        'applied_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'expected_salary' => 'decimal:2',
            'applied_at' => 'datetime',
            'decided_at' => 'datetime',
            'ai_insights' => 'array',
            'screening_answers' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<Applicant, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /**
     * Where this application currently sits in its posting's pipeline.
     *
     * @return BelongsTo<RecruitmentPipelineStage, $this>
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPipelineStage::class, 'recruitment_pipeline_stage_id');
    }

    /**
     * The employee created when this application was hired, if any.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }

    /**
     * @return HasMany<Interview, $this>
     */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether this application has been converted into an employee.
     */
    public function isHired(): bool
    {
        return $this->pipelineStage?->kind === 'won' && $this->hired_employee_id !== null;
    }

    /**
     * Whether the application is still active in the pipeline.
     */
    public function isOpen(): bool
    {
        return $this->pipelineStage?->kind === 'open';
    }

    /**
     * Move the application to an open (non-terminal) stage of its own pipeline,
     * clearing any earlier decision (a rejected candidate brought back into the
     * pipeline is no longer rejected). The one place a forward/lateral stage move
     * happens, so the board, the public flow and the assistant can never drift
     * apart. Hiring and rejecting are dedicated actions with their own side
     * effects — see {@see rejectWith()} and `App\Support\ApplicantHirer`.
     */
    public function moveTo(RecruitmentPipelineStage $stage): void
    {
        if ($stage->kind !== 'open') {
            throw new InvalidArgumentException('moveTo() only accepts an open-kind stage — use rejectWith() or the hire action instead.');
        }

        if ($stage->recruitment_pipeline_id !== $this->jobPosting->recruitment_pipeline_id) {
            throw new InvalidArgumentException('That stage belongs to a different pipeline than this application\'s posting.');
        }

        $this->update([
            'recruitment_pipeline_stage_id' => $stage->id,
            'rejected_reason' => null,
            'decided_at' => null,
        ]);
    }

    /**
     * Turn the candidate down, recording the optional reason and stamping the
     * decision. Defaults to the pipeline's primary `lost` stage when the caller
     * doesn't name one (a pipeline with several — "Rejected," "Withdrawn" — lets
     * the caller pick).
     */
    public function rejectWith(?RecruitmentPipelineStage $lostStage = null, ?string $reason = null): void
    {
        $lostStage ??= $this->jobPosting->pipeline->defaultLostStage();

        $this->update([
            'recruitment_pipeline_stage_id' => $lostStage->id,
            'rejected_reason' => $reason,
            'decided_at' => now(),
        ]);
    }

    /**
     * How many whole days the application has been sitting in the pipeline.
     */
    public function daysInPipeline(): int
    {
        return $this->applied_at ? (int) $this->applied_at->diffInDays() : 0;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Applications still in the running (not hired, not rejected).
     *
     * @param  Builder<JobApplication>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereHas('pipelineStage', fn (Builder $query) => $query->where('kind', 'open'));
    }

    /**
     * Open applications that have gone quiet — untouched past the stall
     * threshold, i.e. the ones a recruiter should chase.
     *
     * @param  Builder<JobApplication>  $query
     */
    public function scopeStalled(Builder $query, ?int $days = null): void
    {
        $query->open()->where('applied_at', '<=', now()->subDays($days ?? self::STALL_DAYS));
    }
}
