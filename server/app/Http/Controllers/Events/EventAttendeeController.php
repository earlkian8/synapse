<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventAttendeeRequest;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Manage who is invited to an event: invite one or more employees, remind the
 * ones who have not answered, update an invitee's response, or remove them.
 * Inviting an employee whose account is linked sends them an in-app
 * notification and stamps `notified_at`. The rules live in
 * {@see EventWorkflow}, which the assistant uses too. Thin (route gate
 * `events.manage`).
 */
class EventAttendeeController extends Controller
{
    public function __construct(private readonly EventWorkflow $workflow) {}

    /**
     * Invite a list of employees to the event. Already-invited employees are
     * skipped, so re-inviting is harmless.
     */
    public function store(EventAttendeeRequest $request, Event $event): RedirectResponse
    {
        try {
            $invited = $this->workflow->invite($event, $request->validated()['employee_ids'], $request->user());
        } catch (EventException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond($invited === 1 ? 'Attendee invited.' : "{$invited} attendees invited.");
    }

    /**
     * Re-notify every invitee who has not responded yet (still "invited") and has
     * an active linked account. Refreshes `notified_at` on each reminder sent.
     */
    public function remind(Request $request, Event $event): RedirectResponse
    {
        try {
            $reminded = $this->workflow->remind($event, $request->user());
        } catch (EventException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond($reminded === 1 ? 'Reminder sent to 1 pending invitee.' : "Reminders sent to {$reminded} pending invitees.");
    }

    /**
     * Update an invitee's response. The employee and event never change here.
     */
    public function update(EventAttendeeRequest $request, EventAttendee $attendee): RedirectResponse
    {
        $this->workflow->respond($attendee, $request->validated()['response']);

        return $this->respond('Response updated.');
    }

    /**
     * Remove an invitee from the event.
     */
    public function destroy(EventAttendee $attendee): RedirectResponse
    {
        $this->workflow->remove($attendee);

        return $this->respond('Attendee removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
