<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated sitting of a {@see TrainingProgram} (ADR 0074): a day and local
 * start / end times on the organisation's wall clock (ADR 0036), where it is,
 * and the attendance taken at it. A session is "held" once its day has come, and
 * only then can attendance be taken.
 */
class TrainingSession extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'training_program_id',
        'title',
        'session_date',
        'starts_at',
        'ends_at',
        'location',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<TrainingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id');
    }

    /**
     * @return HasMany<TrainingAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(TrainingAttendance::class);
    }

    /** The local start, "09:00". */
    public function startTime(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }

    /** The local end, "17:00". */
    public function endTime(): string
    {
        return substr((string) $this->ends_at, 0, 5);
    }

    /** The instant it starts, on the organisation's clock. */
    public function startsAt(): CarbonImmutable
    {
        return OrganizationClock::at($this->session_date->toDateString(), $this->startTime());
    }

    /** The instant it ends, on the organisation's clock. */
    public function endsAt(): CarbonImmutable
    {
        return OrganizationClock::at($this->session_date->toDateString(), $this->endTime());
    }

    /**
     * Whether its day has come on the organisation's clock — attendance can be
     * taken from then on.
     */
    public function isHeld(): bool
    {
        return $this->session_date->toDateString() <= OrganizationClock::today();
    }

    /**
     * In date order, earliest first.
     *
     * @param  Builder<TrainingSession>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('session_date')->orderBy('starts_at');
    }

    /**
     * Sessions of live (not archived) programs.
     *
     * @param  Builder<TrainingSession>  $query
     */
    public function scopeOfLivePrograms(Builder $query): void
    {
        $query->whereHas('program');
    }
}
