# Database: events tables

The tables behind the [Events & Meetings module](../modules/events.md), created by
`…_create_events_tables` and `2026_10_10_000000_create_event_self_service_tables` (ERD §9). A parent record (`events`) + its invitee roster
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
| `location` | string, nullable | Address or link (or a room's name, for events before rooms). |
| `room_id` | FK → rooms, nullable | The room it holds ([ADR 0070](../decisions/0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md)); `nullOnDelete`. Loaded **`withTrashed`**. Two live events never hold one room in overlapping windows (`RoomBooking`). |
| `organizer_id` | FK → users, nullable | Who runs it; `nullOnDelete`. Loaded **`withTrashed`** so a past event still shows the organiser. |
| `series_id` | FK → event_series, nullable | The repeat it belongs to; `nullOnDelete`. |
| `reminder_minutes` | smallint, nullable | 10, 30, 60, 120, 1440 or 2880; null for no reminder. |
| `reminder_sent_at` | timestamp, nullable | Stamped by `events:remind`; cleared when the start moves. |
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
| `responded_at` | timestamp, nullable | When the invitee (or HR) last answered. |
| timestamps | | |

**Constraints:** `unique(event_id, employee_id)` — one invitation per employee per
event. **Indexes:** `employee_id`.

The "going" headcount (`attending`) counts `accepted` + `tentative` responses.

## `rooms`

A room events can be held in ([ADR 0070](../decisions/0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md)). Managed at `/events/rooms`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant; cascade. Indexed. |
| `name` | string | e.g. "Boardroom". |
| `location` | string, nullable | e.g. "5th floor, east wing". |
| `capacity` | integer, nullable | Seats; under the invite count is a warning, not a refusal. |
| `description` | text, nullable | |
| `is_active` | boolean | Default true. A retired room takes no new bookings. |
| timestamps + soft deletes | | A room any event used cannot be permanently deleted. |

## `event_series`

The rule a repeating event was generated from. Each date is its own `events` row with
`series_id`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; cascade. Indexed. |
| `frequency` | string | `daily`, `weekly` or `monthly`. |
| `interval` | smallint | Every 1–4 (default 1). |
| `weekdays` | json, nullable | ISO weekdays (1 = Monday) for weekly. |
| `until` | date, nullable | Last date; or |
| `count` | smallint, nullable | how many dates (at most 100, within two years). |
| `created_by` | FK → users, nullable | `nullOnDelete`. |
| timestamps | | |

## `calendar_feeds`

One person's calendar subscription for one workspace.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; cascade. |
| `user_id` | FK → users | Cascade. |
| `token_hash` | string | sha-256 of the token, for lookup. Unique. |
| `token` | text | The token, encrypted, so the link can be shown again. |
| `last_used_at` | timestamp, nullable | Last time a calendar app fetched it. |
| timestamps | | |

**Constraints:** `unique(organization_id, user_id)`, `unique(token_hash)`. Not part of
Data Export: it holds a bearer token.
