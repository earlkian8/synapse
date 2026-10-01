<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company event or meeting the organisation schedules (ERD §9): its kind, the
 * date-time window, where it happens, and who is running it. Employees are tied to
 * it as invitees through {@see EventAttendee}. The lifecycle **status is derived**
 * from the window (`upcoming → ongoing → past`), never stored, so it cannot drift.
 */
class Event extends Model
{
    use BelongsToOrganization, HasHashid, SoftDeletes;

    /** The two kinds of entry this module schedules. */
    public const TYPES = ['event', 'meeting'];

    /** The derived lifecycle an event can be in. */
    public const STATUSES = ['upcoming', 'ongoing', 'past'];

    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'type',
        'starts_at',
        'ends_at',
        'location',
        'organizer_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * The invitees for this event.
     *
     * @return HasMany<EventAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /**
     * The user running the event. Kept even if the account is archived, so a past
     * event still shows who organised it.
     *
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id')->withTrashed();
    }

    /**
     * The event's lifecycle status, derived from now against its window. An event
     * with no end reads as a point in time (ongoing only while it has just started).
     */
    public function status(): string
    {
        $now = now();

        if ($this->starts_at !== null && $this->starts_at->gt($now)) {
            return 'upcoming';
        }

        $end = $this->ends_at ?? $this->starts_at;

        if ($end !== null && $end->lt($now)) {
            return 'past';
        }

        return 'ongoing';
    }

    /**
     * Soonest first — the chronological ordering for the overview.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('starts_at')->orderBy('id');
    }

    /**
     * Most recent first — for the past / archived sections.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByDesc('starts_at')->orderByDesc('id');
    }
}
