# Events & Meetings

Schedule company **events and meetings**, invite employees, and track who's coming.
Events are created in the module (there is no Company Setup config); an event's
lifecycle status (`upcoming → ongoing → past`) is **derived from its date-time
window**, never stored. Data model is ERD §9; everything is tenant-scoped (ADR 0005).
See [ADR 0015](../decisions/0015-events-and-meetings.md), and
[ADR 0070](../decisions/0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md)
for answering one's own invitations, repeats, rooms, automatic reminders and the
calendar feed.

> Status: **Active** · Route prefix: `/events`
> Sidebar: Workforce → Events & Meetings (shown with `events.view` **or**
> `events.respond`; someone with only the latter lands on `/events/me`)

## Surfaces

The module's pages share a section control in the header (`EventsNav`, built on the
shared `ModuleNav`): **All events · Rooms** for `events.view`, then **My invitations**
for `events.respond` — each shown to whoever may open it, and hidden when only one
applies.

- **`/events`** — the **overview**: stat tiles (upcoming, total, meetings,
  invitations) and a **table of events** (title with kind and organiser, schedule,
  location, attending/invited, derived status). It can be searched, filtered by kind
  and status, sorted and paged. A row opens the event. HR can **schedule a new
  event**. **Archived (n)** is one more status option, and there a row's menu restores
  the event or deletes it permanently.
- **`/events/{event}`** — the **event detail**: a header (kind, schedule, location,
  organiser, description, derived-status badge), stat tiles (invited, accepted,
  tentative, not replied), and the **attendees table**, which can be searched, exported
  and paged. HR can **invite attendees**
  (multi-select), change each invitee's **response** inline, remove an attendee, and
  **edit** or **archive** the event.
- **`/events/me`** — **My invitations** (`events.respond`): the person's own
  invitations by day, **Coming up** and **Past 30 days**. Each answers **Going /
  Maybe / Not going** inline (for a repeating event, optionally for every later date),
  downloads its `.ics`, and shows the room, organiser, headcount, repeat and reminder.
  A repeating event shows its next date unless *Show every date* is ticked.
  **Subscribe** opens the calendar-feed dialog. `?event=<hashid>` (where notifications
  point) scrolls to that event. Only the person's own invitations are reachable here.
- **`/events/rooms`** — **Rooms** (`events.view`; changes need `events.manage`): each
  room with the events holding it, one week at a time (`?week=`), plus add, edit,
  archive, restore and — for a room no event ever used — delete.
- **Employee detail → Events tab** — a read-only list of the events an employee is
  invited to, with their response.

Both pages use the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)).

## Behaviour

- **Derived status** — `App\Models\Event::status()` compares now to the window:
  `upcoming` before it starts, `past` once it ends (or once a no-end event's start
  has passed), `ongoing` in between. Never stored, so it cannot drift (mirrors
  Training programs).
- **Invitations notify** — inviting an employee whose account is **linked and active**
  sends them an in-app `SystemNotification` (category `events`) and stamps
  `event_attendees.notified_at`. Best-effort: a delivery hiccup never blocks the
  invite. Employees with no login are still invited, just not notified.
- **Responses** — an invitee is `invited` by default. They answer `accepted`,
  `tentative` or `declined` themselves (never back to `invited`), until the event is
  over or archived; `responded_at` is stamped. HR can still set a reply for them. The
  "going" headcount counts `accepted` + `tentative`. Notifications link to
  `/events/me?event=<hashid>`, which every invitee can open.
- **Repeats** — `event_series` holds the rule (daily, weekly on chosen weekdays, or
  monthly; every 1–4; until a date or a count; at most 100 dates and two years). Every
  date is its own `events` row with `series_id`, generated at the same local time.
  Monthly keeps the day and clamps to the month's end. Editing, inviting to and
  archiving one date ask for a scope — *this event* or *this and following*;
  "following" moves each later date by whole days, at the new time of day.
- **Rooms** — `events.room_id`. A room needs an end time, and two live events cannot
  hold one room in overlapping windows: `RoomBooking` checks every date of a series in
  a transaction with the room locked, and refuses with the event that holds it. The
  form asks `/events/rooms/availability` and marks each room free or taken. An
  archived or retired room takes no new bookings; events that hold one keep it.
- **Bulk invite** — the invite dialog is a searchable, multi-select checklist
  (events naturally invite many at once); already-invited employees are skipped.
- **Archiving** — events soft-delete (archive) and restore; an event with attendees
  cannot be permanently deleted (archive instead), matching Training programs.
