<?php

namespace App\Queries;

use App\Models\Employee;
use App\Models\EventAttendee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One employee's own invitations (ADR 0070), for My events on the web and the
 * mobile app alike: what is still to come (soonest first), then what took
 * place in the last {@see self::PAST_DAYS} days (latest first). Archived
 * events are left out — they were called off.
 */
final class MyInvitations
{
    public const PAST_DAYS = 30;

    /**
     * @return Collection<int, EventAttendee>
     */
    public static function for(Employee $employee): Collection
    {
        $rows = self::query($employee)
            ->whereHas('event', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('starts_at', '>=', now()->subDays(self::PAST_DAYS))
                    ->orWhere('ends_at', '>=', now()),
            ))
            ->get()
            ->sortBy(fn (EventAttendee $row): int => $row->event->starts_at->getTimestamp());

        [$past, $ahead] = $rows->partition(fn (EventAttendee $row): bool => $row->event->status() === 'past');

        return $ahead->values()->concat($past->reverse()->values());
    }

    /**
     * The employee's invitation to one event, ready to show, or null.
     */
    public static function one(Employee $employee, int $eventId): ?EventAttendee
    {
        return self::query($employee)->where('event_id', $eventId)->first();
    }

    /**
     * @return Builder<EventAttendee>
     */
    private static function query(Employee $employee): Builder
    {
        return EventAttendee::query()
            ->where('employee_id', $employee->id)
            ->whereHas('event')
            ->with(['event' => fn ($query) => $query
                ->with(['organizer:id,first_name,last_name', 'room', 'series'])
                ->withCount('attendees')
                ->withCount(['attendees as attending_count' => fn (Builder $q) => $q->attending()])]);
    }
}
