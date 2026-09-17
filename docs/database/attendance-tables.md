# Database: attendance tables

The tables behind the [Attendance module](../modules/attendance.md), created by
`…_create_attendance_tables`. Both are tenant-scoped (`organization_id`). See
[ADR 0010](../decisions/0010-attendance-and-mobile-api.md) and
[ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
(the shift instants and the `rules` snapshot, added by `…_snapshot_rules_on_attendance_records`).
The **plan** the record is judged against — day patterns, dated assignments and roster
overrides — lives in [scheduling tables](./scheduling-tables.md)
([ADR 0037](../decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)).

## `attendance_records`

One **Daily Time Record** per employee per day — the computed summary, built from the
day's punches (never trusted from the client).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `work_date` | date | The work date the shift belongs to — a night shift that ends the next morning stays on the date it started. |
| `work_schedule_id` | FK → work_schedules, nullable | Snapshot of the schedule the resolver picked for this day. |
| `scheduled_start` / `scheduled_end` | time, nullable | Snapshot of the shift's clock-face edges — the first segment's start and the last segment's end (for display). |
| `scheduled_start_at` / `scheduled_end_at` | timestamp, nullable | The shift as UTC instants, worked out in the organisation's zone; the end is the next morning when it is at or before the start. What lateness and undertime are measured against. |
| `rules` | json, nullable | The `DayRules` snapshot the day is judged by: `version`, `grace_minutes`, `required_minutes`, `is_working_day`, `work_schedule_id`, `schedule_name`, `holiday_type`, `holiday_name`, and — from `version: 2` (ADR 0037) — `type`, `segments`, `core_start_at` / `core_end_at`, `unpaid_break_minutes` and `source`. Changes only when HR re-applies the current schedule. Null on rows from before ADR 0036 until they are recomputed; a `version: 1` snapshot still reads as a fixed shift. |
| `status` | string | `present \| late \| undertime \| absent \| on_leave \| day_off \| holiday \| incomplete`. |
| `first_in_at` / `last_out_at` | timestamp, nullable | Derived from the punches. |
| `worked_minutes` | uint | On-the-clock minutes, breaks excluded. |
| `break_minutes` | uint | Total break time. |
| `late_minutes` | uint | `fixed`: `first_in − (scheduled_start_at + grace)`. `flexible`: against `core_start_at` instead. `hours_only`: always 0. Clamped at 0. |
| `undertime_minutes` | uint | `fixed`: time clocked out before `scheduled_end_at`. `flexible`: the worse of leaving before `core_end_at` and falling below `required_minutes`. `hours_only`: `required_minutes − worked`. |
| `overtime_minutes` | uint | `worked − required_minutes` (from `rules`), clamped at 0. |
| `is_manual` | boolean | True when entered/edited by HR. |
| `remarks` | text, nullable | |
| `approval_status` | string, nullable | `pending \| approved \| rejected` (correction / overtime sign-off). |
| `approved_by` | FK → users, nullable | |
| `approved_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** unique `(employee_id, work_date)`; `work_date`; `status`.

## `attendance_punches`

The raw punch events the summary is computed from. Each carries its capture context so a
mobile app's punches are fully auditable.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `attendance_record_id` | FK → attendance_records | Cascade on delete. |
| `employee_id` | FK → employees | Denormalised so history queries skip a join. |
| `type` | string | `clock_in \| clock_out \| break_start \| break_end`. |
| `punched_at` | timestamp | When the punch happened. |
| `source` | string | `web \| mobile \| kiosk \| biometric \| manual`. |
| `latitude` / `longitude` | decimal(10,7), nullable | GPS fix. |
| `accuracy` | decimal(8,2), nullable | Metres. |
| `photo` | string, nullable | Selfie path (public disk). |
| `note` | string, nullable | |
| `recorded_by` | FK → users, nullable | Null when the employee self-punched. |
| timestamps | | |

**Index:** `(employee_id, punched_at)`.

## Mobile auth

The token-authenticated API ([ADR 0010](../decisions/0010-attendance-and-mobile-api.md))
uses Laravel Sanctum's standard `personal_access_tokens` table (published migration). The
`User` model gains `HasApiTokens`; the web's session auth is unchanged.
