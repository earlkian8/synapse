<?php

use App\Models\Employee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| HR's Events screens with repeats, rooms and reminders (ADR 0070).
*/

beforeEach(function () {
    actingAsSuperAdmin();
    testOrganization()->update(['timezone' => 'Asia/Manila']);
});

test('HR schedules a weekly meeting in a room, with a reminder', function () {
    $room = Room::create(['name' => 'Boardroom', 'capacity' => 10]);

    $this->post(route('events.store'), [
        'title' => 'Standup',
        'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00',
        'ends_at' => '2030-03-04T09:15',
        'room_id' => $room->id,
        'reminder_minutes' => 30,
        'repeat' => ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [1, 3], 'count' => 4],
    ])->assertRedirect()->assertSessionHasNoErrors();
    assertToast('success', 'Event scheduled — 4 dates.');

    $events = Event::query()->orderBy('starts_at')->get();

    expect($events)->toHaveCount(4)
        ->and($events->pluck('room_id')->unique()->all())->toBe([$room->id])
        ->and($events->pluck('reminder_minutes')->unique()->all())->toBe([30])
        ->and($events->pluck('series_id')->unique())->toHaveCount(1);
});

test('the form refuses a bad repeat and an unknown reminder', function () {
    $this->post(route('events.store'), [
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00', 'ends_at' => '2030-03-04T09:15',
        'reminder_minutes' => 7,
        'repeat' => ['frequency' => 'hourly', 'interval' => 9, 'weekdays' => [8], 'count' => 500],
    ])->assertSessionHasErrors(['reminder_minutes', 'repeat.frequency', 'repeat.interval', 'repeat.weekdays.0', 'repeat.count']);

    expect(Event::query()->count())->toBe(0);
});

test('a clash or an over-long series comes back on its field, so the form stays open', function () {
    $room = Room::create(['name' => 'Boardroom']);
    Event::create(['title' => 'Budget review', 'type' => 'meeting', 'starts_at' => '2030-03-04 01:00:00', 'ends_at' => '2030-03-04 02:00:00', 'room_id' => $room->id]);

    $this->post(route('events.store'), [
        'title' => 'Sales sync', 'type' => 'meeting',
        'starts_at' => '2030-03-04T09:30', 'ends_at' => '2030-03-04T10:30',
        'room_id' => $room->id,
    ])->assertSessionHasErrors(['room_id' => 'Boardroom is taken then by “Budget review” (Mar 4, 9:00 AM).']);

    $this->post(route('events.store'), [
        'title' => 'Daily', 'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00', 'ends_at' => '2030-03-04T09:15',
        'repeat' => ['frequency' => 'daily', 'until' => '2031-01-01'],
    ])->assertSessionHasErrors(['repeat']);
});

test('edit, archive and invite take a scope on a repeating event', function () {
    Notification::fake();
    $this->post(route('events.store'), [
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00', 'ends_at' => '2030-03-04T09:15',
        'repeat' => ['frequency' => 'daily', 'count' => 4],
    ]);
    [$first, $second] = Event::query()->orderBy('starts_at')->take(2)->get()->all();
    $employee = Employee::factory()->create();

    $this->post(route('events.update', $second), [
        'title' => 'Standup v2', 'type' => 'meeting',
        'starts_at' => '2030-03-05T09:30', 'ends_at' => '2030-03-05T09:45',
        'scope' => 'following',
    ])->assertSessionHasNoErrors();
    $this->post(route('events.attendees.store', $second), ['employee_ids' => [$employee->id], 'scope' => 'following'])->assertRedirect();
    $this->delete(route('events.destroy', [$second, 'scope' => 'following']))->assertRedirect(route('events.index'));
    assertToast('success', '3 events archived.');

    expect(Event::query()->pluck('title')->all())->toBe(['Standup'])
        ->and(Event::onlyTrashed()->pluck('title')->unique()->all())->toBe(['Standup v2'])
        ->and($employee->eventAttendances()->count())->toBe(3);
});

