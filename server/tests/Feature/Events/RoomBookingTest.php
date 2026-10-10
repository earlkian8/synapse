<?php

use App\Models\Event;
use App\Models\Room;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use App\Support\Events\RoomBooking;
use App\Support\OrganizationClock;

/*
| Rooms (ADR 0070): two live events never hold one room at the same time; a room
| needs an end time; a retired room takes no new bookings; an archived event
| frees its room, and coming back into a taken slot is refused.
*/

beforeEach(function () {
    $this->organizer = actingAsSuperAdmin();
    testOrganization()->update(['timezone' => 'Asia/Manila']);
    $this->workflow = app(EventWorkflow::class);
    $this->room = Room::create(['name' => 'Boardroom', 'capacity' => 8]);
});

function booking(EventWorkflow $workflow, $organizer, Room $room, string $title, string $start, ?string $end, array $data = []): Event
{
    return $workflow->schedule([
        'title' => $title,
        'type' => 'meeting',
        'starts_at' => $start,
        'ends_at' => $end,
        'room_id' => $room->id,
        ...$data,
    ], $organizer);
}

test('a room cannot be held twice at once, and the refusal names what holds it', function () {
    booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-04T09:00', '2030-03-04T10:00');

    expect(fn () => booking($this->workflow, $this->organizer, $this->room, 'Sales sync', '2030-03-04T09:30', '2030-03-04T10:30'))
        ->toThrow(EventException::class, 'Boardroom is taken then by “Budget review”');

    expect(Event::query()->where('title', 'Sales sync')->exists())->toBeFalse();
});

test('back-to-back bookings are fine', function () {
    booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-04T09:00', '2030-03-04T10:00');
    $next = booking($this->workflow, $this->organizer, $this->room, 'Sales sync', '2030-03-04T10:00', '2030-03-04T11:00');

    expect($next->room_id)->toBe($this->room->id);
});

test('a room needs an end time', function () {
    expect(fn () => booking($this->workflow, $this->organizer, $this->room, 'Quick chat', '2030-03-04T09:00', null))
        ->toThrow(EventException::class, 'A room needs an end time');
});

test('a retired room takes no new bookings, but an event that holds it keeps it', function () {
    $held = booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-04T09:00', '2030-03-04T10:00');
    $this->room->update(['is_active' => false]);

    expect(fn () => booking($this->workflow, $this->organizer, $this->room, 'Sales sync', '2030-03-05T09:00', '2030-03-05T10:00'))
        ->toThrow(EventException::class, 'Boardroom is no longer booked');

    $this->workflow->update($held, [
        'title' => 'Budget review (final)',
        'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00',
        'ends_at' => '2030-03-04T10:00',
        'room_id' => $this->room->id,
    ]);

    expect($held->refresh()->room_id)->toBe($this->room->id);
});

test('moving an event into a taken slot is refused, and it stays where it was', function () {
    booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-04T09:00', '2030-03-04T10:00');
    $other = booking($this->workflow, $this->organizer, $this->room, 'Sales sync', '2030-03-04T13:00', '2030-03-04T14:00');

    expect(fn () => $this->workflow->update($other, [
        'title' => 'Sales sync',
        'type' => 'meeting',
        'starts_at' => '2030-03-04T09:30',
        'ends_at' => '2030-03-04T10:30',
        'room_id' => $this->room->id,
    ]))->toThrow(EventException::class, 'taken then');

    expect(OrganizationClock::local($other->refresh()->starts_at)->format('H:i'))->toBe('13:00');
});

test('archiving an event frees its room, and restoring it into a taken slot is refused', function () {
    $first = booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-04T09:00', '2030-03-04T10:00');
    $this->workflow->archive($first);

    booking($this->workflow, $this->organizer, $this->room, 'Sales sync', '2030-03-04T09:00', '2030-03-04T10:00');

    expect(fn () => $this->workflow->restore(Event::onlyTrashed()->findOrFail($first->id)))
        ->toThrow(EventException::class, 'Boardroom is taken then by “Sales sync”');

    expect(Event::onlyTrashed()->whereKey($first->id)->exists())->toBeTrue();
});

test('a series is refused when any of its dates clashes, and nothing is made', function () {
    booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-11T09:00', '2030-03-11T10:00');

    expect(fn () => booking($this->workflow, $this->organizer, $this->room, 'Standup', '2030-03-04T09:00', '2030-03-04T09:30', [
        'repeat' => ['frequency' => 'weekly', 'count' => 3],
    ]))->toThrow(EventException::class, 'Mar 11');

    expect(Event::query()->where('title', 'Standup')->count())->toBe(0);
});

test('availability marks each active room free or taken, leaving out the event being edited', function () {
    $held = booking($this->workflow, $this->organizer, $this->room, 'Budget review', '2030-03-04T09:00', '2030-03-04T10:00');
    $spare = Room::create(['name' => 'Huddle', 'capacity' => 4]);
    Room::create(['name' => 'Old annex', 'is_active' => false]);

    $start = OrganizationClock::parse('2030-03-04 09:30');
    $end = OrganizationClock::parse('2030-03-04 10:30');

    $rooms = collect(app(RoomBooking::class)->availability($start, $end))->keyBy(fn (array $row): string => $row['room']->name);
    $editing = collect(app(RoomBooking::class)->availability($start, $end, [$held->id]))->keyBy(fn (array $row): string => $row['room']->name);

    expect($rooms->keys()->all())->toBe(['Boardroom', 'Huddle'])
        ->and($rooms['Boardroom']['free'])->toBeFalse()
        ->and($rooms['Boardroom']['clash']->title)->toBe('Budget review')
        ->and($rooms['Huddle']['free'])->toBeTrue()
        ->and($editing['Boardroom']['free'])->toBeTrue();
});
