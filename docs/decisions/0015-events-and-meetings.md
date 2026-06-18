# 0015 — Events & Meetings: scheduled events with an invitee roster

- **Status:** Accepted
- **Date:** 2026-06-18
- **Related:** [Events module](../modules/events.md),
  [events tables](../database/events-tables.md), [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0013 — Training & Development](./0013-training-and-development.md) — the parent +
  roster + derived-status precedent,
  [0003 — Notification channels](./0003-notification-channels.md) — the invite path

## Context

ERD §9 sketches `events` (a titled event/meeting with a date-time window, a location
and an organiser) and `event_attendees` (an employee invited to an event, with a
response and a `notified_at`). The sidebar already carried an ungated placeholder for
**Events & Meetings** (`/events`).

Structurally this is the **Training** shape, not the **Awards** shape: a parent record
with a child roster, a lifecycle that depends on time, and a detail page worth
navigating to — not a flat feed.

## Decision

Build Events & Meetings by **mirroring Training & Development**:

- **`events`** — created in-module (no Company-Setup config, like training programs),
  addressed by hashid, soft-deletable (archive / restore / force-delete). The
  lifecycle **status (`upcoming → ongoing → past`) is derived** from the window via
  `Event::status()`, never stored.
- **`event_attendees`** — the invitees, addressed by numeric id (like enrollments),
  each with a `response` (`invited | accepted | declined | tentative`) and a
  `notified_at`.

Three thin controllers (`EventController` read, `EventManagementController` CRUD,
`EventAttendeeController` roster), `EventRequest` + `EventAttendeeRequest`, and
`EventResource` + `EventAttendeeResource` — the same layering as Training. The
overview groups events by derived status; the detail page carries the roster with an
inline response control.

### Justified refinements over the raw ERD (backward-compatible)

- **`events` gains soft deletes** — every operational record archives rather than
  hard-deletes (ERD convention); an event with attendees cannot be force-deleted,
  the same guard Training programs use.
- **`ends_at` is nullable** — a quick meeting may have only a start; `status()`
  treats a no-end event as a point in time. Mirrors Training's nullable `end_date`.
- **Inviting notifies the invitee** — when an attendee's account is linked and active,
  the invite fans out a `SystemNotification` (category `events`) via the existing
  `Notifier` and stamps `notified_at`. The ERD's `invited` response + `notified_at`
  column clearly intend this; it reuses the notification layer rather than adding one.
- **Bulk invite** — `event_attendees` are created from an `employee_ids[]` list, since
  events invite many people at once; already-invited employees are skipped.

## Consequences

- The organiser is recorded as `events.organizer_id` (an actor FK to `users`, per the
  ERD convention, loaded `withTrashed` so a past event still shows who ran it).
- A dedicated `events.*` permission set gates the module; built-in **HR Manager** gets
  both `events.view` and `events.manage`.
- The Employee detail drawer gains a read-only **Events** tab (alongside Benefits,
  Performance, Training and Awards).
- A best-effort notification is emitted per invited, logged-in employee — a handful
  per invite, delivered on the synchronous connection like every other
  `SystemNotification`.
- **Out of scope (this cut):** self-service RSVP for non-HR users, recurring events,
  calendar sync / iCal, room booking, pre-event reminders, and an assistant capability
  (matching the Training / Awards precedent of shipping the operational core first).
