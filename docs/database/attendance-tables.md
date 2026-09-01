# Database: attendance tables

The tables behind the [Attendance module](../modules/attendance.md), created by
`…_create_attendance_tables`. Both are tenant-scoped (`organization_id`). See
[ADR 0010](../decisions/0010-attendance-and-mobile-api.md).

## `attendance_records`

One **Daily Time Record** per employee per day — the computed summary, built from the
day's punches (never trusted from the client).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `work_date` | date | The day this record covers. |
| `work_schedule_id` | FK → work_schedules, nullable | Snapshot of the schedule that applied. |
| `scheduled_start` / `scheduled_end` | time, nullable | Snapshot of the schedule's times. |
| `status` | string | `present \| late \| undertime \| absent \| on_leave \| day_off \| holiday \| incomplete`. |
| `first_in_at` / `last_out_at` | timestamp, nullable | Derived from the punches. |
| `worked_minutes` | uint | On-the-clock minutes, breaks excluded. |
| `break_minutes` | uint | Total break time. |
| `late_minutes` | uint | `first_in − (scheduled_start + grace)`, clamped at 0. |
| `undertime_minutes` | uint | Time clocked out before `scheduled_end`. |
| `overtime_minutes` | uint | `worked − required_hours`, clamped at 0. |
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
