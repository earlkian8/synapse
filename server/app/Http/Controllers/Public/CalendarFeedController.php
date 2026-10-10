<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Support\Events\EventCalendar;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * A person's calendar subscription (ADR 0070), fetched by their calendar app
 * with no session — the secret token in the address is the only key. It serves
 * one company's events: the ones they are invited to and have not declined, and
 * the ones they organise, from {@see self::PAST_DAYS} days back to
 * {@see self::AHEAD_DAYS} ahead. Archived events drop out, and the calendar app
 * removes them on its next look.
 *
 * The link stops working when it is reset, when the account is deactivated, and
 * when the person no longer belongs to the company.
 */
class CalendarFeedController extends Controller
{
    public const PAST_DAYS = 60;

    public const AHEAD_DAYS = 400;

    public function __invoke(string $token, Tenancy $tenancy): Response
    {
        $feed = CalendarFeed::findByToken($token);
        $user = $feed?->user;

        abort_if($feed === null || $user === null || ! $user->is_active, 404);
        abort_unless($user->memberships()->whereKey($feed->organization_id)->exists(), 404);

        $organization = $feed->organization()->firstOrFail();

        $body = $tenancy->runFor($organization, function () use ($feed, $user, $organization): string {
            $employee = $user->employee()->first();
            $responses = $employee
                ? $employee->eventAttendances()->where('response', '!=', 'declined')->pluck('response', 'event_id')->all()
                : [];

            $events = Event::query()
                ->where(fn (Builder $query) => $query
                    ->whereIn('id', array_keys($responses))
                    ->orWhere('organizer_id', $user->id))
                ->whereBetween('starts_at', [now()->subDays(self::PAST_DAYS), now()->addDays(self::AHEAD_DAYS)])
                ->with('room:id,name')
                ->orderBy('starts_at')
                ->get();

            $feed->forceFill(['last_used_at' => now()])->saveQuietly();

            return EventCalendar::document($events, "{$organization->name} events", $responses);
        });

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="events.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
