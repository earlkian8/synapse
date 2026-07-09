<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A training program / cohort the organisation runs (ERD §8): its provider, date
 * window and seat capacity. Employees are tied to it through
 * {@see TrainingEnrollment}. The lifecycle **status is derived** from the dates
 * (`upcoming → ongoing → completed`), never stored, so it cannot drift.
 */
class TrainingProgram extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** The derived lifecycle a program can be in. */
    public const STATUSES = ['upcoming', 'ongoing', 'completed'];

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'provider',
        'start_date',
        'end_date',
        'capacity',
        'ai_insights',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
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
            ?? $this->enrollments()->where('status', '!=', 'dropped')->count();

        return $taken >= $this->capacity;
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
}
