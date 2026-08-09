# Attendance 

The **Daily Time Record (DTR)**: employees clock in/out (and breaks) from the web or a
mobile app; HR sees the whole team's day, corrects records, and approves. Worked hours,
lateness, undertime and overtime are computed server-side against each employee's
**work schedule**. The *why* (the two-table punch model + the token API for mobile) is
in [ADR 0010](../decisions/0010-attendance-and-mobile-api.md); this is the *how*.
Everything is tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/attendance` · API prefix: `/api` (Sanctum)
> Sidebar: Workforce → Attendance (HR) and Workforce → My Attendance (self-service)

## Surfaces

- **`/attendance`** — the **HR attendance workspace**: stat cards (present / late /
  absent / on-leave / avg hours), a **period-aware stepper** (prev / today / next +
  picker, stepping by day / week / month), search + department filters, and three tabs
  over the same roster (which is built from *every* employee, so people with no punches
  still appear as **Absent** / **Day off** / **On leave**):
  - **Today's Log** — a sortable table: avatar + name, time in / out, computed hours, a
    **status pill** and an **anomaly flag** (late by N, missing time-out, left early,
    unscheduled absence), beside an **exceptions panel** (the day's problems grouped by
    kind, each actionable). A **status filter** narrows it (present / late / …).
  - **Weekly View** — a matrix: employees down, Mon–Sun across, each cell a status tile;
    clicking a cell jumps to that day's log.
  - **Monthly Report** — one summary row per employee (present days, late count, absences,
    overtime, attendance-rate %) with an inline worked-hours **sparkline**.

  Opening any record reveals the **day-detail drawer** — the full punch timeline (each
  punch's time, source, GPS pin and selfie), the computed totals, and HR actions (correct,
  approve, delete).
- **`/attendance/me`** — employee **self-service**: a live **clock card** whose primary
  button flips with the day's state (Clock in → Start break → End break → Clock out),
  capturing geolocation (and an optional selfie) on each punch; plus today's punch
  timeline, a this-month summary, and recent DTR history.
- **Mobile API** (`/api`, token-authenticated) — the same clock engine for a future DTR
  app: `POST /api/auth/login`, `GET /api/attendance/today`, `POST /api/attendance/punch`
  (with GPS + selfie), `GET /api/attendance/records`.

The daily log stays a per-person table — the right tool for "what happened today" — while
the weekly/monthly tabs give the depth and the exceptions panel gives the utility (so it
reads as an ERP module, not a spreadsheet with a stylesheet). Self-service is a **single
big clock**, the way a punch clock should feel.

## Data model

Two tables (see [attendance tables](../database/attendance-tables.md)):

- **`attendance_records`** — one row per employee per day: a snapshot of the schedule
  that applied (`scheduled_start/end`, `work_schedule_id`), the derived `status` and
  minute totals (`worked / break / late / undertime / overtime`), `first_in_at` /
  `last_out_at`, an `is_manual` flag, `remarks`, and a correction/overtime approval
  lifecycle (`approval_status`, `approved_by/at`). Unique on `(employee_id, work_date)`.
- **`attendance_punches`** — the raw punch events the summary is built from: `type`
  (`clock_in | clock_out | break_start | break_end`), `punched_at`, `source`
  (`web | mobile | kiosk | biometric | manual`), GPS (`latitude / longitude / accuracy`),
  an optional `photo` selfie, a `note`, and `recorded_by` (null when self-punched).

## How it computes

The punch engine and calculator are **canonical support classes** (`app/Support/Attendance`)
shared by the web, the mobile API and the assistant, so a punch behaves identically
everywhere:

- **`AttendanceClock`** — `punch(employee, type, context)` finds/creates today's record
  (snapshotting the schedule), **validates the transition** (no double clock-in, no
  clock-out before clock-in, breaks only while clocked in), writes the punch, then
  recomputes and saves. `nextExpected()` / `allowed()` drive the UI's buttons.
  `applyManualPunches()` backs HR manual entry/corrections.
- **`AttendanceCalculator::recompute()`** — walks the ordered punches as a state machine:
  worked minutes (on-the-clock, excluding breaks), break minutes, **late** (`first_in −
  (scheduled_start + grace)`), **undertime** (vs `scheduled_end`), **overtime** (worked −
  `required_hours`), and the **status**. A non-working day (weekday ∉ the schedule's
  `work_days`) is `day_off`; an approved leave day is `on_leave`; a clocked-in-but-not-out
  day is `incomplete`.

Leave-awareness reuses `LeaveRequest::coversDate()` so an approved leave day is never
flagged absent.

## Permissions

`attendance.view` (the board & records), `attendance.manage` (manual entry, corrections,
approvals), `attendance.clock` (record your own punches). Built-in roles: **HR Manager**
gets all three; **Staff** gets `attendance.clock` for self-service.

## Assistant

The agent's **Attendance** capability (gated by `attendance.view`) exposes
`find_attendance` (an employee's recent DTRs) and `record_punch` (clock an employee in/out;
gated by `attendance.manage`) — both routed through `AttendanceClock`, so totals and
status stay correct.
