<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Events\EventRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Events capability: the company calendar of events and meetings, and who is
 * coming.
 *
 * **Reading** answers "what's on this week?", "who hasn't replied to the town
 * hall?", "what is Maria invited to?". **Doing** schedules, edits and archives
 * events and manages their guest lists through {@see EventWorkflow}, the path
 * the screens take — so an invitation notifies exactly who the screen's would,
 * and times mean the office's wall clock, as they do on the screen.
 *
 * Disclosure follows the screens: `events.view` to read, `events.manage` to
 * change anything; there is no self-service RSVP. **Anything that notifies
 * people waits for the user's Confirm** — inviting and reminding reach real
 * inboxes, and a steered model must not be able to send them (ADR 0049) — as do
 * archiving an event and taking somebody off its list.
 *
 * Events resolve by title to exactly one, or not at all: a recurring
 * "Weekly standup" is told apart by its date.
 */
class EventsModule extends Module implements ContributesContext, ContributesTopicContext
{
    /** How many results a list returns. */
    private const MAX_RESULTS = 15;

    /** How many people one invite may name. */
    private const MAX_NAMED = 25;

    /** How many departments one invite may name. */
    private const MAX_DEPARTMENTS = 5;

    /** The most people one invite may reach — beyond it, the screen. */
    private const MAX_INVITEES = 200;

    /** How many names an event read-out spells out per group. */
    private const MAX_NAMES = 10;

    /** How many of a person's invitations their brief lists. */
    private const CONTEXT_INVITATIONS = 5;

    public function __construct(private readonly EventWorkflow $workflow) {}

