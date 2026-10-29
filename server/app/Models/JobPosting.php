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

    /**
     * What a pay figure is quoted per — a posting's range, and the expected
     * salary an applicant gives for it (ADR 0091). Monthly is how most
     * Philippine roles are quoted; daily for daily-rated work, hourly for
     * part-time, annual for senior packages.
     */
    public const SALARY_PERIODS = ['monthly', 'annual', 'daily', 'hourly'];

    /** Where the work is done. */
    public const WORK_ARRANGEMENTS = ['onsite', 'hybrid', 'remote'];

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
        'preferred_skills',
        'requires_resume',
        'use_fit_scoring',
        'employment_type',
        'openings',
        'salary_min',
        'salary_max',
        'salary_period',
        'show_salary',
        'asks_expected_salary',
        'work_arrangement',
        'work_location_id',
        'status',
        'closing_date',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'openings' => 'integer',
            // Whole or half years: "1.5" (ADR 0091).
            'min_years_experience' => 'float',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'show_salary' => 'boolean',
            'asks_expected_salary' => 'boolean',
            // Must-haves and nice-to-haves (ADR 0097).
            'skills' => 'array',
            'preferred_skills' => 'array',
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
     * The site the role is based at, when it has one.
     *
     * @return BelongsTo<WorkLocation, $this>
     */
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /**
     * The pay range as a reader would say it — "₱25,000–₱35,000 a month",
     * "From ₱600 a day" — or null when none was given.
     */
    public function payLabel(): ?string
    {
        return self::money($this->salary_min, $this->salary_max, $this->salary_period);
    }

    /**
     * A pay figure or range in pesos, with the period it is quoted per.
     */
    public static function money(int|float|string|null $min, int|float|string|null $max = null, ?string $period = null): ?string
    {
        $format = fn ($value): string => '₱'.number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2);
        $per = match ($period) {
            'annual' => ' a year',
            'daily' => ' a day',
            'hourly' => ' an hour',
            'monthly' => ' a month',
            default => '',
        };

        return match (true) {
            $min !== null && $max !== null && (float) $min !== (float) $max => $format($min).'–'.$format($max).$per,
            $min !== null => ($max === null ? 'From ' : '').$format($min).$per,
            $max !== null => 'Up to '.$format($max).$per,
            default => null,
        };
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
