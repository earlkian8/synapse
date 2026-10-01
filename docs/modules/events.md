# Events & Meetings

Schedule company **events and meetings**, invite employees, and track who's coming.
Events are created in the module (there is no Company Setup config); an event's
lifecycle status (`upcoming → ongoing → past`) is **derived from its date-time
window**, never stored. Data model is ERD §9; everything is tenant-scoped (ADR 0005).
See [ADR 0015](../decisions/0015-events-and-meetings.md).

> Status: **Active** · Route prefix: `/events`
> Sidebar: Workforce → Events & Meetings (gated by `events.view`)

## Surfaces

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
- **Responses** — an invitee is `invited` by default; HR sets `accepted`, `declined`
  or `tentative`. The "going" headcount counts `accepted` + `tentative`.
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
- **Reminders** — HR can re-notify every invitee who has not replied. Not once the
  event is over, and only people with an active account.
- **Calendar file** — `/events/{event}/ics` downloads the event for Outlook, Google
  or Apple calendars. Every line break in a text value, a lone CR included, is
  escaped, so a title cannot add a property of its own.

## Where the rules live

`App\Support\Events\EventWorkflow` is the one path that schedules, edits or archives
an event and manages its guest list, for the screens and the assistant alike.
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
    declined or has not replied.
- **Writes** (`events.manage`), all through `EventWorkflow`:
  - `schedule_event` and `update_event`:
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
- **No self-service**, as on the screens.

## Permissions

`events.view` (overview + detail) and `events.manage` (schedule / edit / archive
events, invite attendees, update responses). Built-in **HR Manager** gets both. The
creating user is recorded as the event's organiser.

## Out of scope (this cut)

Self-service RSVP for non-HR users, recurring events, two-way calendar sync, room or
resource booking, and automatic reminders ahead of the event.
