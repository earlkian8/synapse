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
`…_create_attendance_policies`), which also added the minute buckets and `flags`. What
employees **ask for** and what is **closed** — `attendance_requests` and
`attendance_periods` — are below ([ADR 0039](../decisions/0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md),
`…_create_attendance_requests_and_periods`), which also made punches soft-deletable and
added the sign-off grant. **Where punches come from** — `work_locations`,
`employee_work_locations` and `attendance_devices` — and what capture records on each
punch are below ([ADR 0040](../decisions/0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md),
`…_create_work_locations_and_attendance_devices`), which also added `closed_at` for the
end-of-day job ([ADR 0041](../decisions/0041-attendance-days-close-themselves.md)).

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
| `flags` | json, nullable | Everything more specific than the status (ADR 0038): `late`, `undertime`, `half_day`, `late_absent`, `below_minimum`, `break_deducted`, `break_exceeded`, `unapproved_overtime`, `rest_day_worked`, `holiday_worked`; and from approved requests (ADR 0039) `official_business`, `remote_work`; from capture (ADR 0040) `outside_geofence`, `source_not_allowed`, `device_sequence_anomaly`, `clock_skew`; from the end-of-day job (ADR 0041) `auto_closed`, and `missing_clock_out` on a day it closed still open. |
| `first_in_at` / `last_out_at` | timestamp, nullable | Derived from the punches. |
| `worked_minutes` | uint | On-the-clock minutes judged by the policy: breaks excluded (but the paid part of a punched one counted, and an unpunched unpaid one deducted), clock-ins and clock-outs rounded, early minutes clipped when the policy does not count them. |
| `break_minutes` | uint | Total break time — punched, or the unpunched one the policy deducted. |
| `late_minutes` | uint | `fixed`: `first_in − scheduled_start_at`, less grace. `flexible`: against `core_start_at` instead. `hours_only`, or a policy that does not judge lateness: always 0. Clamped at 0. |
| `excused_late_minutes` | uint | The lateness grace forgave (ADR 0038) — per day, or out of a monthly allowance, which counts these down across the month. |
| `undertime_minutes` | uint | `fixed`: time clocked out before `scheduled_end_at`. `flexible`: the worse of leaving before `core_end_at` and falling below `required_minutes`. `hours_only`: `required_minutes − worked`. |
| `regular_minutes` | uint | `worked − overtime` (ADR 0038). With `overtime_minutes` a partition of the worked minutes. |
| `overtime_minutes` | uint | By the policy's basis: `daily` beyond its threshold (or `required_minutes`), `weekly` the part of the day that carries the Mon–Sun week's regular minutes past its threshold, `daily_and_weekly` both without counting a minute twice; a whole rest day or holiday when the policy says so; nothing below the minimum block. The built-in fallback is `worked − required_minutes`, clamped at 0. |
| `approved_overtime_minutes` | uint | The overtime that needs no further sign-off: all of it under a policy that does not require approval; under one that does, `min(overtime, granted)`, where granted is the larger of `signed_off_overtime_minutes` and the day's approved overtime requests (ADR 0039). |
| `night_minutes` | uint | Worked minutes inside the policy's night window, on the organisation's clock. A tag over worked minutes, not more of them. |
| `rest_day_minutes` | uint | Worked minutes on a day that was not a working day. A tag. |
| `holiday_minutes` | uint | Worked minutes on a `regular` or `special_non_working` holiday. A tag. |
| `is_manual` | boolean | True when entered/edited by HR. |
| `remarks` | text, nullable | |
| `approval_status` | string, nullable | *Needs sign-off* (ADR 0039), derived on every evaluation: `pending` while the day carries a review flag (`unapproved_overtime`, `outside_geofence`, `source_not_allowed`, `device_sequence_anomaly`, `clock_skew`, `auto_closed`), `approved` once signed off, null otherwise. |
| `approved_by` | FK → users, nullable | Who signed the day off. |
| `approved_at` | timestamp, nullable | |
| `signed_off_overtime_minutes` | uint, nullable | The overtime the sign-off granted — the day's overtime at that moment. Read back by the evaluator, so it survives a recompute. |
| `closed_at` | timestamp, nullable | When the end-of-day job handled the day's forgotten clock-out — auto-closed it or left it flagged (ADR 0041). Set once, so it is not handled twice. |
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
nullable, null on delete) — see [scheduling tables](./scheduling-tables.md) — and by
`work_locations.attendance_policy_id`. Precedence: assignment → schedule → department →
the primary work location → company default → built-in fallback.

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
| `punched_at` | timestamp | When the punch happened — for a punch a phone queued offline, the phone's time. |
| `device_punched_at` | timestamp, nullable | The phone's own time for a punch it queued offline (ADR 0040); null for a live punch. |
| `received_at` | timestamp, nullable | When the server received it. |
| `clock_skew_seconds` | int, nullable | The sender's clock minus the server's when it sent the punch (from `sent_at`). Beyond the policy's `max_clock_skew_minutes`, the day is flagged `clock_skew`. |
| `source` | string | `web \| mobile \| kiosk \| biometric \| manual \| correction \| system` — `correction` written by an approved correction (ADR 0039), `system` by the end-of-day job's auto-close (ADR 0041). |
| `attendance_device_id` | FK → attendance_devices, nullable | The kiosk or scanner that sent it (null on delete). |
| `external_id` | string(100), nullable | The sender's own id for the punch — a device's, or a phone's client id for a queued punch — so a resend is recognised. |
| `latitude` / `longitude` | decimal(10,7), nullable | GPS fix. |
| `accuracy` | decimal(8,2), nullable | Metres. |
| `work_location_id` | FK → work_locations, nullable | The nearest site of the employee's fence, or the device's site (null on delete). |
| `distance_meters` | uint, nullable | From that site's centre. |
| `within_geofence` | boolean, nullable | `distance − accuracy ≤ radius`. Null when not checked: no site to check against, or a kiosk / scanner punch. False, too, for a web or mobile punch with no position while the policy checks. |
| `photo` | string, nullable | Selfie path (public disk). |
| `note` | string, nullable | |
| `recorded_by` | FK → users, nullable | Null when the employee self-punched. |
| `attendance_request_id` | FK → attendance_requests, nullable | The correction that wrote this punch. |
| `replaced_by_request_id` | FK → attendance_requests, nullable | The correction that replaced it. |
| timestamps, `deleted_at` | | Soft-deleted when an HR edit or a correction replaces it, so the day keeps what it said before. |

