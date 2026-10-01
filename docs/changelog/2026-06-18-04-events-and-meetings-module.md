# Events & Meetings module

Adds the **Events & Meetings** module (ERD §9): schedule company events and meetings,
invite employees, and track who's coming. Mirrors **Training & Development** — a parent
record with an invitee roster, created in-module, with a lifecycle status **derived from
the date-time window**. Wires the existing sidebar placeholder. See
[ADR 0015](../decisions/0015-events-and-meetings.md),
[module doc](../modules/events.md) and [events tables](../database/events-tables.md).

## Highlights

- **Overview grouped by status.** `/events` shows KPIs (upcoming, total, meetings,
  invitations) and a card per event grouped into **Happening now / Upcoming / Past** —
  status derived from `starts_at` / `ends_at`, never stored.
- **Event detail + roster.** `/events/{event}` carries the schedule, location, organiser
  and a searchable **multi-select invite** dialog; each attendee's response (invited /
  accepted / declined / tentative) is editable inline.
- **Invitations notify.** Inviting an employee with a linked, active account sends them
  an in-app notification (category `events`) and stamps `notified_at` — best-effort, via
  the existing `Notifier`.

## Backend

- **Migration** `…_create_events_tables`: `events` (title, type, starts_at, nullable
  ends_at, location, organizer_id → users, soft-deletes) and `event_attendees` (event,
  employee, response, notified_at; `unique(event_id, employee_id)`). Tenant-scoped.
- **Models** `Event` (HasHashid, soft-deletes, `TYPES`/`STATUSES`, derived `status()`,
  `organizer` loaded `withTrashed`, `chronological`/`recentFirst` scopes) + `EventAttendee`
  (`RESPONSES`, `attending` scope); `Employee::eventAttendances`.
- **Controllers** `Events\EventController` (index + show), `EventManagementController`
  (CRUD + archive/restore/force), `EventAttendeeController` (bulk invite recording the
  organiser-side notification, response update, remove). Thin, FormRequest-validated,
  activity-logged.
- **Resources** `EventResource`, `EventAttendeeResource`.
- **Routes** new `routes/events.php` (required in `web.php`); attendee routes declared
  before the `{event}` wildcard. Permissions `events.view` / `events.manage` added to
  `PermissionRegistry`; built-in HR Manager granted both.
- **Seeder** `EventSeeder` (6 events across the lifecycle + a believable response
  spread); wired into `DatabaseSeeder` and `MockSeeder`.
- **Employee integration** `EmployeeController` eager-loads `eventAttendances.event`;
  `EmployeeResource` exposes `events`.

## Frontend

- **Feature** `features/events` (types, routes, api, constants with date-time helpers,
  components: stats, status + response badges, event card, event form sheet,
  multi-select invite dialog).
- **Pages** `events/index` (status-grouped overview + archived section), `events/show`
  (header, stat strip, attendee roster with inline response control).
- **Employee detail** read-only **Events** tab (alongside Performance, Training & Awards).
- **Sidebar** Events & Meetings placeholder gated by `events.view`.

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint, Prettier and `vite build` all green (both
  `pages/events/*` chunks emitted). Migration applied on Postgres; a tinker transaction
  (rolled back) confirmed the `upcoming/ongoing/past` derivation incl. the no-end
  point-in-time case, the `attending` scope (accepted + tentative), `EventResource`
  serialization, and that `events.view` / `events.manage` synced. Pest was **not** run
  locally (no `pdo_sqlite`).
- Out of scope this cut: self-service RSVP for non-HR users, recurring events, calendar
  sync / iCal, room booking, pre-event reminders, and an assistant capability.
