<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventAttendeeRequest;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Manage who is invited to an event: invite one or more employees, update an
 * invitee's response, or remove them. Inviting an employee whose account is linked
 * sends them an in-app notification and stamps `notified_at`. Thin (route gate
 * `events.manage`).
 */
class EventAttendeeController extends Controller
{
    /**
     * Invite a list of employees to the event. Already-invited employees are
     * skipped, so re-inviting is harmless.
     */
    public function store(EventAttendeeRequest $request, Event $event): RedirectResponse
    {
        $alreadyInvited = $event->attendees()->pluck('employee_id')->all();
        $employeeIds = array_values(array_diff($request->validated()['employee_ids'], $alreadyInvited));

        if ($employeeIds === []) {
            return $this->respond('Those employees are already invited.', 'warning');
        }

        $invited = 0;

        foreach (Employee::query()->whereIn('id', $employeeIds)->with('user')->get() as $employee) {
            $notifiedAt = $this->notify($event, $employee);

            $event->attendees()->create([
                'employee_id' => $employee->id,
                'response' => 'invited',
                'notified_at' => $notifiedAt,
            ]);

            $invited++;
        }

        ActivityLogger::log(
            event: 'created',
            description: "Invited {$invited} ".str('attendee')->plural($invited)." to \"{$event->title}\"",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $this->respond($invited === 1 ? 'Attendee invited.' : "{$invited} attendees invited.");
    }

    /**
     * Re-notify every invitee who has not responded yet (still "invited") and has
     * an active linked account. Refreshes `notified_at` on each reminder sent.
     */
    public function remind(Event $event): RedirectResponse
    {
        if ($event->status() === 'past') {
            return $this->respond('This event is over — no reminders sent.', 'warning');
        }

        $pending = $event->attendees()
            ->where('response', 'invited')
            ->with('employee.user')
            ->get();

        if ($pending->isEmpty()) {
            return $this->respond('Everyone has already responded.', 'warning');
        }

        $reminded = 0;

        foreach ($pending as $attendee) {
            $employee = $attendee->employee;

            if (! $employee || $this->notify($event, $employee, reminder: true) === null) {
                continue;
            }

            $attendee->update(['notified_at' => now()]);
            $reminded++;
        }

        if ($reminded === 0) {
            return $this->respond('No pending invitee has an active account to remind.', 'warning');
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Reminded {$reminded} pending ".str('invitee')->plural($reminded)." about \"{$event->title}\"",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $this->respond($reminded === 1 ? 'Reminder sent to 1 pending invitee.' : "Reminders sent to {$reminded} pending invitees.");
    }

    /**
     * Update an invitee's response. The employee and event never change here.
     */
    public function update(EventAttendeeRequest $request, EventAttendee $attendee): RedirectResponse
    {
        $attendee->update(['response' => $request->validated()['response']]);

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated an event attendee response',
            subject: $attendee->event,
            logName: 'events',
            subjectLabel: $attendee->event?->title,
        );

        return $this->respond('Response updated.');
    }

    /**
     * Remove an invitee from the event.
     */
    public function destroy(EventAttendee $attendee): RedirectResponse
    {
        $event = $attendee->event;
        $attendee->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed an event attendee',
            subject: $event,
            logName: 'events',
            subjectLabel: $event?->title,
        );

        return $this->respond('Attendee removed.');
    }

    /**
     * Notify an invitee's linked account, if any, and return the timestamp it was
     * sent (null when the employee has no active login). Best-effort — a delivery
     * problem never blocks the invite.
     */
    private function notify(Event $event, Employee $employee, bool $reminder = false): ?Carbon
    {
        $user = $employee->user;

        if (! $user || ! $user->is_active) {
            return null;
        }

        Notifier::toUser(
            user: $user,
            title: ($reminder ? 'Reminder — please respond: ' : "You're invited: ").$event->title,
            body: trim(($event->location ? "{$event->location} · " : '').$event->starts_at?->format('M j, Y g:i A')),
            url: '/events/'.$event->hashid,
            level: 'info',
            category: 'events',
            actor: request()->user(),
        );

        return now();
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
