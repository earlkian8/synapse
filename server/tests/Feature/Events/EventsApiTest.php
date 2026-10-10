<?php

use App\Models\Employee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Room;
use App\Support\Events\EventWorkflow;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

/*
| The mobile app's events (ADR 0070): the signed-in employee's invitations, an
| answer from the phone, and the calendar subscription link.
*/

beforeEach(function () {
    $this->user = actingAsUserWith(['events.respond']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    Sanctum::actingAs($this->user);
});

function apiInvitation(Employee $employee, array $event = [], string $response = 'invited'): Event
{
    $model = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), ...$event]);
    $model->attendees()->create(['employee_id' => $employee->id, 'response' => $response]);

    return $model;
}

test('the app lists my invitations, soonest first, with what it needs to show each', function () {
    $room = Room::create(['name' => 'Boardroom', 'location' => '5F']);
    apiInvitation($this->employee, ['title' => 'Later', 'starts_at' => now()->addDays(9)]);
    apiInvitation($this->employee, ['title' => 'Sooner', 'room_id' => $room->id], 'accepted');
    apiInvitation($this->employee, ['title' => 'Last month', 'starts_at' => now()->subDays(20), 'ends_at' => now()->subDays(20)->addHour()]);
    apiInvitation(Employee::factory()->create(), ['title' => 'Not mine']);

    $this->getJson('/api/events')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.event.title', 'Sooner')
        ->assertJsonPath('data.0.event.room.name', 'Boardroom')
        ->assertJsonPath('data.0.response', 'accepted')
        ->assertJsonPath('data.1.event.title', 'Later')
        ->assertJsonPath('data.2.event.title', 'Last month')
        ->assertJsonPath('data.2.event.status', 'past')
        ->assertJsonPath('pending', 1);
});

test('the app answers for this date or every later one', function () {
    $first = app(EventWorkflow::class)->schedule([
        'title' => 'Standup', 'type' => 'meeting',
        'starts_at' => now()->addDays(1)->format('Y-m-d\T09:00'),
        'ends_at' => now()->addDays(1)->format('Y-m-d\T09:15'),
        'repeat' => ['frequency' => 'daily', 'count' => 3],
    ], $this->user);
    app(EventWorkflow::class)->invite($first, [$this->employee->id], null, scope: 'following');

    $this->postJson("/api/events/{$first->hashid}/respond", ['response' => 'accepted', 'scope' => 'following'])
        ->assertOk()
        ->assertJsonPath('answered', 3)
        ->assertJsonPath('data.response', 'accepted');

    $this->getJson("/api/events/{$first->hashid}")
        ->assertOk()
        ->assertJsonPath('data.event.series.summary', 'Every day')
        ->assertJsonPath('data.response', 'accepted');
});

test('the app is told plainly why an answer was refused', function () {
    $past = apiInvitation($this->employee, ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);
    $theirs = apiInvitation(Employee::factory()->create());

    $this->postJson("/api/events/{$past->hashid}/respond", ['response' => 'accepted'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This event is over — answers are closed.');
    $this->postJson("/api/events/{$theirs->hashid}/respond", ['response' => 'accepted'])->assertNotFound();
    $this->getJson("/api/events/{$theirs->hashid}")->assertNotFound();
});

test('another company’s event is not found from the app', function () {
    $other = Organization::factory()->create();
    $foreign = app(Tenancy::class)->runFor($other, fn () => Event::create(['title' => 'Theirs', 'type' => 'event', 'starts_at' => now()->addDay()]));
    app(Tenancy::class)->forget();

    $this->getJson("/api/events/{$foreign->hashid}")->assertNotFound();
});

test('the app gets the subscription link, and can reset it', function () {
    $link = $this->getJson('/api/calendar-feed')->assertOk()->json();
    $reset = $this->postJson('/api/calendar-feed/reset')->assertOk()->json();

    expect($link['webcal'])->toStartWith('webcal://')
        ->and($reset['https'])->not->toBe($link['https']);
});

test('without the permission the app gets a 403', function () {
    Sanctum::actingAs(actingAsUserWith(['leave.request']));

    $this->getJson('/api/events')->assertForbidden();
    $this->getJson('/api/calendar-feed')->assertForbidden();
});

test('the session tells the app what this person may do', function () {
    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.can_respond_events', true)
        ->assertJsonPath('user.can_recognize', false);
});