**Indexes:** `(employee_id, punched_at)`; unique `(attendance_device_id, external_id)`;
`(employee_id, external_id)`.

## `attendance_requests`

What an employee asks attendance to know (ADR 0039).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid (by id on the mobile API, self-scoped). |
| `organization_id` | FK → organizations | Tenant. Cascade on delete. |
| `employee_id` | FK → employees | Whose. Cascade on delete. |
| `type` | string | `correction \| overtime \| official_business \| remote_work`. |
| `start_date` / `end_date` | date | Equal for the single-day types; at most 31 days apart. |
| `attendance_record_id` | FK → attendance_records, nullable | The day it concerns, when one exists (null on delete). |
| `payload` | json, nullable | `correction`: `time_in`, `break_start`, `break_end`, `time_out` (each "HH:MM" or null — null keeps the punch). `overtime`: `minutes`, `pre_approval`. `official_business` / `remote_work`: `start_time`, `end_time`, `location`. |
| `reason` | text | Required. |
| `attachment` | string, nullable | Path on the public disk. |
| `status` | string | `pending \| approved \| rejected \| cancelled`. |
| `reviewer_id` | FK → users, nullable | Who decided it. |
| `reviewed_at` | timestamp, nullable | |
| `review_note` | text, nullable | Shown to the employee. |
| `requested_by` | FK → users, nullable | Who filed it — HR can file on somebody's behalf. |
| timestamps, `deleted_at` | | |

