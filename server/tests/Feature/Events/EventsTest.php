<?php

use App\Models\Employee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;

/*
| The Events screens' writes, now that they run through EventWorkflow — the
| same path the assistant takes — and the defects that path fixed: form times
| read as UTC, and every invitation to someone with a login throwing.
*/

test('the form’s time is the office wall clock, and saving it back does not move it', function () {
    actingAsSuperAdmin();
    testOrganization()->update(['timezone' => 'Asia/Manila']);

    $this->post(route('events.store'), [
        'title' => 'Town hall',
        'type' => 'event',
        'starts_at' => '2030-03-04T14:00',
        'ends_at' => '2030-03-04T15:00',
    ])->assertRedirect();
    assertToast('success', 'Event scheduled.');

    $event = Event::query()->where('title', 'Town hall')->firstOrFail();

    expect($event->starts_at->utc()->toIso8601String())->toBe('2030-03-04T06:00:00+00:00');

    // What the edit form shows (the wall clock) posted straight back.
    $shown = OrganizationClock::local($event->starts_at)->format('Y-m-d\TH:i');

    $this->post(route('events.update', $event), [
        'title' => 'Town hall',
        'type' => 'event',
        'starts_at' => $shown,
        'ends_at' => OrganizationClock::local($event->ends_at)->format('Y-m-d\TH:i'),
    ])->assertRedirect();

    expect($shown)->toBe('2030-03-04T14:00')
        ->and($event->refresh()->starts_at->utc()->toIso8601String())->toBe('2030-03-04T06:00:00+00:00');
});

test('inviting someone with a login notifies them — and skips who is already invited', function () {
    Notification::fake();
    actingAsSuperAdmin();
    $event = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addDays(3)]);
    $user = User::factory()->create(['is_active' => true]);
    $linked = Employee::factory()->create(['user_id' => $user->id]);
    $unlinked = Employee::factory()->create();

    $this->post(route('events.attendees.store', $event), ['employee_ids' => [$linked->id, $unlinked->id]])->assertRedirect();
    assertToast('success', '2 attendees invited.');

    Notification::assertSentTo($user, SystemNotification::class);

    expect($event->attendees()->where('employee_id', $linked->id)->value('notified_at'))->not->toBeNull();

    $this->post(route('events.attendees.store', $event), ['employee_ids' => [$linked->id]])->assertRedirect();
    assertToast('warning', 'Those employees are already invited.');

    $this->post(route('events.remind', $event))->assertRedirect();
    assertToast('success', 'Reminder sent to 1 pending invitee.');
});

test('another workspace’s employee cannot be invited', function () {
    actingAsSuperAdmin();
    $event = Event::create(['title' => 'Town hall', 'type' => 'event', 'starts_at' => now()->addDays(3)]);
    $stranger = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Employee::factory()->create());

    $this->post(route('events.attendees.store', $event), ['employee_ids' => [$stranger->id]])
        ->assertSessionHasErrors('employee_ids.0');

    expect($event->attendees()->count())->toBe(0);
});

test('reminding about an event that is over is refused', function () {
    actingAsSuperAdmin();
    $event = Event::create(['title' => 'Old meeting', 'type' => 'meeting', 'starts_at' => now()->subDays(3)]);

    $this->post(route('events.remind', $event))->assertRedirect();
    assertToast('warning', 'This event is over');
});

test('the calendar download works, and a title cannot start a property of its own', function () {
    actingAsSuperAdmin();
    $event = Event::create([
        'title' => "Town hall\rATTENDEE:mailto:someone@example.com",
        'type' => 'event',
        'starts_at' => '2030-03-04 06:00:00',
    ]);

    $body = $this->get(route('events.ics', $event))->assertOk()->getContent();

    expect($body)->toContain('DTSTART:20300304T060000Z')
        ->and($body)->toContain('SUMMARY:Town hall\nATTENDEE:mailto:someone@example.com')
        ->and(preg_match('/^ATTENDEE:/m', str_replace("\r", "\n", $body)))->toBe(0);
});
