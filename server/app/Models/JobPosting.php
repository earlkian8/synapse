<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    protected $fillable = [
        'organization_id',
        'recruitment_pipeline_id',
        'title',
        'department_id',
        'position_id',
        'description',
        'requirements',
        'min_years_experience',
        'skills',
        'requires_resume',
        'use_fit_scoring',
        'employment_type',
        'openings',
        'status',
        'closing_date',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'openings' => 'integer',
            'min_years_experience' => 'integer',
            'skills' => 'array',
            'requires_resume' => 'boolean',
            'use_fit_scoring' => 'boolean',
            'closing_date' => 'date',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The hiring process this posting's candidates move through.
     *
     * @return BelongsTo<RecruitmentPipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPipeline::class, 'recruitment_pipeline_id');
    }

    /**
     * The posting's own yes/no screening questions, in display order.
     *
     * @return HasMany<JobPostingScreeningQuestion, $this>
     */
    public function screeningQuestions(): HasMany
    {
        return $this->hasMany(JobPostingScreeningQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The user who posted the vacancy.
     *
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Replace the posting's screening questions wholesale — the form always sends
     * the full ordered list, same principle as
     * {@see RecruitmentPipeline::syncStages()}.
     *
     * @param  array<int, array{label: string}>  $questions
     */
    public function syncScreeningQuestions(array $questions): void
    {
        $this->screeningQuestions()->delete();

        foreach (array_values($questions) as $index => $question) {
            $this->screeningQuestions()->create([
                'label' => $question['label'],
                'position' => $index,
            ]);
        }
    }

    /**
     * Whether an open posting has passed its closing date and should no longer
     * accept applications. Postings without a closing date never expire.
     */
    public function isExpired(): bool
    {
        return $this->status === 'open'
            && $this->closing_date !== null
            && $this->closing_date->endOfDay()->isPast();
    }

    /**
     * Whole days until the posting closes (negative once past due), or null when
     * it has no closing date.
     */
    public function daysToClose(): ?int
    {
        if ($this->closing_date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->closing_date->startOfDay(), false);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Open postings whose closing date has passed — the ones the auto-close job
     * (and the careers guard) treat as no longer accepting applications.
     *
     * @param  Builder<JobPosting>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('status', 'open')
            ->whereNotNull('closing_date')
            ->whereDate('closing_date', '<', now()->toDateString());
    }

    /**
     * Free-text search across the searchable columns.
     *
     * @param  Builder<JobPosting>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $query) use ($needle, $like) {
            foreach (['title', 'description', 'requirements'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
