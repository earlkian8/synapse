<?php

namespace App\Support\Events;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Carbon\CarbonInterface;

/**
 * Everything that changes an event or who is invited to it, in one place:
 * schedule, edit and archive an event; invite people, remind the ones who have
 * not answered, record a response, and take somebody off the list.
 *
 * The Events screens and the assistant both come through here, so an
 * invitation notifies the same people, and is refused for the same reasons —
 * in the same words ({@see EventException}) — however it was asked for.
 *
 * **Times are the organisation's wall clock** (ADR 0036). A form's
 * `datetime-local` value, or "Friday 2pm" resolved by the assistant, arrives
 * without a zone; it means 2pm in the office, and it is stored as the UTC
 * instant that is. Read without a zone it would have been 2pm UTC — 10pm in
 * Manila — and every save of the edit form would have moved it again.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class EventWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(array $data, User $organizer, string $channel = ''): Event
    {
        $event = Event::create([
            ...$this->onTheClock($data),
            'organizer_id' => $organizer->id,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Scheduled {$event->type} \"{$event->title}\"{$channel}",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data, string $channel = ''): Event
    {
        $event->update($this->onTheClock($data));

        ActivityLogger::log(
            event: 'updated',
            description: "Updated {$event->type} \"{$event->title}\"{$channel}",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $event;
    }

    /**
     * Archive (soft delete) an event. Its invitations stay, so restoring it
     * brings them back.
     */
    public function archive(Event $event, string $channel = ''): void
    {
        $title = $event->title;
        $event->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived event \"{$title}\"{$channel}",
            logName: 'events',
            subjectLabel: $title,
        );
    }

    /**
     * Invite employees. Anyone already invited is skipped, so re-inviting is
     * harmless; everyone with an active linked account is notified.
     *
     * @param  iterable<int>  $employeeIds
     * @return int How many were invited.
     *
     * @throws EventException
     */
    public function invite(Event $event, iterable $employeeIds, ?User $actor, string $channel = ''): int
    {
        $alreadyInvited = $event->attendees()->pluck('employee_id')->all();
        $wanted = array_values(array_diff(
            array_map('intval', is_array($employeeIds) ? $employeeIds : iterator_to_array($employeeIds)),
            $alreadyInvited,
        ));

        // The tenant scope keeps this to the current organisation's people.
        $employees = $wanted === []
            ? collect()
            : Employee::query()->whereIn('id', $wanted)->with('user')->get();

        if ($employees->isEmpty()) {
            throw new EventException('Those employees are already invited.');
        }

        foreach ($employees as $employee) {
            $event->attendees()->create([
                'employee_id' => $employee->id,
                'response' => 'invited',
                'notified_at' => $this->notify($event, $employee, $actor),
            ]);
        }

        $invited = $employees->count();

        ActivityLogger::log(
            event: 'created',
            description: "Invited {$invited} ".str('attendee')->plural($invited)." to \"{$event->title}\"{$channel}",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $invited;
    }

    /**
     * Re-notify every invitee who has not answered yet and has an active linked
     * account, refreshing `notified_at` on each reminder sent.
     *
     * @return int How many were reminded.
     *
     * @throws EventException
     */
    public function remind(Event $event, ?User $actor, string $channel = ''): int
    {
        if ($event->status() === 'past') {
            throw new EventException('This event is over — no reminders sent.');
        }

        $pending = $event->attendees()
            ->where('response', 'invited')
            ->with('employee.user')
            ->get();

        if ($pending->isEmpty()) {
            throw new EventException('Everyone has already responded.');
        }

        $reminded = 0;

        foreach ($pending as $attendee) {
            $employee = $attendee->employee;

            if (! $employee || $this->notify($event, $employee, $actor, reminder: true) === null) {
                continue;
            }

            $attendee->update(['notified_at' => now()]);
            $reminded++;
        }

        if ($reminded === 0) {
            throw new EventException('No pending invitee has an active account to remind.');
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Reminded {$reminded} pending ".str('invitee')->plural($reminded)." about \"{$event->title}\"{$channel}",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $reminded;
    }

    /**
     * Record an invitee's response. The employee and event never change here.
     */
    public function respond(EventAttendee $attendee, string $response, string $channel = ''): void
    {
        $attendee->update(['response' => $response]);

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated an event attendee response'.$channel,
            subject: $attendee->event,
            logName: 'events',
            subjectLabel: $attendee->event?->title,
        );
    }

    /**
     * Take an invitee off the list.
     */
    public function remove(EventAttendee $attendee, string $channel = ''): void
    {
        $event = $attendee->event;
        $attendee->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed an event attendee'.$channel,
            subject: $event,
            logName: 'events',
            subjectLabel: $event?->title,
        );
    }

    /**
     * Read a zone-less start or end as the organisation's wall clock. A value
     * that is already an instant — a Carbon, or a string with an offset — keeps
     * the moment it names.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function onTheClock(array $data): array
    {
        foreach (['starts_at', 'ends_at'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $data[$key] = OrganizationClock::parse($value);
            } elseif ($value instanceof CarbonInterface) {
                $data[$key] = $value->clone()->utc();
            }
        }

        return $data;
    }

    /**
     * Notify an invitee's linked account, if any, and return the moment it was
     * sent (null when the employee has no active login). Best-effort — a
     * delivery problem never blocks the invite.
     */
    private function notify(Event $event, Employee $employee, ?User $actor, bool $reminder = false): ?CarbonInterface
    {
        $user = $employee->user;

        if (! $user || ! $user->is_active) {
            return null;
        }

        Notifier::toUser(
            user: $user,
            title: ($reminder ? 'Reminder — please respond: ' : "You're invited: ").$event->title,
            body: trim(($event->location ? "{$event->location} · " : '').($event->starts_at
                ? OrganizationClock::local($event->starts_at)->format('M j, Y g:i A')
                : '')),
            url: '/events/'.$event->hashid,
            level: 'info',
            category: 'events',
            actor: $actor,
        );

        return now();
    }
}
