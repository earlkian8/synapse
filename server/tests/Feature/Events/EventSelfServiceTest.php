<?php

use App\Models\Employee;
use App\Models\Event;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use Illuminate\Support\Facades\Notification;

/*
| An invitee answers for themselves, and reminders go out on their own before an
| event (ADR 0070).
*/

beforeEach(function () {
    $this->organizer = actingAsSuperAdmin();
    $this->workflow = app(EventWorkflow::class);
});

function selfServiceInvitee(array $user = ['is_active' => true]): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create($user)->id]);
}

function invitedTo(Event $event, Employee $employee, string $response = 'invited'): void
{
    $event->attendees()->create(['employee_id' => $employee->id, 'response' => $response]);
}

test('an invitee answers for themselves, and when they did is kept', function () {
    $event = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addDays(2)]);
    $employee = selfServiceInvitee();
    invitedTo($event, $employee);

    $answered = $this->workflow->respondAsInvitee($event, $employee, 'accepted');
    $row = $event->attendees()->sole();

    expect($answered)->toBe(1)
        ->and($row->response)->toBe('accepted')
        ->and($row->responded_at)->not->toBeNull();
});

test('only accepted, tentative or declined can be given', function () {
    $event = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addDays(2)]);
    $employee = selfServiceInvitee();
    invitedTo($event, $employee, 'accepted');

    expect(fn () => $this->workflow->respondAsInvitee($event, $employee, 'invited'))
        ->toThrow(EventException::class, 'Answer with accepted, tentative or declined.');
});

test('an event that is over takes no more answers', function () {
    $event = Event::create(['title' => 'Last week', 'type' => 'event', 'starts_at' => now()->subDays(7), 'ends_at' => now()->subDays(7)->addHour()]);
    $employee = selfServiceInvitee();
    invitedTo($event, $employee);

    expect(fn () => $this->workflow->respondAsInvitee($event, $employee, 'accepted'))
        ->toThrow(EventException::class, 'This event is over');
});

test('somebody who is not invited cannot answer', function () {
    $event = Event::create(['title' => 'Board only', 'type' => 'meeting', 'starts_at' => now()->addDay()]);

    expect(fn () => $this->workflow->respondAsInvitee($event, selfServiceInvitee(), 'accepted'))
        ->toThrow(EventException::class, 'not invited');
});

test('answering for this and following covers every later invitation, not the earlier ones', function () {
    $first = $this->workflow->schedule([
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => now()->addDays(1)->format('Y-m-d\T09:00'),
        'ends_at' => now()->addDays(1)->format('Y-m-d\T09:15'),
        'repeat' => ['frequency' => 'daily', 'count' => 3],
    ], $this->organizer);
    $employee = selfServiceInvitee();
    $this->workflow->invite($first, [$employee->id], $this->organizer, scope: 'following');
    $second = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->skip(1)->firstOrFail();

    $answered = $this->workflow->respondAsInvitee($second, $employee, 'declined', 'following');

    expect($answered)->toBe(2)
        ->and($employee->eventAttendances()->orderBy('event_id')->pluck('response')->all())->toBe(['invited', 'declined', 'declined']);
});

test('a due reminder goes once, to everybody who has not declined and can sign in', function () {
    Notification::fake();
    $event = Event::create([
        'title' => 'Town hall', 'type' => 'event',
        'starts_at' => now()->addMinutes(50), 'reminder_minutes' => 60,
    ]);
    $coming = selfServiceInvitee();
    $silent = selfServiceInvitee();
    $declined = selfServiceInvitee();
    $inactive = selfServiceInvitee(['is_active' => false]);
    invitedTo($event, $coming, 'accepted');
    invitedTo($event, $silent);
    invitedTo($event, $declined, 'declined');
    invitedTo($event, $inactive, 'tentative');

    expect($this->workflow->sendDueReminders())->toBe(2)
        ->and($this->workflow->sendDueReminders())->toBe(0)
        ->and($event->refresh()->reminder_sent_at)->not->toBeNull();

    Notification::assertSentToTimes($coming->user, SystemNotification::class, 1);
    Notification::assertSentTo($silent->user, SystemNotification::class, fn (SystemNotification $notice): bool => str_starts_with($notice->title, 'Starting soon: Town hall')
        && $notice->url === '/events/me?event='.$event->hashid);
    Notification::assertNotSentTo($declined->user, SystemNotification::class);
    Notification::assertNotSentTo($inactive->user, SystemNotification::class);
});

test('a reminder waits until it is due, and none goes for an event with none set, started, or archived', function () {
    Notification::fake();
    $later = Event::create(['title' => 'Later', 'type' => 'event', 'starts_at' => now()->addHours(3), 'reminder_minutes' => 60]);
    $none = Event::create(['title' => 'No reminder', 'type' => 'event', 'starts_at' => now()->addMinutes(20)]);
    $started = Event::create(['title' => 'Started', 'type' => 'event', 'starts_at' => now()->subMinutes(5), 'reminder_minutes' => 60]);
    $archived = Event::create(['title' => 'Archived', 'type' => 'event', 'starts_at' => now()->addMinutes(20), 'reminder_minutes' => 60]);
    $archived->delete();

    foreach ([$later, $none, $started, $archived] as $event) {
        invitedTo($event, selfServiceInvitee());
    }

    expect($this->workflow->sendDueReminders())->toBe(0);
    Notification::assertNothingSent();
});

test('moving the start sends the reminder again, for the new time', function () {
    Notification::fake();
    $event = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addMinutes(30), 'reminder_minutes' => 60]);
    invitedTo($event, selfServiceInvitee());
    $this->workflow->sendDueReminders();

    $this->workflow->update($event, [
        'title' => 'Town hall',
        'type' => 'event',
        'starts_at' => now()->addDays(2),
    ]);

    expect($event->refresh()->reminder_sent_at)->toBeNull();
});
