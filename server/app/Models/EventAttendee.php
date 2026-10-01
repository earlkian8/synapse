<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's invitation to an {@see Event}: their response and the moment they
 * were notified. One row per employee per event.
 */
class EventAttendee extends Model
{
    use BelongsToOrganization;

    /** The responses an invitee can give. */
    public const RESPONSES = ['invited', 'accepted', 'declined', 'tentative'];

    protected $fillable = [
        'organization_id',
        'event_id',
        'employee_id',
        'response',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Limit to invitees who are coming (accepted or tentative) — the headcount.
     *
     * @param  Builder<EventAttendee>  $query
     */
    public function scopeAttending(Builder $query): void
    {
        $query->whereIn('response', ['accepted', 'tentative']);
    }
}