test('restoring an event whose room was taken meanwhile is refused with a warning', function () {
    $room = Room::create(['name' => 'Boardroom']);
    $old = Event::create(['title' => 'Budget review', 'type' => 'meeting', 'starts_at' => '2030-03-04 01:00:00', 'ends_at' => '2030-03-04 02:00:00', 'room_id' => $room->id]);
    $old->delete();
    Event::create(['title' => 'Sales sync', 'type' => 'meeting', 'starts_at' => '2030-03-04 01:00:00', 'ends_at' => '2030-03-04 02:00:00', 'room_id' => $room->id]);

    $this->patch(route('events.restore', $old->hashid))->assertRedirect();

    assertToast('warning', 'Boardroom is taken');
    expect(Event::onlyTrashed()->whereKey($old->id)->exists())->toBeTrue();
});

test('the event page shows its room, its series and where it sits in it', function () {
    $room = Room::create(['name' => 'Boardroom', 'capacity' => 6]);
    $this->post(route('events.store'), [
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00', 'ends_at' => '2030-03-04T09:15',
        'room_id' => $room->id, 'reminder_minutes' => 60,
        'repeat' => ['frequency' => 'weekly', 'count' => 3],
    ]);
    $second = Event::query()->orderBy('starts_at')->skip(1)->firstOrFail();

    $this->get(route('events.show', $second))->assertInertia(fn (Assert $page) => $page
        ->component('events/show')
        ->where('event.room.name', 'Boardroom')
        ->where('event.reminder_minutes', 60)
        ->where('event.series.summary', 'Every week on Mon')
        ->where('event.series.position', 2)
        ->where('event.series.total', 3));
});

test('rooms: listed with their week, managed, and checked for a window', function () {
    $this->post(route('events.rooms.store'), ['name' => 'Huddle', 'location' => '3F', 'capacity' => 4])->assertRedirect();
    assertToast('success', 'Room added.');
    $room = Room::query()->where('name', 'Huddle')->firstOrFail();
    Event::create(['title' => 'One-on-one', 'type' => 'meeting', 'starts_at' => '2030-03-04 01:00:00', 'ends_at' => '2030-03-04 02:00:00', 'room_id' => $room->id]);

    $this->get(route('events.rooms.index', ['week' => '2030-03-04']))->assertInertia(fn (Assert $page) => $page
        ->component('events/rooms')
        ->has('rooms', 1)
        ->where('rooms.0.name', 'Huddle')
        ->where('rooms.0.bookings.0.title', 'One-on-one')
        ->where('week.start', '2030-03-04'));

    $this->getJson(route('events.rooms.availability', ['starts_at' => '2030-03-04T09:30', 'ends_at' => '2030-03-04T10:30']))
        ->assertOk()
        ->assertJsonPath('rooms.0.name', 'Huddle')
        ->assertJsonPath('rooms.0.free', false)
        ->assertJsonPath('rooms.0.clash.title', 'One-on-one');

    $this->post(route('events.rooms.update', $room), ['name' => 'Huddle room', 'capacity' => 5, 'is_active' => false])->assertRedirect();
    $this->delete(route('events.rooms.force-delete', $room->hashid))->assertRedirect();
    assertToast('warning', 'has bookings');

    expect($room->refresh()->name)->toBe('Huddle room')->and($room->is_active)->toBeFalse();
});

test('someone who can only see events cannot manage rooms', function () {
    actingAsUserWith(['events.view']);

    $this->get(route('events.rooms.index'))->assertOk();
    $this->post(route('events.rooms.store'), ['name' => 'Nope'])->assertForbidden();
    $this->getJson(route('events.rooms.availability', ['starts_at' => '2030-03-04T09:30', 'ends_at' => '2030-03-04T10:30']))->assertForbidden();
});

test('another company’s room cannot be booked', function () {
    $other = Organization::factory()->create();
    $foreign = app(Tenancy::class)->runFor($other, fn () => Room::create(['name' => 'Their room']));

    $this->post(route('events.store'), [
        'title' => 'Sneaky', 'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00', 'ends_at' => '2030-03-04T10:00',
        'room_id' => $foreign->id,
    ])->assertSessionHasErrors('room_id');
});

test('the reminder command runs every company’s due reminders', function () {
    Notification::fake();
    $user = User::factory()->create(['is_active' => true]);
    $event = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addMinutes(20), 'reminder_minutes' => 30]);
    $event->attendees()->create(['employee_id' => Employee::factory()->create(['user_id' => $user->id])->id]);
    app(Tenancy::class)->forget();

    Artisan::call('events:remind');

    expect(Artisan::output())->toContain('1 reminder sent.')
        ->and($event->refresh()->reminder_sent_at)->not->toBeNull();
});