    public function key(): string
    {
        return 'events';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('events.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_events' => 'findEvents',
            'get_event' => 'getEvent',
            'schedule_event' => 'schedule',
            'update_event' => 'updateEvent',
            'invite_to_event' => 'invite',
            'remind_event_invitees' => 'remind',
            'set_event_response' => 'respond',
            'remove_event_attendee' => 'removeAttendee',
            'archive_event' => 'archiveEvent',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_events' => 'events.view',
            'get_event' => 'events.view',
            'schedule_event' => 'events.manage',
            'update_event' => 'events.manage',
            'invite_to_event' => 'events.manage',
            'remind_event_invitees' => 'events.manage',
            'set_event_response' => 'events.manage',
            'remove_event_attendee' => 'events.manage',
            'archive_event' => 'events.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // Inviting and reminding send notifications to real people; the other
        // two take an event, or a person, off the calendar.
        return ['invite_to_event', 'remind_event_invitees', 'remove_event_attendee', 'archive_event'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'events.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'events.manage' ? 'change events' : 'view events');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $zone = OrganizationClock::timezone();
        $now = OrganizationClock::now()->format('D Y-m-d H:i');

        $manage = $this->allows($user, 'events.manage')
            ? <<<'TXT'

            - schedule_event creates an event or meeting; update_event changes one (only the fields given); set_event_response records an invitee's answer.
            - invite_to_event invites people by name (up to 25) and/or whole departments; remind_event_invitees nudges everyone who has not replied. Both NOTIFY people, so they wait for the user's confirmation — as do remove_event_attendee and archive_event.
            TXT
            : '';

        return <<<TXT
        EVENTS — the company's events and meetings, and who is invited to each (response: invited, accepted, tentative or declined). An event is upcoming, ongoing or past by its times.
        - find_events lists events (upcoming by default; by title, kind, status, a window of days ahead, or who is invited); get_event reads one — when, where, who organises it, and who has accepted, declined or not replied.
        - Times are the office's wall clock ({$zone}; it is now {$now}). Pass them as "YYYY-MM-DD HH:MM" in 24-hour time, resolving words like "Friday 2pm" yourself. Identify an event by its title, plus its date (YYYY-MM-DD) when several share a title.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $event = ['type' => 'STRING', 'description' => 'The event title.'];
        $date = ['type' => 'STRING', 'description' => 'The event\'s date, YYYY-MM-DD — needed when several events share the title.'];
        $employee = ['type' => 'STRING', 'description' => 'Employee name or employee number.'];
        $fields = [
            'type' => ['type' => 'STRING', 'enum' => Event::TYPES, 'description' => 'An event or a meeting.'],
            'starts_at' => ['type' => 'STRING', 'description' => 'Start, "YYYY-MM-DD HH:MM" on the office clock.'],
            'ends_at' => ['type' => 'STRING', 'description' => 'End, "YYYY-MM-DD HH:MM" on the office clock.'],
            'location' => ['type' => 'STRING', 'description' => 'Where it happens (a room, an address or a call link name).'],
            'description' => ['type' => 'STRING', 'description' => 'What it is about.'],
        ];

        return $this->permitted($user, [
            [
                'name' => 'find_events',
                'description' => 'List events and meetings. Without a status or title, lists what is upcoming or happening now, soonest first.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the title or location.'],
                        'type' => ['type' => 'STRING', 'enum' => Event::TYPES],
                        'status' => ['type' => 'STRING', 'enum' => Event::STATUSES],
                        'days' => ['type' => 'INTEGER', 'description' => 'Only events starting within this many days from now (1–90).'],
                        'attendee' => ['type' => 'STRING', 'description' => 'Only events this employee is invited to (name or employee number).'],
                    ],
                ],
            ],
            [
                'name' => 'get_event',
                'description' => 'Read one event: when, where, organiser, description, and who accepted, is tentative, declined or has not replied.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['event' => $event, 'date' => $date],
                    'required' => ['event'],
                ],
            ],
            [
                'name' => 'schedule_event',
                'description' => 'Schedule a new event or meeting. The signed-in user becomes its organiser. Nobody is invited yet.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['title' => ['type' => 'STRING', 'description' => 'The title.'], ...$fields],
                    'required' => ['title', 'type', 'starts_at'],
                ],
            ],
            [
                'name' => 'update_event',
                'description' => 'Change an event: its title, kind, times, location or description. Only the fields given change.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'event' => $event,
                        'date' => $date,
                        'new_title' => ['type' => 'STRING', 'description' => 'A new title.'],
                        ...$fields,
                    ],
                    'required' => ['event'],
                ],
            ],
            [
                'name' => 'invite_to_event',
                'description' => 'Invite people to an event — by name, by department, or both. Anyone already invited is skipped. Invitees with an account are notified.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'event' => $event,
                        'date' => $date,
                        'employees' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Names or employee numbers, at most 25.'],
                        'departments' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Invite every active employee of these departments, by name.'],
                    ],
                    'required' => ['event'],
                ],
            ],
            [
                'name' => 'remind_event_invitees',
                'description' => 'Send a reminder to every invitee who has not replied yet.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['event' => $event, 'date' => $date],
                    'required' => ['event'],
                ],
            ],
            [
                'name' => 'set_event_response',
                'description' => "Record an invitee's response.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'event' => $event,
                        'date' => $date,
                        'employee' => $employee,
                        'response' => ['type' => 'STRING', 'enum' => EventAttendee::RESPONSES],
                    ],
                    'required' => ['event', 'employee', 'response'],
                ],
            ],
            [
                'name' => 'remove_event_attendee',
                'description' => "Take someone off an event's guest list.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['event' => $event, 'date' => $date, 'employee' => $employee],
                    'required' => ['event', 'employee'],
                ],
            ],
            [
                'name' => 'archive_event',
                'description' => 'Archive an event. It can be restored from the Events screen.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['event' => $event, 'date' => $date],
                    'required' => ['event'],
                ],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * A person's calendar: the events they are invited to next, and whether
     * they have answered.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        // No self-service exception: there is no "my events" view to mirror.
        if ($employee === null || $user->cannot('events.view')) {
            return null;
        }

        $invitations = EventAttendee::query()
            ->where('employee_id', $employee->id)
            ->whereHas('event', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('starts_at', '>=', now())
                ->orWhere('ends_at', '>=', now())))
            ->with('event')
            ->get()
            ->sortBy(fn (EventAttendee $a): string => $a->event?->starts_at?->toIso8601String() ?? '')
            ->values();

        if ($invitations->isEmpty()) {
            return ContextSection::of('Events', ['No upcoming events or meetings on their calendar.']);
        }

        $unanswered = $invitations->where('response', 'invited')->count();

        return ContextSection::of('Events', [
            sprintf(
                'Invited to %d upcoming %s; %d not replied yet.',
                $invitations->count(),
                Str::plural('event', $invitations->count()),
                $unanswered,
            ),
            ...$invitations->take(self::CONTEXT_INVITATIONS)->map(fn (EventAttendee $a): string => sprintf(
                '%s (%s) %s — %s.',
                $a->event?->title ?? 'An event',
                $a->event?->type ?? 'event',
                $a->event ? $this->when($a->event) : '',
                $this->responseText($a->response),
            ))->all(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'event', 'events', 'meeting', 'meetings', 'town hall', 'townhall', 'all-hands', 'all hands',
            'offsite', 'team building', 'teambuilding', 'party', 'outing', 'rsvp', 'rsvps', 'invite', 'invites',
            'invitation', 'invitations', 'invited', 'calendar', 'pulong', 'pagpupulong',
        ];
    }

    /**
     * The calendar: what is on now, what is coming in the next month, and how
     * the replies are going.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('events.view')) {
            return null;
        }

        $current = $this->withCounts(Event::query())
            ->where(fn (Builder $q) => $q->where('starts_at', '>=', now())->orWhere('ends_at', '>=', now()))
            ->where('starts_at', '<=', now()->addDays(30))
            ->chronological()
            ->limit(20)
            ->get();

        if ($current->isEmpty()) {
            $last = Event::query()->where('starts_at', '<', now())->recentFirst()->first();

            return ContextSection::of('Events', [
                'Nothing is scheduled for the next 30 days.',
                $last !== null ? "The last one was {$last->title} ({$this->when($last)})." : null,
            ]);
        }

        $ongoing = $current->filter(fn (Event $e): bool => $e->status() === 'ongoing');
        $upcoming = $current->filter(fn (Event $e): bool => $e->status() === 'upcoming');

        return ContextSection::of('Events', [
            sprintf(
                'Next 30 days: %d %s (%d %s)%s.',
                $upcoming->count(),
                Str::plural('event', $upcoming->count()),
                $upcoming->where('type', 'meeting')->count(),
                Str::plural('meeting', $upcoming->where('type', 'meeting')->count()),
                $ongoing->isNotEmpty() ? '; happening now: '.$ongoing->map(fn (Event $e): string => $e->title)->implode(', ') : '',
            ),
            ...$upcoming->take(5)->map(fn (Event $e): string => $this->summaryLine($e))->all(),
        ]);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findEvents(User $user, array $args): ToolResult
    {
        $attendee = null;

        if (filled($args['attendee'] ?? null)) {
            [$attendee, $error] = $this->resolveEmployee((string) $args['attendee']);

            if ($attendee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        $query = trim((string) ($args['query'] ?? ''));
        $type = in_array($args['type'] ?? null, Event::TYPES, true) ? $args['type'] : null;
        $status = in_array($args['status'] ?? null, Event::STATUSES, true) ? $args['status'] : null;
        $days = is_numeric($args['days'] ?? null) ? max(1, min(90, (int) $args['days'])) : null;
        $like = Event::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $base = $this->withCounts(Event::query())
            ->when($query !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('title', $like, '%'.addcslashes($query, '%_\\').'%')
                ->orWhere('location', $like, '%'.addcslashes($query, '%_\\').'%')))
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->when($attendee !== null, fn (Builder $q) => $q->whereHas('attendees', fn (Builder $a) => $a->where('employee_id', $attendee->id)))
            ->when($days !== null, fn (Builder $q) => $q->where('starts_at', '>=', now())->where('starts_at', '<=', now()->addDays($days)));

        // Past events read newest first; everything else soonest first. With no
        // filter at all, the calendar ahead — what people mean by "events".
        $events = match (true) {
            $status === 'past' => (clone $base)->where(fn (Builder $q) => $q
                ->where('ends_at', '<', now())
                ->orWhere(fn (Builder $w) => $w->whereNull('ends_at')->where('starts_at', '<', now())))
                ->recentFirst()->limit(self::MAX_RESULTS)->get(),
            $status !== null => (clone $base)->chronological()->limit(200)->get()
                ->filter(fn (Event $e): bool => $e->status() === $status)->take(self::MAX_RESULTS),
            $query === '' && $attendee === null => (clone $base)
                ->where(fn (Builder $q) => $q->where('starts_at', '>=', now())->orWhere('ends_at', '>=', now()))
                ->chronological()->limit(self::MAX_RESULTS)->get(),
            default => (clone $base)->chronological()->limit(200)->get()
                ->sortBy(fn (Event $e): array => [$e->status() === 'past' ? 1 : 0, $e->status() === 'past' ? -$e->starts_at?->timestamp : $e->starts_at?->timestamp])
                ->take(self::MAX_RESULTS),
        };

        $cards = $events->map(fn (Event $e): array => $this->eventCard($e, 'find', 'neutral', Str::headline($e->status())))->values()->all();

        return ToolResult::found('Searched events', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getEvent(User $user, array $args): ToolResult
    {
        [$event, $error] = $this->locateEvent($args);

        if ($event === null) {
            return ToolResult::error('Looked up the event', $error);
        }

        $event->loadCount(['attendees', 'attendees as attending_count' => fn (Builder $q) => $q->attending()])
            ->load(['organizer:id,first_name,last_name', 'attendees.employee:id,first_name,middle_name,last_name,suffix']);

        $by = $event->attendees->groupBy('response');
        $names = fn (string $response): ?string => $this->names($by->get($response, collect()));

        $card = $this->eventCard($event, 'insight', 'info', Str::headline($event->status()));
        $card['meta'] = array_values(array_filter([
            $this->tally($event),
            $event->organizer ? 'Organised by '.trim($event->organizer->first_name.' '.$event->organizer->last_name) : null,
            ($n = $names('accepted')) ? 'Accepted: '.$n : null,
            ($n = $names('tentative')) ? 'Tentative: '.$n : null,
            ($n = $names('declined')) ? 'Declined: '.$n : null,
            ($n = $names('invited')) ? 'Not replied: '.$n : null,
            filled($event->description) ? 'About: '.Str::limit((string) $event->description, 240) : null,
        ]));

        return ToolResult::found("Read {$event->title}", $this->when($event), [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function schedule(User $user, array $args): ToolResult
    {
        [$data, $error] = $this->eventFields($args, ['title' => trim((string) ($args['title'] ?? ''))]);

        if ($error !== null) {
            return ToolResult::error('Scheduled the event', $error);
        }

        if (($problem = $this->invalid($data, (new EventRequest)->rules())) !== null) {
            return ToolResult::error('Scheduled the event', $problem);
        }

        // A model that got the year wrong schedules into the past; the screen
        // allows recording past events, but nobody asks a chat to do that.
        if (OrganizationClock::parse((string) $data['starts_at'])->isPast()) {
            return ToolResult::error('Scheduled the event', 'That start time has already passed ('.$data['starts_at'].'; it is now '.OrganizationClock::now()->format('Y-m-d H:i').'). Check the date.');
        }

        $event = $this->workflow->schedule($data, $user, ' via assistant');

        return ToolResult::ok(
            "Scheduled {$event->title}",
            $this->when($event),
            $this->eventCard($this->withCounts(Event::query())->findOrFail($event->id), 'schedule', 'positive', 'Scheduled'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateEvent(User $user, array $args): ToolResult
    {
        [$event, $error] = $this->locateEvent($args);

        if ($event === null) {
            return ToolResult::error('Looked up the event', $error);
        }

        [$changes, $error] = $this->eventFields($args, filled($args['new_title'] ?? null) ? ['title' => trim((string) $args['new_title'])] : []);

        if ($error !== null) {
            return ToolResult::error('Updated the event', $error);
        }

        if ($changes === []) {
            return ToolResult::error('Updated the event', 'Say what to change.');
        }

        // The whole event, as it would be, against the screen's own rules — both
        // ends on the same wall clock, so moving only the end is still checked
        // against the start.
        $merged = [
            'title' => $event->title,
            'type' => $event->type,
            'description' => $event->description,
            'starts_at' => $event->starts_at ? OrganizationClock::local($event->starts_at)->format('Y-m-d H:i') : null,
            'ends_at' => $event->ends_at ? OrganizationClock::local($event->ends_at)->format('Y-m-d H:i') : null,
            'location' => $event->location,
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, (new EventRequest)->rules())) !== null) {
            return ToolResult::error('Updated the event', $problem);
        }

        $this->workflow->update($event, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$event->title}",
            implode(', ', array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($changes))),
            $this->eventCard($this->withCounts(Event::query())->findOrFail($event->id), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function invite(User $user, array $args): ToolResult
    {
        [$event, $error] = $this->locateEvent($args);

        if ($event === null) {
            return ToolResult::error('Looked up the event', $error);
        }

        $named = is_array($args['employees'] ?? null) ? array_filter($args['employees'], 'filled') : [];
        $departments = is_array($args['departments'] ?? null) ? array_filter($args['departments'], 'filled') : [];

        if ($named === [] && $departments === []) {
            return ToolResult::error('Invited people', 'Say who to invite — people, departments, or both.');
        }

        $people = collect();

        if ($named !== []) {
            [$employees, $error] = $this->resolveEmployees(array_values($named), self::MAX_NAMED);

            if ($employees === null) {
                return ToolResult::error('Looked up the people', $error);
            }

            $people = $people->merge($employees);
        }

        if ($departments !== []) {
            [$members, $error] = $this->departmentMembers(array_values($departments));

            if ($members === null) {
                return ToolResult::error('Looked up the departments', $error);
            }

            $people = $people->merge($members);
        }

        $people = $people->unique('id')->values();

        if ($people->count() > self::MAX_INVITEES) {
            return ToolResult::error('Invited people', 'That is '.$people->count().' people — invite more than '.self::MAX_INVITEES.' at once from the event screen.');
        }

        try {
            $invited = $this->workflow->invite($event, $people->pluck('id')->all(), $user, ' via assistant');
        } catch (EventException $e) {
            return ToolResult::error("Invited people to {$event->title}", $e->getMessage());
        }

        $skipped = $people->count() - $invited;

        return ToolResult::ok(
            "Invited {$invited} to {$event->title}",
            $skipped > 0 ? "{$skipped} already invited" : null,
            $this->card(
                kind: 'add',
                tone: 'positive',
                badge: 'Invited',
                title: $event->title,
                subtitle: $invited.' '.Str::plural('person', $invited).' invited · '.$this->when($event),
                meta: [
                    $skipped > 0 ? "{$skipped} were already on the list" : null,
                    'People with an account were notified',
                ],
                id: $event->hashid,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function remind(User $user, array $args): ToolResult
    {
        [$event, $error] = $this->locateEvent($args);

        if ($event === null) {
            return ToolResult::error('Looked up the event', $error);
        }

        try {
            $reminded = $this->workflow->remind($event, $user, ' via assistant');
        } catch (EventException $e) {
            return ToolResult::error("Reminded the invitees of {$event->title}", $e->getMessage());
        }

        return ToolResult::ok(
            "Reminded {$reminded} about {$event->title}",
            null,
            $this->card(
                kind: 'remind',
                tone: 'info',
                badge: 'Reminded',
                title: $event->title,
                subtitle: $reminded.' pending '.Str::plural('invitee', $reminded).' reminded',
                meta: [$this->when($event)],
                id: $event->hashid,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function respond(User $user, array $args): ToolResult
    {
        [$attendee, $error] = $this->locateAttendee($args);

        if ($attendee === null) {
            return ToolResult::error('Looked up the invitation', $error);
        }

        $response = in_array($args['response'] ?? null, EventAttendee::RESPONSES, true) ? (string) $args['response'] : null;

        if ($response === null) {
            return ToolResult::error('Recorded the response', 'Say which: accepted, tentative, declined, or invited (no reply).');
        }

        $this->workflow->respond($attendee, $response, ' via assistant');

        return ToolResult::ok(
            "Recorded {$attendee->employee?->full_name}'s response",
            $this->responseText($response),
            $this->attendeeCard($attendee->refresh()->load(['employee', 'event']), 'edit', $response === 'declined' ? 'warning' : 'positive', Str::headline($response)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeAttendee(User $user, array $args): ToolResult
    {
        [$attendee, $error] = $this->locateAttendee($args);

        if ($attendee === null) {
            return ToolResult::error('Looked up the invitation', $error);
        }

        $card = $this->attendeeCard($attendee, 'cancel', 'warning', 'Removed');
        $name = $attendee->employee?->full_name;
        $title = $attendee->event?->title;

        $this->workflow->remove($attendee, ' via assistant');

        return ToolResult::ok("Removed {$name} from {$title}", null, $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveEvent(User $user, array $args): ToolResult
    {
        [$event, $error] = $this->locateEvent($args);

        if ($event === null) {
            return ToolResult::error('Looked up the event', $error);
        }

        $card = $this->eventCard($this->withCounts(Event::query())->findOrFail($event->id), 'archive', 'warning', 'Archived');

        $this->workflow->archive($event, ' via assistant');

        return ToolResult::ok("Archived {$event->title}", 'It can be restored from the Events screen.', $card);
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one (non-archived) event for a title — and a date, when several
     * share it — or why not.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: Event|null, 1: string}
     */
    private function locateEvent(array $args): array
    {
        $title = trim((string) ($args['event'] ?? ''));

        if ($title === '') {
            return [null, 'Say which event.'];
        }

        $date = null;

        if (filled($args['date'] ?? null) && ($date = $this->isoDate($args['date'])) === null) {
            return [null, 'Give the event date as YYYY-MM-DD.'];
        }

        $matches = Event::query()->whereRaw('lower(title) = ?', [Str::lower($title)])->chronological()->limit(50)->get();

        if ($matches->isEmpty()) {
            $like = Event::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $matches = Event::query()->where('title', $like, '%'.addcslashes($title, '%_\\').'%')->chronological()->limit(50)->get();
        }

        if ($date !== null) {
            $matches = $matches->filter(fn (Event $e): bool => $e->starts_at !== null && OrganizationClock::localDate($e->starts_at) === $date)->values();
        }

        return match (true) {
            $matches->isEmpty() => [null, 'No event matches “'.Str::limit($title, 60).'”'.($date !== null ? " on {$date}" : '').'.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one event matches “'.Str::limit($title, 60).'”: '.$matches->take(6)->map(fn (Event $e): string => "{$e->title} ({$this->when($e)})")->implode('; ').'. Say which by its full title and date.'],
        };
    }

    /**
     * This person's invitation to this event.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: EventAttendee|null, 1: string}
     */
    private function locateAttendee(array $args): array
    {
        [$event, $error] = $this->locateEvent($args);

        if ($event === null) {
            return [null, $error];
        }

        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return [null, $error];
        }

        $attendee = EventAttendee::query()
            ->with(['employee', 'event'])
            ->where('event_id', $event->id)
            ->where('employee_id', $employee->id)
            ->first();

        return $attendee === null
            ? [null, "{$employee->full_name} is not invited to {$event->title}."]
            : [$attendee, ''];
    }

    /**
     * Every active employee of the named departments — each name must be a
     * department, or nobody is invited.
     *
     * @param  list<mixed>  $names
     * @return array{0: Collection<int, Employee>|null, 1: string}
     */
    private function departmentMembers(array $names): array
    {
        if (count($names) > self::MAX_DEPARTMENTS) {
            return [null, 'At most '.self::MAX_DEPARTMENTS.' departments at a time.'];
        }

        $ids = [];

        foreach ($names as $name) {
            $id = $this->resolveId(Department::query(), 'name', is_scalar($name) ? (string) $name : '');

            if ($id === null) {
                return [null, 'No department is called “'.Str::limit(is_scalar($name) ? (string) $name : '', 60).'”.'];
            }

            $ids[] = $id;
        }

        return [Employee::query()->whereIn('department_id', $ids)->where('employment_status', 'active')->get(), ''];
    }

    /**
     * The event fields present in the arguments, times read as "YYYY-MM-DD
     * HH:MM" on the office clock — or why one of them is not usable.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $fields  Already-chosen values (the title).
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function eventFields(array $args, array $fields): array
    {
        if (in_array($args['type'] ?? null, Event::TYPES, true)) {
            $fields['type'] = $args['type'];
        }

        foreach (['location', 'description'] as $key) {
            if (filled($args[$key] ?? null)) {
                $fields[$key] = trim((string) $args[$key]);
            }
        }

        foreach (['starts_at', 'ends_at'] as $key) {
            if (! filled($args[$key] ?? null)) {
                continue;
            }

            $time = $this->wallClock($args[$key]);

            if ($time === null) {
                return [[], 'Give the '.($key === 'starts_at' ? 'start' : 'end').' as "YYYY-MM-DD HH:MM" (24-hour).'];
            }

            $fields[$key] = $time;
        }

        return [$fields, null];
    }

    /**
     * A wall-clock date-time as "Y-m-d H:i", or null when it is not one. No
     * zone, no words: "next Friday" is the model's to resolve against the clock
     * it was given.
     */
    private function wallClock(mixed $value): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m) !== 1) {
            return null;
        }

        if ($this->isoDate($m[1]) === null || (int) $m[2] > 23 || (int) $m[3] > 59) {
            return null;
        }

        return sprintf('%s %02d:%s', $m[1], (int) $m[2], $m[3]);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query
            ->withCount('attendees')
            ->withCount(['attendees as attending_count' => fn (Builder $q) => $q->attending()])
            ->withCount(['attendees as pending_count' => fn (Builder $q) => $q->where('response', 'invited')]);
    }

    /**
     * When an event is, on the office clock: "Fri Oct 2, 2026, 2:00pm – 3:30pm".
     */
    private function when(Event $event): string
    {
        if ($event->starts_at === null) {
            return 'time not set';
        }

        $start = OrganizationClock::local($event->starts_at);
        $end = $event->ends_at ? OrganizationClock::local($event->ends_at) : null;
        $text = $start->format('D M j, Y, g:ia');

        if ($end === null) {
            return $text;
        }

        return $text.' – '.($end->isSameDay($start) ? $end->format('g:ia') : $end->format('D M j, g:ia'));
    }

    private function tally(Event $event): string
    {
        $invited = (int) ($event->attendees_count ?? 0);

        if ($invited === 0) {
            return 'Nobody invited yet';
        }

        $pending = (int) ($event->pending_count ?? $event->attendees()->where('response', 'invited')->count());

        return sprintf('%d invited: %d coming (accepted or tentative), %d not replied', $invited, (int) ($event->attending_count ?? 0), $pending);
    }

    private function summaryLine(Event $event): string
    {
        return sprintf(
            '%s (%s) — %s%s; %s.',
            $event->title,
            $event->type,
            $this->when($event),
            $event->location ? ' at '.$event->location : '',
            Str::lcfirst($this->tally($event)),
        );
    }

    private function responseText(string $response): string
    {
        return match ($response) {
            'accepted' => 'accepted',
            'tentative' => 'tentative',
            'declined' => 'declined',
            default => 'not replied yet',
        };
    }

    /**
     * @param  Collection<int, EventAttendee>  $attendees
     */
    private function names(Collection $attendees): ?string
    {
        if ($attendees->isEmpty()) {
            return null;
        }

        $names = $attendees->map(fn (EventAttendee $a): string => $a->employee?->full_name ?? 'Unknown')->values();

        return $names->take(self::MAX_NAMES)->implode(', ').($names->count() > self::MAX_NAMES ? ' and '.($names->count() - self::MAX_NAMES).' more' : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function eventCard(Event $event, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $event->title,
            subtitle: $this->when($event).($event->location ? ' · '.$event->location : ''),
            meta: [Str::headline($event->type), $this->tally($event)],
            id: $event->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attendeeCard(EventAttendee $attendee, string $kind, string $tone, string $badge): array
    {
        $employee = $attendee->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: $attendee->event ? $attendee->event->title.' · '.$this->when($attendee->event) : null,
            meta: [$this->responseText($attendee->response)],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $attendee->id,
        );
    }
}
