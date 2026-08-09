<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * The ordered pipeline stages an application moves through.
     *
     * @var list<string>
     */
    public const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'];

    /**
     * The non-terminal stages: a candidate is still in the running, and these are
     * the only stages an application may be *moved* to (hiring and rejecting have
     * dedicated actions with their own side effects).
     *
     * @var list<string>
     */
    public const OPEN_STAGES = ['applied', 'screening', 'interview', 'offer'];

    /**
     * The stages that end an application's journey.
     *
     * @var list<string>
     */
    public const TERMINAL_STAGES = ['hired', 'rejected'];

    /**
     * How long an open application may sit untouched before it counts as stalled.
     */
    public const STALL_DAYS = 14;

    protected $fillable = [
        'organization_id',
        'job_posting_id',
        'applicant_id',
        'stage',
        'rating',
        'expected_salary',
        'cover_note',
        'rejected_reason',
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
        return $this->stage === 'hired' && $this->hired_employee_id !== null;
    }

    /**
     * Whether the application is still active in the pipeline.
     */
    public function isOpen(): bool
    {
        return ! in_array($this->stage, self::TERMINAL_STAGES, true);
    }

    /**
     * Move the application to a non-terminal stage, clearing any earlier decision
     * (a rejected candidate brought back into the pipeline is no longer rejected).
     * The one place a stage move happens, so the board, the public flow and the
     * assistant can never drift apart.
     */
    public function moveTo(string $stage): void
    {
        $this->update([
            'stage' => $stage,
            'rejected_reason' => null,
            'decided_at' => null,
        ]);
    }

    /**
     * Turn the candidate down, recording the optional reason and stamping the
     * decision.
     */
    public function rejectWith(?string $reason = null): void
    {
        $this->update([
            'stage' => 'rejected',
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
        $query->whereNotIn('stage', self::TERMINAL_STAGES);
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