**Indexes:** `(employee_id, start_date)`; `(organization_id, status)`; `type`.

## `attendance_periods`

The periods attendance closes on (ADR 0039). Generated on
`organizations.attendance_period_frequency` (`weekly \| bi_weekly \| semi_monthly \|
monthly`, default `semi_monthly`), contiguous and never overlapping; reminders follow
`organizations.attendance_lock_reminder_days` (default 2).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `organization_id` | FK → organizations | Tenant. Cascade on delete. |
| `start_date` / `end_date` | date | |
| `status` | string | `open \| locked`. Nothing about a day inside a locked period can change. |
| `locked_by` / `locked_at` | FK → users / timestamp, nullable | |
| `lock_note` | text, nullable | Why it was locked with the checklist still open. |
| `unlocked_by` / `unlocked_at` / `unlock_reason` | nullable | The last unlock; always with a reason. |
| `export_path` | string, nullable | The period summary written at the lock, on the private `local` disk. |
| `reminded_at` | timestamp, nullable | When the lock reminder went out, so it goes once. |
| timestamps | | |

**Indexes:** unique `(organization_id, start_date)`; `(organization_id, status)`.

## `work_locations`

A company's sites (ADR 0040) — each a fence punches are checked against, and a link in
the shift and policy precedence chains. See [Work Locations](../modules/work-locations.md).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `organization_id` | FK → organizations | Tenant. Cascade on delete. |
| `name` | string | Unique per tenant among live rows (validated). |
| `address` | string, nullable | |
| `latitude` / `longitude` | decimal(10,7) | The fence's centre. |
| `radius_meters` | uint | Default 150; 25–5000 (validated). |
| `default_work_schedule_id` | FK → work_schedules, nullable | The shift for people based here, between department and organisation (null on delete). |
| `attendance_policy_id` | FK → attendance_policies, nullable | Likewise for the policy (null on delete). |
| `is_active` | boolean | Default true. An inactive site checks nothing and sits in neither chain. |
| timestamps, `deleted_at` | | Archived; a site any punch names cannot be force-deleted. |

**Index:** `(organization_id, is_active)`.

## `employee_work_locations`

Who is based where. Scoped through both of its parents.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | Cascade on delete. |
| `work_location_id` | FK → work_locations | Cascade on delete. |
| `is_primary` | boolean | At most one per employee (kept by the writer). The primary — or an only site — is the one the precedence chains read. |
| timestamps | | |

**Indexes:** unique `(employee_id, work_location_id)`; `work_location_id`.

## `attendance_devices`

Kiosks and biometric scanners (ADR 0040), each authenticated by its own key. See
[Attendance Devices](../modules/attendance-devices.md).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `organization_id` | FK → organizations | Tenant. Cascade on delete. |
| `name` | string | |
| `type` | string | `kiosk \| biometric`; fixed once created. |
| `work_location_id` | FK → work_locations, nullable | Where it is; its punches are recorded there (null on delete). |
| `api_key_hash` | string(64), unique | SHA-256 of the key. The key (`sdk_` + 40 characters) is shown once and never stored. |
| `api_key_hint` | string(8) | The key's last four characters, to tell keys apart. |
| `last_seen_at` | timestamp, nullable | Updated on every authenticated call. |
| `is_active` | boolean | An inactive device's key is refused. |
| `csv_mapping` | json, nullable | How the device's CSV export maps onto a punch, remembered from the last import. |
| `created_by` | FK → users, nullable | |
| timestamps, `deleted_at` | | Removing a device deactivates it and keeps its punches. |

**Index:** `(organization_id, is_active)`.

## Mobile auth

The token-authenticated API ([ADR 0010](../decisions/0010-attendance-and-mobile-api.md))
uses Laravel Sanctum's standard `personal_access_tokens` table (published migration). The
`User` model gains `HasApiTokens`; the web's session auth is unchanged.
