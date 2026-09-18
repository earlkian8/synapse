# Database: scheduling tables

The tables behind **which shift applies to whom, and when** — the plan that
[Attendance](../modules/attendance.md) judges the record against. Created by
`…_create_schedule_patterns_assignments_and_roster`; every one is tenant-scoped
(`organization_id`). See
[ADR 0037](../decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md).

`work_schedules` itself is documented with
[Work Schedule & Holidays](./work-schedule-holidays-tables.md); the columns ADR 0037 adds
to it are listed at the bottom of this page.

`App\Support\Attendance\ShiftResolver` is the only reader of all three, and it walks them
in one precedence chain: **roster entry → assignment → `employees.work_schedule_id` →
department default → organisation default → built-in fallback**.

## `work_schedule_days`

One row per day of a template's cycle. A weekly schedule has seven (1 = Monday); a
rotation has as many as its `cycle_length_days`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `work_schedule_id` | FK → work_schedules | Cascade on delete. |
| `day_index` | usmallint | `1..cycle_length_days`. For a week, 1 is Monday. |
| `is_rest_day` | boolean | Not a working day. Segments and windows are cleared when it is set. |
| `segments` | json, nullable | A list of `{"start":"HH:MM","end":"HH:MM"}` — one for a normal shift, two or more for a split one. An end at or before its start crosses midnight. |
| `required_minutes` | usmallint | What the day asks for. Defaults to how long its segments actually run when nothing is given. |
| `core_start` / `core_end` | string(5), nullable | `flexible` only: the window everyone must be present for. Lateness is judged against `core_start`. |
| `earliest_start` / `latest_end` | string(5), nullable | `flexible` only: the window punches are accepted in (used by `workDateFor()`). |
| `unpaid_break_minutes` | usmallint | The expected break. Recorded now; whether it is auto-deducted is an attendance **policy** decision, not this phase's. |
| timestamps | | |

**Indexes:** unique `(work_schedule_id, day_index)`; `organization_id`.

**Written by** `App\Support\Attendance\SchedulePatternWriter` only, which replaces a
template's whole cycle in a transaction and then refreshes the schedule's legacy summary
columns.

**A schedule with no rows here still resolves.** `WorkSchedule::patternDays()`
synthesises seven from `work_days` / `start_time` / `end_time` / `required_hours` — which
is exactly what the pre-pattern code did — so a factory, a seeder or an import that only
sets the old columns keeps answering the way it always has.

## `employee_schedule_assignments`

Which schedule an employee works, and from when. Before this, the answer was
`employees.work_schedule_id` alone, so a change was retroactive to every screen.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by **hashid** in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `work_schedule_id` | FK → work_schedules | Cascade on delete. Resolved `withTrashed`, so an archived shift still answers. |
| `effective_from` | date | Inclusive. |
| `effective_to` | date, nullable | Inclusive. Null is open-ended — the assignment in force until another replaces it. |
| `cycle_offset` | usmallint | Where in a rotation this person starts. Two crews on one four-on, four-off template are offset by 4. |
| `attendance_policy_id` | FK → attendance_policies, nullable | ADR 0038: judges this person by a policy other than their shift's while the assignment runs — the most specific link in the policy chain. Null on delete. A split carries it onto both halves. |
| `assigned_by` | FK → users, nullable | Null on delete. |
| timestamps | | |

**Indexes:** `(employee_id, effective_from)`; `organization_id`.

**Written by** `App\Support\Attendance\ScheduleAssigner` only, which keeps two
invariants: ranges never overlap for one employee (a new range closes, trims or **splits**
whatever it lands on, so a fortnight on another shift returns the old one afterwards),
and `employees.work_schedule_id` points at whichever assignment covers the organisation's
**today** — not simply the one just written, which may start next month.

Overlap is enforced by the writer rather than by a database constraint: Postgres
exclusion constraints would do it in the engine, but the committed default connection is
SQLite, which has none.

**Backfilled** on migration: one open-ended row per employee that had a
`work_schedule_id`, dated from their `date_hired` (or their record's `created_at`).

## `shift_roster_entries`

"On this date, this person works this instead" — the one-off that beats every schedule
and assignment: a swap, a Saturday call-in, a day off.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by **hashid** in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `date` | date | The single date overridden. |
| `work_schedule_id` | FK → work_schedules, nullable | Borrow another template's pattern for that date. Null on delete. |
| `segments` | json, nullable | …or give the day hours of its own, in the same shape as `work_schedule_days.segments`. These win over a borrowed template. |
| `required_minutes` | usmallint, nullable | What the overridden day asks for. Defaults to how long its segments run. |
| `is_rest_day` | boolean | A day off instead. Clears the schedule and the segments. |
| `reason` | string, nullable | Why — shown on the roster cell's tooltip and in the modal. |
| `created_by` | FK → users, nullable | Null on delete. |
| timestamps | | |

**Indexes:** unique `(employee_id, date)`; `(organization_id, date)`.

**Written by** `App\Support\Attendance\RosterWriter` only (`updateOrCreate`, so setting an
override twice corrects it rather than stacking a second). Clearing one hands the day back
to the precedence chain.

## Columns added to existing tables

| Table | Column | Notes |
| --- | --- | --- |
| `work_schedules` | `type` | `fixed \| flexible \| hours_only` — how the day is judged. |
| `work_schedules` | `cycle_length_days` | 7 for a week; N for a rotation. Capped at 84 (twelve weeks). |
| `work_schedules` | `cycle_anchor_date` | date, nullable — which date is day 1. Required for a rotation; ignored for a week. |
| `work_schedules` | `weekly_required_minutes` | uint, nullable — an `hours_only` weekly target, reported on the weekly and monthly views rather than as a daily status. |
| `departments` | `default_work_schedule_id` | FK, nullable — the hours a member works with no assignment of their own. |
| `organizations` | `default_work_schedule_id` | FK, nullable — the company's default hours. |
| `work_schedules` | `attendance_policy_id` | FK → attendance_policies, nullable, null on delete (ADR 0038) — how days on this shift are judged, unless an assignment names a policy. |
| `departments` | `attendance_policy_id` | FK → attendance_policies, nullable, null on delete (ADR 0038) — how the department's people are judged, below their assignment's and schedule's. |

`work_schedules.start_time`, `end_time` and `work_days` remain, **read-only**: a summary
of the pattern (the first working day's first segment, its last segment's end, and the
weekday names that are not rest days), refreshed by `SchedulePatternWriter` for the
employee screens, the mobile session and the assistant's employee module. A rotation has
no weekday names to summarise, so its `work_days` is empty.
