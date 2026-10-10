<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Events\EventWorkflow;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo events & meetings: a spread across the lifecycle (past, happening now and
 * upcoming) with a believable mix of attendees and responses, so the module has
 * real rosters and headcounts. Schedules are relative to "now" so the derived
 * statuses always look natural. Idempotent.
 *
 * ADR 0070 adds rooms (the meeting rooms that match the events' locations, which
 * hold them), an hour-before reminder on what is still ahead, and a weekly team
 * standup that repeats — made through EventWorkflow, as the screen would. No
 * notification is sent: attendees are written directly.
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

    /**
     * The rooms (name => [where, seats]). An event whose location names one holds it.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const ROOMS = [
        'Conference Room A' => ['5th floor, east wing', 10],
        'Conference Room B' => ['5th floor, west wing', 8],
        'Training Room 2' => ['3rd floor', 30],
        'Huddle Pod' => ['4th floor, by the pantry', 4],
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

        $rooms = $this->seedRooms();
        $events = $this->seedEvents($rooms);

        if (EventAttendee::count() > 0) {
            return;
        }

        $this->seedAttendees($events);
        $this->seedStandup($rooms['Huddle Pod']);
    }

    /**
     * @return Collection<string, Room>
     */
    private function seedRooms(): Collection
    {
        return collect(self::ROOMS)->map(fn (array $room, string $name): Room => Room::firstOrCreate(
            ['name' => $name],
            ['location' => $room[0], 'capacity' => $room[1]],
        ));
    }

    /**
     * A team standup every Monday, Wednesday and Friday at 9:00 for four weeks
     * from next Monday, in the huddle pod, with a handful of the team invited.
     */
    private function seedStandup(Room $room): void
    {
        $organizer = User::query()->orderBy('id')->first();

        if ($organizer === null || Event::query()->where('title', 'Team Standup')->exists()) {
            return;
        }

        $monday = OrganizationClock::now()->next('Monday')->toDateString();

        $first = app(EventWorkflow::class)->schedule([
            'title' => 'Team Standup',
            'type' => 'meeting',
            'description' => 'Fifteen minutes: yesterday, today, and anything in the way.',
            'starts_at' => "{$monday} 09:00",
            'ends_at' => "{$monday} 09:15",
            'room_id' => $room->id,
            'reminder_minutes' => 10,
            'repeat' => ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [1, 3, 5], 'count' => 12],
        ], $organizer);

        $team = Employee::query()->where('employment_status', 'active')
            ->whereNotNull('user_id')->orderBy('id')->limit(4)->get()
            ->concat(Employee::query()->where('employment_status', 'active')->whereNull('user_id')->orderBy('id')->limit(2)->get());

        foreach (Event::query()->where('series_id', $first->series_id)->get() as $occurrence) {
            foreach ($team as $i => $employee) {
                EventAttendee::firstOrCreate(
                    ['event_id' => $occurrence->id, 'employee_id' => $employee->id],
                    ['response' => $i % 3 === 0 ? 'invited' : 'accepted', 'notified_at' => $employee->user_id ? now() : null],
                );
            }
        }
    }

    /**
     * Seed the events. Idempotent — keyed by title. One held in a room books it;
     * what is still ahead reminds its invitees an hour before.
     *
     * @param  Collection<string, Room>  $rooms
     * @return Collection<string, Event>
     */
    private function seedEvents(Collection $rooms): Collection
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
                    'room_id' => $rooms->get($event['location'])?->id,
                    'reminder_minutes' => $event['start'] > 0 ? 60 : null,
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
