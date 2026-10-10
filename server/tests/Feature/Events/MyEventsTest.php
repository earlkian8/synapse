<?php

use App\Models\CalendarFeed;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

/*
| My events (ADR 0070): an employee sees and answers their own invitations on the
| web, downloads one, and subscribes to all of them from a calendar app.
*/

beforeEach(function () {
    $this->user = actingAsUserWith(['events.respond']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

function myInvitation(Employee $employee, array $event = [], string $response = 'invited'): Event
{
    $model = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), ...$event]);
    $model->attendees()->create(['employee_id' => $employee->id, 'response' => $response]);

    return $model;
}

test('the module has one way in: someone who only answers invitations lands on them', function () {
    $this->get(route('events.index'))->assertRedirect(route('events.me'));
    $this->get(route('events.rooms.index'))->assertForbidden();

    actingAsUserWith(['events.view']);
    $this->get(route('events.index'))->assertOk();

    actingAsUserWith(['leave.request']);
    $this->get(route('events.index'))->assertForbidden();
});

test('an employee sees only their own invitations, the one a notice pointed at opened', function () {
    $room = Room::create(['name' => 'Boardroom']);
    $mine = myInvitation($this->employee, ['room_id' => $room->id]);
    myInvitation(Employee::factory()->create(), ['title' => 'Not mine']);

    $this->get(route('events.me', ['event' => $mine->hashid]))->assertInertia(fn (Assert $page) => $page
        ->component('events/me')
        ->has('invitations', 1)
        ->where('invitations.0.event.title', 'Town hall')
        ->where('invitations.0.event.room.name', 'Boardroom')
        ->where('invitations.0.response', 'invited')
        ->where('focus', $mine->hashid)
        ->where('has_employee', true));
});

test('an employee answers their own invitation, and nobody else’s', function () {
    $mine = myInvitation($this->employee);
    $theirs = myInvitation(Employee::factory()->create(), ['title' => 'Not mine']);

    $this->post(route('events.me.respond', $mine), ['response' => 'tentative'])->assertRedirect();
    assertToast('success', 'Answered: Maybe.');

    $this->post(route('events.me.respond', $theirs), ['response' => 'accepted'])->assertNotFound();
    $this->post(route('events.me.respond', $mine), ['response' => 'invited'])->assertSessionHasErrors('response');

    expect($mine->attendees()->sole()->response)->toBe('tentative')
        ->and($theirs->attendees()->sole()->response)->toBe('invited');
});

test('a closed event says so, as a warning', function () {
    $past = myInvitation($this->employee, ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour()]);

    $this->post(route('events.me.respond', $past), ['response' => 'accepted'])->assertRedirect();

    assertToast('warning', 'This event is over');
});

test('without the permission there is no page and no answering', function () {
    $mine = myInvitation($this->employee);
    actingAsUserWith(['leave.request']);

    $this->get(route('events.me'))->assertForbidden();
    $this->post(route('events.me.respond', $mine), ['response' => 'accepted'])->assertForbidden();
});

test('another company’s event cannot be reached by its address', function () {
    $other = Organization::factory()->create();
    $foreign = app(Tenancy::class)->runFor($other, fn () => Event::create(['title' => 'Theirs', 'type' => 'event', 'starts_at' => now()->addDay()]));
    app(Tenancy::class)->forget();

    $this->post(route('events.me.respond', $foreign->hashid), ['response' => 'accepted'])->assertNotFound();
});

test('an invitee downloads their event as a calendar file', function () {
    $mine = myInvitation($this->employee, ['title' => 'Town hall; Q3, all-hands']);
    $theirs = myInvitation(Employee::factory()->create(), ['title' => 'Not mine']);

    $this->get(route('events.me.ics', $mine))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('SUMMARY:Town hall\; Q3\, all-hands', false);

    $this->get(route('events.me.ics', $theirs))->assertNotFound();
});

test('the subscription link is made on first ask, shown again, and replaced on reset', function () {
    $first = $this->getJson(route('events.me.calendar'))->assertOk()->json();
    $again = $this->getJson(route('events.me.calendar'))->json();
    $reset = $this->postJson(route('events.me.calendar.reset'))->assertOk()->json();

    expect($again['https'])->toBe($first['https'])
        ->and($first['webcal'])->toStartWith('webcal://')
        ->and($first['https'])->toContain('/api/calendar/')->toEndWith('.ics')
        ->and($reset['https'])->not->toBe($first['https']);
});

test('the feed serves what the person goes to or runs, and nothing they declined or that was archived', function () {
    $coming = myInvitation($this->employee, ['title' => 'Town hall'], 'accepted');
    myInvitation($this->employee, ['title' => 'Maybe lunch'], 'tentative');
    myInvitation($this->employee, ['title' => 'Declined one'], 'declined');
    myInvitation($this->employee, ['title' => 'Called off'])->delete();
    Event::create(['title' => 'I organise this', 'type' => 'meeting', 'starts_at' => now()->addDay(), 'organizer_id' => $this->user->id]);
    myInvitation(Employee::factory()->create(), ['title' => 'Someone else’s']);

    $token = CalendarFeed::issueFor($this->user)->plainToken();
    app(Tenancy::class)->forget();
    auth()->logout();

    $body = $this->get('/api/calendar/'.$token.'.ics')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->getContent();

    expect($body)->toContain('SUMMARY:Town hall', 'SUMMARY:Maybe lunch', 'STATUS:TENTATIVE', 'SUMMARY:I organise this', 'UID:event-'.$coming->hashid.'@')
        ->not->toContain('Declined one', 'Called off', 'Someone else');
    expect(CalendarFeed::findByToken($token)->last_used_at)->not->toBeNull();

    $this->get('/api/calendar/nope.ics')->assertNotFound();
});

test('the feed is one company’s, and stops when the person can no longer sign in to it', function () {
    myInvitation($this->employee, ['title' => 'Ours']);
    $token = CalendarFeed::issueFor($this->user)->plainToken();

    $other = Organization::factory()->create();
    OrganizationProvisioner::addMember($other, $this->user);
    app(Tenancy::class)->runFor($other, function () {
        $theirs = Event::create(['title' => 'Theirs', 'type' => 'event', 'starts_at' => now()->addDay()]);
        $theirs->attendees()->create(['employee_id' => Employee::factory()->create(['user_id' => $this->user->id])->id]);
    });
    app(Tenancy::class)->forget();

    expect($this->get('/api/calendar/'.$token.'.ics')->getContent())->toContain('Ours')->not->toContain('Theirs');

    User::query()->whereKey($this->user->id)->update(['is_active' => false]);

    $this->get('/api/calendar/'.$token.'.ics')->assertNotFound();
});
