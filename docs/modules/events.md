# Events & Meetings

Schedule company **events and meetings**, invite employees, and track who's coming.
Events are created in the module (there is no Company Setup config); an event's
lifecycle status (`upcoming → ongoing → past`) is **derived from its date-time
window**, never stored. Data model is ERD §9; everything is tenant-scoped (ADR 0005).
See [ADR 0015](../decisions/0015-events-and-meetings.md).

> Status: **Active** · Route prefix: `/events`
> Sidebar: Workforce → Events & Meetings (gated by `events.view`)

## Surfaces

- **`/events`** — the **overview**: a KPI bar (upcoming, total, meetings,
  invitations) and a card per event grouped by derived status (**Happening now**,
  **Upcoming**, **Past**). Each card shows the kind (event / meeting), the schedule,
  the location and the going/invited headcount. HR can **schedule a new event** and
  reveal an **Archived** section to restore / permanently delete.
- **`/events/{event}`** — the **event detail**: a header (kind, schedule, location,
  organiser, description, derived-status badge), a stat strip (location, invited,
  accepted, organiser), and the **attendee roster**. HR can **invite attendees**
  (multi-select), change each invitee's **response** inline, remove an attendee, and
  **edit** or **archive** the event.
- **Employee detail → Events tab** — a read-only list of the events an employee is
  invited to, with their response.

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

## Permissions

`events.view` (overview + detail) and `events.manage` (schedule / edit / archive
events, invite attendees, update responses). Built-in **HR Manager** gets both. The
creating user is recorded as the event's organiser.

## Out of scope (this cut)

Self-service RSVP for non-HR users, recurring events, calendar sync / iCal export,
room or resource booking, reminders ahead of the event, and an assistant capability
— matching the precedent of shipping operational modules (Training, Awards) without
those first.