- **Times are the office's wall clock** ([ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md),
  [ADR 0050](../decisions/0050-assistant-training-awards-and-events.md)). The form's
  `datetime-local` value has no zone. It is read as the organisation's clock and
  stored as that UTC instant. Before, it was read as UTC: a Manila user who entered
  2pm saw 10pm, and every save of the edit form moved the event another eight hours.
  Events stored before the fix keep the instant they were given.
- **Reminders** — automatic: `reminder_minutes` (10 min to 2 days; the form defaults
  to 1 hour) is sent by `events:remind` (every five minutes) to every invitee who has
  not declined and has an active account, once — `reminder_sent_at` is stamped, and
  moving the start clears it. HR's *Remind pending* still re-notifies people who have
  not replied, not once the event is over.
- **Calendar file** — `/events/{event}/ics` (HR) and `/events/me/{event}/ics` (the
  invitee) download the event for Outlook, Google or Apple calendars. Every line break
  in a text value, a lone CR included, is escaped, so a title cannot add a property of
  its own.
- **Calendar feed** — `/api/calendar/{token}.ics`, public and throttled (60/min):
  one feed per person per workspace, serving their invitations (not declined) and the
  events they organise, 60 days back to 400 ahead, each with a stable `UID` and a
  `SEQUENCE`. The token is stored hashed for lookup and encrypted for display; *Reset
  link* replaces it. Write-back from Google or Outlook is not built.

## Where the rules live

`App\Support\Events\EventWorkflow` is the one path that schedules, edits or archives
an event, manages its guest list and records answers (`respondAsInvitee()` for the
invitee, `respond()` for HR), for the screens, the mobile API and the assistant alike.
`Recurrence` generates a series' dates, `RoomBooking` guards rooms,
`EventCalendar` writes every `VCALENDAR`, and `events:remind`
(`App\Console\Commands\RemindEvents`) sends the scheduled reminders. A refusal tied
to a field (`EventException::$field`, e.g. `room_id`) is shown on that field.
Refusals are shown in its own words (`EventException`): everyone is already invited,
the event is over, nobody is left to remind. Employee ids are validated against the
current workspace (`TenantRule`).

It also fixed an outright failure. Dates are immutable app-wide, and the old
controller's `notify()` promised a mutable `Carbon`. So **inviting anyone with an
active login, and every reminder, threw an error.** The `.ics` download failed the
same way.

## The assistant

`App\Services\Assistant\Modules\EventsModule` puts the calendar in the chat
assistant ([ADR 0050](../decisions/0050-assistant-training-awards-and-events.md)).

- **Reads** (`events.view`):
  - `find_events` — what is ahead by default, soonest first. It can filter by title
    or location, kind, status, a window of days, or who is invited;
  - `get_event` — when, where, the organiser, and by name who accepted, is tentative,
    declined or has not replied;
  - `find_rooms` — rooms, and whether each is free in a window.
- **Self-service** (`events.respond`): `find_my_invitations` (the user's own) and
  `respond_to_event` (accepted, tentative or declined; `all_following` for a series),
  searched only among the user's own invitations.
- **Writes** (`events.manage`), all through `EventWorkflow`:
  - `schedule_event` and `update_event` (with `room`, `reminder_minutes`, `repeat`, and
    a `scope` for an update):
    - times are given as `YYYY-MM-DD HH:MM` on the office clock, and the model is told
      the zone and the time now;
    - values are checked against `EventRequest`'s own rules, an update as the whole
      event would be;
    - a new event starting in the past is refused as a likely wrong year;
  - `set_event_response`;
  - **anything that notifies people waits for the user's Confirm:**
    `invite_to_event` (people by name, up to 25, and/or whole departments; at most
    200 people in one go) and `remind_event_invitees`. So do
    `remove_event_attendee` and `archive_event`.
- **Events are resolved by title, plus their date when several share it.** A
  recurring "Weekly standup" is never guessed.
- **Retrieval:**
  - a question about a person carries their upcoming invitations and replies;
  - a question about events that names nobody ("any meetings this week?") carries
    the next 30 days.

## Permissions

`events.view` (overview, detail, rooms), `events.manage` (schedule / edit / archive
events, invite attendees, update responses, manage rooms) and `events.respond` (see
and answer one's own invitations, subscribe). Built-in **HR Manager** gets all three;
**Department Head** gets `events.view` and `events.respond`; **Staff** gets
`events.respond`. The creating user is recorded as the event's organiser.

## Out of scope (this cut)

Writing back from Google or Outlook (it needs an OAuth app per provider); reserving a
room without an event; equipment or other resources; per-person reminder preferences.
