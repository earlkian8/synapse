<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A training program / cohort the organisation runs (ERD §8): its provider, date
 * window and seat capacity. Employees are tied to it through
 * {@see TrainingEnrollment}. The lifecycle **status is derived** from the dates
 * (`upcoming → ongoing → completed`), never stored, so it cannot drift.
 *
 * It runs as dated {@see TrainingSession}s, says who may join it themselves
 * (`enrollment_mode`, ADR 0074), may earn a certificate that is valid for some
 * months, and carries a budget its {@see TrainingCost}s are read against (ADR 0075).
 */
class TrainingProgram extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** The derived lifecycle a program can be in. */
    public const STATUSES = ['upcoming', 'ongoing', 'completed'];

    /**
     * Who may put themselves on the roster: nobody (HR enrolls), anyone while
     * seats remain, or anyone who asks and is approved.
     */
    public const ENROLLMENT_MODES = ['closed', 'open', 'approval'];

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'provider',
        'start_date',
        'end_date',
        'capacity',
        'enrollment_mode',
        'issues_certificate',
        'certificate_validity_months',
        'budget',
        'ai_insights',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
            'issues_certificate' => 'boolean',
            'certificate_validity_months' => 'integer',
            'budget' => 'decimal:2',
            'ai_insights' => 'array',
        ];
    }

    /**
     * @return HasMany<TrainingEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }

    /**
     * @return HasMany<TrainingSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    /**
     * @return HasMany<TrainingCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(TrainingCost::class);
    }

    /**
     * Whether an employee can put themselves on this program now: it lets
     * people join (directly or by asking), and it is neither over nor archived.
     */
    public function acceptsSelfEnrollment(): bool
    {
        return $this->enrollment_mode !== 'closed'
            && ! $this->trashed()
            && $this->status() !== 'completed';
    }

    /**
     * Whether a date falls inside the program's window. An open side (no start
     * or no end date) takes any date on that side.
     */
    public function covers(CarbonInterface $date): bool
    {
        return ($this->start_date === null || $date->toDateString() >= $this->start_date->toDateString())
            && ($this->end_date === null || $date->toDateString() <= $this->end_date->toDateString());
    }

    /**
     * The program's lifecycle status, derived from today against its date window.
     * A program with no start date reads as upcoming.
     */
    public function status(): string
    {
        $today = today();

        if ($this->end_date !== null && $this->end_date->lt($today)) {
            return 'completed';
        }

        if ($this->start_date !== null && $this->start_date->lte($today)) {
            return 'ongoing';
        }

        return 'upcoming';
    }

    /**
     * Whether every seat is taken (capacity reached). Uncapped programs are never
     * full. Relies on the `active_enrollments_count` aggregate when present,
     * falling back to a count of non-dropped enrollments.
     */
    public function isFull(): bool
    {
        if ($this->capacity === null) {
            return false;
        }

        $taken = $this->active_enrollments_count
            ?? $this->enrollments()->active()->count();

        return $taken >= $this->capacity;
    }

    /**
     * Effectiveness analytics, derived from the roster: outcome counts, the
     * completion rate, the average score and the at-risk headcount (still
     * enrolled after the program has ended). Uses the loaded `enrollments` when
     * present — the program screen loads them anyway — and loads them otherwise.
     * Requests to join (and declined ones) are not on the roster and not counted.
     *
     * @return array{total: int, completed: int, dropped: int, enrolled: int, completion_rate: int|null, average_score: float|null, at_risk: int}
     */
    public function analytics(): array
    {
        $enrollments = $this->enrollments->whereIn('status', TrainingEnrollment::ROSTER_STATUSES);
        $total = $enrollments->count();

        $completed = $enrollments->where('status', 'completed')->count();
        $dropped = $enrollments->where('status', 'dropped')->count();
        $enrolled = $enrollments->where('status', 'enrolled')->count();

        $scored = $enrollments->whereNotNull('score');

        return [
            'total' => $total,
            'completed' => $completed,
            'dropped' => $dropped,
            'enrolled' => $enrolled,
            'completion_rate' => $total === 0 ? null : (int) round($completed / $total * 100),
            'average_score' => $scored->isEmpty()
                ? null
                : round((float) $scored->avg(fn (TrainingEnrollment $e): float => (float) $e->score), 1),
            'at_risk' => $this->status() === 'completed' ? $enrolled : 0,
        ];
    }

    /**
     * Newest / soonest programs first.
     *
     * @param  Builder<TrainingProgram>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByRaw('start_date is null')->orderByDesc('start_date');
    }

    /**
     * Case-insensitive search across the name and the provider (ADR 0069 — global search).
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
            foreach (['name', 'provider'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
