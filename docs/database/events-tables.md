# Database: events tables

The tables behind the [Events & Meetings module](../modules/events.md), created by
`…_create_events_tables` (ERD §9). A parent record (`events`) + its invitee roster
(`event_attendees`) — see [ADR 0015](../decisions/0015-events-and-meetings.md). Both
are tenant-scoped (`organization_id`). Created in-module; there is no Company-Setup
config (like training programs).

## `events`

One scheduled event or meeting. Managed at `/events`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `title` | string | e.g. "Quarterly Town Hall". |
| `description` | text, nullable | Agenda or details. |
| `type` | string | `event` or `meeting`. |
| `starts_at` | datetime | When it begins. Indexed. |
| `ends_at` | datetime, nullable | When it ends; null for a point-in-time entry. |
| `location` | string, nullable | Room, address or link. |
| `organizer_id` | FK → users, nullable | Who runs it; `nullOnDelete`. Loaded **`withTrashed`** so a past event still shows the organiser. |
| timestamps + soft deletes | | An event with attendees cannot be permanently deleted. |

**Indexes:** `starts_at`, `type`.

The lifecycle **status is derived**, not stored — `App\Models\Event::status()`
returns `upcoming` / `ongoing` / `past` from now against `starts_at` / `ends_at`.

## `event_attendees`

One employee's invitation to an event. Managed on the event detail page.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Attendees are addressed by numeric id. |
| `organization_id` | FK → organizations | Tenant. |
| `event_id` | FK → events | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. Indexed. |
| `response` | string | `invited` (default), `accepted`, `declined`, `tentative`. |
| `notified_at` | timestamp, nullable | When the invite notification was delivered; null when the employee has no linked / active account. |
| timestamps | | |

**Constraints:** `unique(event_id, employee_id)` — one invitation per employee per
event. **Indexes:** `employee_id`.

The "going" headcount (`attending`) counts `accepted` + `tentative` responses.
