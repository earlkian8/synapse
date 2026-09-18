# Database: attendance tables

The tables behind the [Attendance module](../modules/attendance.md), created by
`…_create_attendance_tables`. Both are tenant-scoped (`organization_id`). See
[ADR 0010](../decisions/0010-attendance-and-mobile-api.md) and
[ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
(the shift instants and the `rules` snapshot, added by `…_snapshot_rules_on_attendance_records`).
The **plan** the record is judged against — day patterns, dated assignments and roster
overrides — lives in [scheduling tables](./scheduling-tables.md)
([ADR 0037](../decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)).
How the day is **judged** — the company's attendance policies — is `attendance_policies`
below ([ADR 0038](../decisions/0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md),
`…_create_attendance_policies`), which also added the minute buckets and `flags`.

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
| `rules` | json, nullable | The `DayRules` snapshot the day is judged by: `version`, `grace_minutes`, `required_minutes`, `is_working_day`, `work_schedule_id`, `schedule_name`, `holiday_type`, `holiday_name`, and — from `version: 2` (ADR 0037) — `type`, `segments`, `core_start_at` / `core_end_at`, `unpaid_break_minutes` and `source`; from `version: 3` (ADR 0038) — `policy`: `{id, name, source, settings_version, settings}`, the complete attendance policy the day is judged by. Changes only when HR re-applies the current schedule and policy. Null on rows from before ADR 0036 until they are recomputed; a `version: 1` snapshot still reads as a fixed shift, and a `version: 1` or `2` one is judged by the built-in fallback policy. |
| `status` | string | `present \| late \| undertime \| half_day \| absent \| on_leave \| day_off \| holiday \| incomplete`. `half_day` (ADR 0038) is very late or very short by the policy's thresholds; a threshold can also make a punched day `absent`. |
| `flags` | json, nullable | Everything more specific than the status (ADR 0038): `late`, `undertime`, `half_day`, `late_absent`, `below_minimum`, `break_deducted`, `break_exceeded`, `unapproved_overtime`, `rest_day_worked`, `holiday_worked`. |
| `first_in_at` / `last_out_at` | timestamp, nullable | Derived from the punches. |
| `worked_minutes` | uint | On-the-clock minutes judged by the policy: breaks excluded (but the paid part of a punched one counted, and an unpunched unpaid one deducted), clock-ins and clock-outs rounded, early minutes clipped when the policy does not count them. |
| `break_minutes` | uint | Total break time — punched, or the unpunched one the policy deducted. |
| `late_minutes` | uint | `fixed`: `first_in − scheduled_start_at`, less grace. `flexible`: against `core_start_at` instead. `hours_only`, or a policy that does not judge lateness: always 0. Clamped at 0. |
| `excused_late_minutes` | uint | The lateness grace forgave (ADR 0038) — per day, or out of a monthly allowance, which counts these down across the month. |
| `undertime_minutes` | uint | `fixed`: time clocked out before `scheduled_end_at`. `flexible`: the worse of leaving before `core_end_at` and falling below `required_minutes`. `hours_only`: `required_minutes − worked`. |
| `regular_minutes` | uint | `worked − overtime` (ADR 0038). With `overtime_minutes` a partition of the worked minutes. |
| `overtime_minutes` | uint | By the policy's basis: `daily` beyond its threshold (or `required_minutes`), `weekly` the part of the day that carries the Mon–Sun week's regular minutes past its threshold, `daily_and_weekly` both without counting a minute twice; a whole rest day or holiday when the policy says so; nothing below the minimum block. The built-in fallback is `worked − required_minutes`, clamped at 0. |
| `approved_overtime_minutes` | uint | The overtime that needs no further sign-off: all of it under a policy that does not require approval, none of it (until Phase 3's approval flow) under one that does. |
| `night_minutes` | uint | Worked minutes inside the policy's night window, on the organisation's clock. A tag over worked minutes, not more of them. |
| `rest_day_minutes` | uint | Worked minutes on a day that was not a working day. A tag. |
| `holiday_minutes` | uint | Worked minutes on a `regular` or `special_non_working` holiday. A tag. |
| `is_manual` | boolean | True when entered/edited by HR. |
| `remarks` | text, nullable | |
| `approval_status` | string, nullable | `pending \| approved \| rejected` (correction / overtime sign-off). |
| `approved_by` | FK → users, nullable | |
| `approved_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** unique `(employee_id, work_date)`; `work_date`; `status`.

## `attendance_policies`

How a company judges an attendance day (ADR 0038) — a preset, adjusted through typed
options. Configured under Company Setup → Attendance Policies; see the
[module doc](../modules/attendance-policies.md) for every setting.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `organization_id` | FK → organizations | Tenant. Cascade on delete. |
| `name` | string | Unique per tenant among live rows (validated). |
| `description` | text, nullable | Who it is for. |
| `preset_key` | string, nullable | The preset it was made from — `ph_labor_code`, `standard_40h_week`, `flexible_no_lateness`, `shift_work` — for "reset to preset". |
| `settings` | json, nullable | Grouped typed options (`punch_windows`, `lateness`, `undertime`, `rounding`, `breaks`, `overtime`, `missing_clock_out`, `night`, `capture`), always stored complete and canonical. |
| `settings_version` | smallint | The shape of `settings`; `1`. |
| `is_default` | boolean | The company default. At most one per tenant (kept by the writer). |
| timestamps, `deleted_at` | | Archived rather than deleted; a policy something names cannot be force-deleted. |

**Index:** `(organization_id, is_default)`.

A policy is attached by `employee_schedule_assignments.attendance_policy_id`,
`work_schedules.attendance_policy_id` and `departments.attendance_policy_id` (all
nullable, null on delete) — see [scheduling tables](./scheduling-tables.md). Precedence:
assignment → schedule → department → company default → built-in fallback.

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
