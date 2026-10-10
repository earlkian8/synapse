<?php

use App\Models\Employee;
use App\Models\Event;
use App\Models\Room;
use App\Services\Assistant\Modules\EventsModule;
use App\Services\Assistant\ToolResult;
use App\Support\Events\EventWorkflow;
use App\Support\OrganizationClock;

/*
| The assistant and ADR 0070: one's own invitations and answers, rooms, repeats
| and reminders — through EventWorkflow, as the screens.
*/

function selfEventsAgent($user, string $tool, array $args = []): ToolResult
{
    return app(EventsModule::class)->run($user, $tool, $args);
}

test('someone who only answers invitations gets their own tools, not the calendar', function () {
    $user = actingAsUserWith(['events.respond']);
    $tools = array_column(app(EventsModule::class)->tools($user), 'name');

    expect(app(EventsModule::class)->isAvailable($user))->toBeTrue()
        ->and($tools)->toBe(['find_my_invitations', 'respond_to_event']);
});

test('the assistant lists my invitations and answers one for me, for later dates too', function () {
    $user = actingAsUserWith(['events.respond']);
    $me = Employee::factory()->create(['user_id' => $user->id]);
    $standup = app(EventWorkflow::class)->schedule([
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => now()->addDay()->format('Y-m-d 09:00'), 'ends_at' => now()->addDay()->format('Y-m-d 09:15'),
        'repeat' => ['frequency' => 'daily', 'count' => 3],
    ], $user);
    app(EventWorkflow::class)->invite($standup, [$me->id], null, scope: 'following');
    Event::create(['title' => 'Board only', 'type' => 'meeting', 'starts_at' => now()->addDays(2)]);

    $list = selfEventsAgent($user, 'find_my_invitations');
    $answered = selfEventsAgent($user, 'respond_to_event', ['event' => 'Standup', 'response' => 'accepted', 'all_following' => true, 'date' => now()->addDay()->toDateString()]);
    $notMine = selfEventsAgent($user, 'respond_to_event', ['event' => 'Board only', 'response' => 'accepted']);

    expect($list->cards)->toHaveCount(3)
        ->and($answered->status)->toBe('done')
        ->and($answered->label)->toContain('Going')
        ->and($me->eventAttendances()->where('response', 'accepted')->count())->toBe(3)
        ->and($notMine->status)->toBe('error')
        ->and($notMine->detail)->toContain('No event among your invitations matches “Board only”');
});

test('scheduling through the assistant books a room, sets a reminder and repeats', function () {
    $user = actingAsSuperAdmin();
    Room::create(['name' => 'Boardroom', 'capacity' => 8]);
    $start = now()->addDays(3);

    $result = selfEventsAgent($user, 'schedule_event', [
        'title' => 'Ops sync', 'type' => 'meeting',
        'starts_at' => $start->format('Y-m-d').' 10:00', 'ends_at' => $start->format('Y-m-d').' 10:30',
        'room' => 'boardroom', 'reminder_minutes' => 30,
        'repeat' => ['frequency' => 'weekly', 'count' => 4],
    ]);

    $events = Event::query()->where('title', 'Ops sync')->get();

    expect($result->status)->toBe('done')
        ->and($result->detail)->toContain('4 dates')
        ->and($events)->toHaveCount(4)
        ->and($events->pluck('reminder_minutes')->unique()->all())->toBe([30])
        ->and($events->pluck('room_id')->filter()->count())->toBe(4);
});

test('a room clash or an unknown room comes back in words', function () {
    $user = actingAsSuperAdmin();
    $room = Room::create(['name' => 'Boardroom']);
    $start = now()->addDays(3)->format('Y-m-d');
    Event::create(['title' => 'Budget', 'type' => 'meeting', 'starts_at' => OrganizationClock::parse("{$start} 10:00"), 'ends_at' => OrganizationClock::parse("{$start} 11:00"), 'room_id' => $room->id]);

    $clash = selfEventsAgent($user, 'schedule_event', ['title' => 'Sync', 'type' => 'meeting', 'starts_at' => "{$start} 10:30", 'ends_at' => "{$start} 11:30", 'room' => 'Boardroom']);
    $unknown = selfEventsAgent($user, 'schedule_event', ['title' => 'Sync', 'type' => 'meeting', 'starts_at' => "{$start} 13:00", 'ends_at' => "{$start} 14:00", 'room' => 'Atrium']);

    expect($clash->status)->toBe('error')
        ->and($clash->detail)->toContain('Boardroom is taken then by “Budget”')
        ->and($unknown->detail)->toContain('No room is called “Atrium”');
});

test('the assistant says which rooms are free in a window', function () {
    $user = actingAsUserWith(['events.view']);
    $room = Room::create(['name' => 'Boardroom', 'capacity' => 8]);
    Room::create(['name' => 'Huddle', 'capacity' => 4]);
    $day = now()->addDays(3)->format('Y-m-d');
    Event::create(['title' => 'Budget', 'type' => 'meeting', 'starts_at' => OrganizationClock::parse("{$day} 10:00"), 'ends_at' => OrganizationClock::parse("{$day} 11:00"), 'room_id' => $room->id]);

    $result = selfEventsAgent($user, 'find_rooms', ['starts_at' => "{$day} 10:30", 'ends_at' => "{$day} 11:30"]);
    $badges = collect($result->cards)->pluck('badge', 'title');

    expect($badges['Boardroom'])->toBe('Taken')->and($badges['Huddle'])->toBe('Free');
});

test('archiving this and later dates goes through the scope', function () {
    $user = actingAsSuperAdmin();
    app(EventWorkflow::class)->schedule([
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => now()->addDay()->format('Y-m-d 09:00'), 'ends_at' => now()->addDay()->format('Y-m-d 09:15'),
        'repeat' => ['frequency' => 'daily', 'count' => 3],
    ], $user);

    $result = selfEventsAgent($user, 'archive_event', ['event' => 'Standup', 'date' => now()->addDays(2)->toDateString(), 'scope' => 'following']);

    expect($result->status)->toBe('done')
        ->and(Event::query()->where('title', 'Standup')->count())->toBe(1);
});
