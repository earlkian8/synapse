<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo events & meetings: a spread across the lifecycle (past, happening now and
 * upcoming) with a believable mix of attendees and responses, so the module has
 * real rosters and headcounts. Schedules are relative to "now" so the derived
 * statuses always look natural. Idempotent.
 */
class EventSeeder extends Seeder
{
    /**
     * The starter events (title => attributes). `start`/`end` are hour offsets from
     * now (negative = past), so the derived status reads naturally on any day.
     *
     * @var list<array{title: string, type: string, start: int, end: ?int, location: string, description: string}>
     */
    private const EVENTS = [
        ['title' => 'Company-wide Town Hall', 'type' => 'event', 'start' => -480, 'end' => -477, 'location' => 'Main Auditorium', 'description' => 'Quarterly business update and open Q&A with leadership.'],
        ['title' => 'Q2 Department Heads Sync', 'type' => 'meeting', 'start' => -168, 'end' => -167, 'location' => 'Conference Room A', 'description' => 'Cross-department alignment on the quarter\'s priorities.'],
        ['title' => 'All-Hands Standup', 'type' => 'meeting', 'start' => -1, 'end' => 2, 'location' => 'Zoom', 'description' => 'Weekly company standup — wins, blockers and announcements.'],
        ['title' => 'New Hire Orientation', 'type' => 'event', 'start' => 48, 'end' => 52, 'location' => 'Training Room 2', 'description' => 'Welcome session, systems walkthrough and policy overview.'],
        ['title' => 'Mid-Year Performance Kickoff', 'type' => 'meeting', 'start' => 120, 'end' => 121, 'location' => 'Conference Room B', 'description' => 'Briefing on the mid-year evaluation cycle and timelines.'],
        ['title' => 'Year-End Christmas Party', 'type' => 'event', 'start' => 360, 'end' => 366, 'location' => 'Grand Ballroom, Makati', 'description' => 'Annual celebration, awarding and fellowship night.'],
    ];

    /** The response cycle used to give attendees a believable spread. */
    private const RESPONSES = ['accepted', 'accepted', 'tentative', 'declined', 'invited'];

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        $events = $this->seedEvents();

        if (EventAttendee::count() > 0) {
            return;
        }

        $this->seedAttendees($events);
    }

    /**
     * Seed the events. Idempotent — keyed by title.
     *
     * @return Collection<string, Event>
     */
    private function seedEvents(): Collection
    {
        $organizerId = User::query()->orderBy('id')->value('id');
        $events = collect();

        foreach (self::EVENTS as $event) {
            $events->put($event['title'], Event::firstOrCreate(
                ['title' => $event['title']],
                [
                    'type' => $event['type'],
                    'description' => $event['description'],
                    'location' => $event['location'],
                    'starts_at' => now()->addHours($event['start']),
                    'ends_at' => $event['end'] === null ? null : now()->addHours($event['end']),
                    'organizer_id' => $organizerId,
                ],
            ));
        }

        return $events;
    }

    /**
     * Invite a believable spread of employees to each event, with mixed responses.
     * Past events read as fully responded; future ones keep some still "invited".
     *
     * @param  Collection<string, Event>  $events
     */
    private function seedAttendees(Collection $events): void
    {
        $employees = Employee::query()->where('employment_status', 'active')->orderBy('id')->get()->values();

        foreach ($events as $event) {
            $isPast = $event->status() === 'past';

            foreach ($employees as $i => $employee) {
                // Big events invite everyone; meetings invite a subset.
                if ($event->type === 'meeting' && $i % 3 === 2) {
                    continue;
                }

                $response = $isPast
                    ? (self::RESPONSES[$i % 4]) // past events: no lingering "invited"
                    : self::RESPONSES[$i % count(self::RESPONSES)];

                EventAttendee::firstOrCreate(
                    ['event_id' => $event->id, 'employee_id' => $employee->id],
                    [
                        'response' => $response,
                        'notified_at' => $employee->user_id ? $event->created_at : null,
                    ],
                );
            }
        }
    }
}
