<?php

namespace App\Support\Events;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventSeries;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes an event or who is invited to it, in one place:
 * schedule (once, or as a repeating series), edit and archive an event; invite
 * people, remind the ones who have not answered, record a response, let an
 * invitee answer for themselves, take somebody off the list, and send the
 * reminders that go out on their own.
 *
 * The Events screens, the mobile app and the assistant all come through here,
 * so an invitation notifies the same people, and is refused for the same
 * reasons — in the same words ({@see EventException}) — however it was asked
 * for.
 *
 * **Times are the organisation's wall clock** (ADR 0036). A form's
 * `datetime-local` value, or "Friday 2pm" resolved by the assistant, arrives
 * without a zone; it means 2pm in the office, and it is stored as the UTC
 * instant that is. Read without a zone it would have been 2pm UTC — 10pm in
 * Manila — and every save of the edit form would have moved it again.
 *
 * **Series** (ADR 0070) are ordinary events sharing a `series_id`. An edit,
 * archive or invitation made to one occurrence covers it alone (`this`) or it
 * and every later one (`following`). **Rooms** are held by live events only,
 * never two at once ({@see RoomBooking}).
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class EventWorkflow
{
    /** The columns a form or the assistant may set. */
    private const FIELDS = ['title', 'description', 'type', 'starts_at', 'ends_at', 'location', 'room_id', 'reminder_minutes'];

    public function __construct(private readonly RoomBooking $rooms) {}

    /**
     * Schedule an event, or a series when `repeat` carries a rule
     * ({@see Recurrence}). Returns the first (or only) occurrence.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws EventException
     */
    public function schedule(array $data, User $organizer, string $channel = ''): Event
    {
        return DB::transaction(function () use ($data, $organizer, $channel): Event {
            $fields = $this->onTheClock(Arr::only($data, self::FIELDS));
            $repeat = $data['repeat'] ?? null;
            $windows = is_array($repeat) && filled($repeat['frequency'] ?? null)
                ? $this->seriesWindows($fields, $repeat)
                : [['starts_at' => $fields['starts_at'], 'ends_at' => $fields['ends_at'] ?? null]];

            if (filled($fields['room_id'] ?? null)) {
                $this->rooms->assertBookable((int) $fields['room_id'], $windows);
            }

            $series = count($windows) > 1 || is_array($repeat) && filled($repeat['frequency'] ?? null)
                ? EventSeries::create([
                    'frequency' => $repeat['frequency'],
                    'interval' => max(1, (int) ($repeat['interval'] ?? 1)),
                    'weekdays' => $repeat['frequency'] === 'weekly'
                        ? collect($repeat['weekdays'] ?? [])->push(OrganizationClock::local($fields['starts_at'])->dayOfWeekIso)
                            ->map(fn ($day): int => (int) $day)->unique()->sort()->values()->all()
                        : null,
                    'until' => $repeat['until'] ?? null,
                    'count' => $repeat['count'] ?? null,
                    'created_by' => $organizer->id,
                ])
                : null;

            $first = null;

            foreach ($windows as $window) {
                $event = Event::create([
                    ...$fields,
                    ...$window,
                    'organizer_id' => $organizer->id,
                    'series_id' => $series?->id,
                ]);

                $first ??= $event;
            }

            $dates = count($windows);

            ActivityLogger::log(
                event: 'created',
                description: "Scheduled {$first->type} \"{$first->title}\"".($series ? " — {$dates} dates, ".lcfirst($series->summary()) : '').$channel,
                subject: $first,
                logName: 'events',
                subjectLabel: $first->title,
            );

            return $first;
        });
    }

    /**
     * Edit an event — alone, or with every later occurrence of its series.
     * "Following" moves each later date by the same wall-clock shift as this one
     * (whole days, then the new time of day) and gives each the same length.
     *
     * Moving the start re-arms the automatic reminder.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws EventException
     */
    public function update(Event $event, array $data, string $channel = '', string $scope = 'this'): Event
    {
        return DB::transaction(function () use ($event, $data, $channel, $scope): Event {
            $fields = $this->onTheClock(Arr::only($data, self::FIELDS));
            $targets = $this->targets($event, $scope);
            $windows = $this->shiftedWindows($event, $targets, $fields);
            $roomId = array_key_exists('room_id', $fields) ? $fields['room_id'] : $event->room_id;

            if (filled($roomId)) {
                $this->rooms->assertBookable(
                    (int) $roomId,
                    array_values($windows),
                    $targets->pluck('id')->all(),
                    alreadyHeld: (int) $event->room_id === (int) $roomId,
                );
            }

            foreach ($targets as $target) {
                $window = $windows[$target->id];
                $moved = ! $target->starts_at->equalTo($window['starts_at']);

                $target->update([
                    ...Arr::except($fields, ['starts_at', 'ends_at']),
                    ...$window,
                    ...($moved ? ['reminder_sent_at' => null] : []),
                ]);
            }

            $later = $targets->count() - 1;

            ActivityLogger::log(
                event: 'updated',
                description: "Updated {$event->type} \"{$event->title}\"".($later > 0 ? " and {$later} later ".str('date')->plural($later) : '').$channel,
                subject: $event,
                logName: 'events',
                subjectLabel: $event->title,
            );

            return $event->refresh();
        });
    }

    /**
     * Archive (soft delete) an event, or it and every later occurrence. Its
     * invitations stay, so restoring brings them back; its room is free
     * meanwhile.
     *
     * @return int How many were archived.
     */
    public function archive(Event $event, string $channel = '', string $scope = 'this'): int
    {
        $targets = $this->targets($event, $scope);
        $title = $event->title;

        $targets->each(fn (Event $target) => $target->delete());

        $later = $targets->count() - 1;

        ActivityLogger::log(
            event: 'archived',
            description: "Archived event \"{$title}\"".($later > 0 ? " and {$later} later ".str('date')->plural($later) : '').$channel,
            logName: 'events',
            subjectLabel: $title,
        );

        return $targets->count();
    }

    /**
     * Bring an archived event back — unless its room was booked meanwhile.
     *
     * @throws EventException
     */
    public function restore(Event $event, string $channel = ''): void
    {
        DB::transaction(function () use ($event): void {
            if ($event->room_id !== null) {
                $this->rooms->assertBookable(
                    $event->room_id,
                    [['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]],
                    [$event->id],
                    alreadyHeld: true,
                );
            }

            $event->restore();
        });

        ActivityLogger::log(
            event: 'restored',
            description: "Restored event \"{$event->title}\"{$channel}",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );
    }

    /**
     * Invite employees to an event, or to it and every later occurrence. Anyone
     * already invited is skipped, so re-inviting is harmless; everyone with an
     * active linked account is told once, however many dates it covers.
     *
     * @param  iterable<int>  $employeeIds
     * @return int How many people were invited.
     *
     * @throws EventException
     */
    public function invite(Event $event, iterable $employeeIds, ?User $actor, string $channel = '', string $scope = 'this'): int
    {
        $events = $this->targets($event, $scope);
        $ids = array_values(array_unique(array_map('intval', is_array($employeeIds) ? $employeeIds : iterator_to_array($employeeIds))));

        // The tenant scope keeps this to the current organisation's people.
        $employees = $ids === [] ? collect() : Employee::query()->whereIn('id', $ids)->with('user')->get();
        $invited = EventAttendee::query()
            ->whereIn('event_id', $events->pluck('id'))
            ->get(['event_id', 'employee_id'])
            ->map(fn (EventAttendee $row): string => $row->event_id.'|'.$row->employee_id)
            ->flip();

        $people = 0;

        foreach ($employees as $employee) {
            $missing = $events->reject(fn (Event $occurrence): bool => $invited->has($occurrence->id.'|'.$employee->id));

            if ($missing->isEmpty()) {
                continue;
            }

            $notifiedAt = $this->notify($missing->first(), $employee, $actor, dates: $missing->count());

            foreach ($missing as $occurrence) {
                $occurrence->attendees()->create([
                    'employee_id' => $employee->id,
                    'response' => 'invited',
                    'notified_at' => $notifiedAt,
                ]);
            }

            $people++;
        }

        if ($people === 0) {
            throw new EventException('Those employees are already invited.');
        }

        $dates = $events->count();

        ActivityLogger::log(
            event: 'created',
            description: "Invited {$people} ".str('attendee')->plural($people)." to \"{$event->title}\"".($dates > 1 ? " ({$dates} dates)" : '').$channel,
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $people;
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
     * Record an invitee's response on their behalf (HR). The employee and event
     * never change here.
     */
    public function respond(EventAttendee $attendee, string $response, string $channel = ''): void
    {
        $attendee->update([
            'response' => $response,
            'responded_at' => $response === 'invited' ? null : now(),
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated an event attendee response'.$channel,
            subject: $attendee->event,
            logName: 'events',
            subjectLabel: $attendee->event?->title,
        );
    }

    /**
     * An invitee answers for themselves: accepted, tentative or declined, while
     * the event is not over — for this date, or this and every later date of
     * the series they are invited to.
     *
     * @return int How many invitations were answered.
     *
     * @throws EventException
     */
    public function respondAsInvitee(Event $event, Employee $employee, string $response, string $scope = 'this', string $channel = ''): int
    {
        if (! in_array($response, EventAttendee::ANSWERS, true)) {
            throw new EventException('Answer with accepted, tentative or declined.');
        }

        if ($event->trashed()) {
            throw new EventException('This event was called off.');
        }

        if ($event->status() === 'past') {
            throw new EventException('This event is over — answers are closed.');
        }

        $own = $event->attendees()->where('employee_id', $employee->id)->first();

        if ($own === null) {
            throw new EventException('You’re not invited to this event.');
        }

        $rows = $scope === 'following' && $event->series_id !== null
            ? EventAttendee::query()
                ->where('employee_id', $employee->id)
                ->whereHas('event', fn ($query) => $query
                    ->where('series_id', $event->series_id)
                    ->where('starts_at', '>=', $event->starts_at))
                ->get()
            : collect([$own]);

        foreach ($rows as $row) {
            $row->update(['response' => $response, 'responded_at' => now()]);
        }

        $later = $rows->count() - 1;

        ActivityLogger::log(
            event: 'updated',
            description: "{$employee->full_name} answered “{$response}” to \"{$event->title}\"".($later > 0 ? " and {$later} later ".str('date')->plural($later) : '').$channel,
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $rows->count();
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
     * Send the automatic reminders that are due in the bound organisation: for
     * every live event whose `reminder_minutes` before the start has come, to
     * every invitee who has not declined and can sign in. Each event's reminder
     * goes once (`reminder_sent_at`), however often this runs.
     *
     * @return int How many people were reminded.
     */
    public function sendDueReminders(): int
    {
        $now = CarbonImmutable::now();

        $due = Event::query()
            ->whereNotNull('reminder_minutes')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addMinutes(max(Event::REMINDER_CHOICES)))
            ->with('room:id,name')
            ->get()
            ->filter(fn (Event $event): bool => $event->starts_at->subMinutes($event->reminder_minutes)->lte($now));

        $sent = 0;

        foreach ($due as $event) {
            $reminded = 0;

            $attendees = $event->attendees()
                ->where('response', '!=', 'declined')
                ->with('employee.user')
                ->get();

            foreach ($attendees as $attendee) {
                $user = $attendee->employee?->user;

                if ($user === null || ! $user->is_active) {
                    continue;
                }

                $reminded += Notifier::toUser(
                    user: $user,
                    title: "Starting soon: {$event->title}",
                    body: implode(' · ', array_filter([
                        'Starts '.$this->leadTime((int) $now->diffInMinutes($event->starts_at, true)),
                        $event->room?->name ?? $event->location,
                        OrganizationClock::local($event->starts_at)->format('M j, g:i A'),
                    ])),
                    url: '/events/me?event='.$event->hashid,
                    level: 'info',
                    category: 'events',
                );
            }

            $event->forceFill(['reminder_sent_at' => $now])->save();

            if ($reminded > 0) {
                ActivityLogger::log(
                    event: 'updated',
                    description: "Reminded {$reminded} ".str('invitee')->plural($reminded)." about \"{$event->title}\" (automatic)",
                    subject: $event,
                    logName: 'events',
                    subjectLabel: $event->title,
                );
            }

            $sent += $reminded;
        }

        return $sent;
    }

    /**
     * The event alone, or it and every later live occurrence of its series.
     *
     * @return Collection<int, Event>
     */
    private function targets(Event $event, string $scope): Collection
    {
        if ($scope !== 'following' || $event->series_id === null) {
            return collect([$event]);
        }

        return Event::query()
            ->where('series_id', $event->series_id)
            ->where('starts_at', '>=', $event->starts_at)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * The dates of a new series, from the first window and its rule.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $rule
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable|null}>
     *
     * @throws EventException
     */
    private function seriesWindows(array $fields, array $rule): array
    {
        $start = OrganizationClock::local($fields['starts_at']);
        $end = $fields['ends_at'] ?? null;

        return Recurrence::occurrences($start, $end === null ? null : (int) $fields['starts_at']->diffInMinutes($end), $rule);
    }

    /**
     * Each target's new window. The edited event takes the times given; every
     * later one is moved by the same whole days, to the new time of day, with
     * the new length. A field left out keeps what the event has.
     *
     * @param  Collection<int, Event>  $targets
     * @param  array<string, mixed>  $fields
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable|null}>
     */
    private function shiftedWindows(Event $event, Collection $targets, array $fields): array
    {
        $start = CarbonImmutable::instance($fields['starts_at'] ?? $event->starts_at);
        $end = array_key_exists('ends_at', $fields) ? $fields['ends_at'] : $event->ends_at;
        $duration = $end === null ? null : (int) $start->diffInMinutes($end);

        $before = OrganizationClock::local($event->starts_at);
        $after = OrganizationClock::local($start);
        $days = (int) $before->startOfDay()->diffInDays($after->startOfDay(), false);
        $time = $after->format('H:i:s');

        $windows = [];

        foreach ($targets as $target) {
            $begins = $target->is($event)
                ? $start->utc()
                : CarbonImmutable::parse(OrganizationClock::local($target->starts_at)->addDays($days)->toDateString().' '.$time, $after->getTimezone())->utc();

            $windows[$target->id] = [
                'starts_at' => $begins,
                'ends_at' => $duration === null ? null : $begins->addMinutes($duration),
            ];
        }

        return $windows;
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
                $data[$key] = CarbonImmutable::instance($value)->utc();
            } elseif (array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /** "in 10 minutes", "in 1 hour", "in 2 days". */
    private function leadTime(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => 'in '.max(1, $minutes).' '.str('minute')->plural(max(1, $minutes)),
            $minutes < 1440 => 'in '.intdiv($minutes + 30, 60).' '.str('hour')->plural(intdiv($minutes + 30, 60)),
            default => 'in '.intdiv($minutes + 720, 1440).' '.str('day')->plural(intdiv($minutes + 720, 1440)),
        };
    }

    /**
     * Notify an invitee's linked account, if any, and return the moment it was
     * sent (null when the employee has no active login). Best-effort — a
     * delivery problem never blocks the invite. An invitation to several dates
     * of a series is one notice.
     */
    private function notify(Event $event, Employee $employee, ?User $actor, bool $reminder = false, int $dates = 1): ?CarbonInterface
    {
        $user = $employee->user;

        if (! $user || ! $user->is_active) {
            return null;
        }

        $when = $event->starts_at ? OrganizationClock::local($event->starts_at)->format('M j, Y g:i A') : null;

        Notifier::toUser(
            user: $user,
            title: ($reminder ? 'Reminder — please respond: ' : "You're invited: ").$event->title,
            body: implode(' · ', array_filter([
                $event->room?->name ?? $event->location,
                $dates > 1 ? "{$dates} dates from {$when}" : $when,
            ])),
            url: '/events/me?event='.$event->hashid,
            level: 'info',
            category: 'events',
            actor: $actor,
        );

        return now();
    }
}
