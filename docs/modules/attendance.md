# Attendance

The **Daily Time Record (DTR)**: employees clock in/out (and breaks) from the web or a
mobile app; HR sees the whole team's day, corrects records, and approves. Worked hours,
lateness, undertime and overtime are computed server-side against **the shift that
applies to that person on that day**, on the **organisation's clock**. The *why* is in
[ADR 0010](../decisions/0010-attendance-and-mobile-api.md) (the two-table punch model +
the token API for mobile), [ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
(local time, shift-anchored work dates, frozen rules, holidays) and
[ADR 0037](../decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)
(day patterns, dated assignments, roster overrides, the resolver); this is the *how*.
Everything is tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/attendance` · API prefix: `/api` (Sanctum)
> Sidebar: Workforce → Attendance (HR) and Workforce → My Attendance (self-service)

## Surfaces

- **`/attendance`** — the **HR attendance workspace**: stat cards (present / late /
  absent / on-leave / avg hours), a **period-aware stepper** (prev / today / next +
  picker, stepping by day / week / month — "today" is the organisation's), search +
  department filters, and four tabs over the same roster (which is built from *every*
  employee, so people with no punches still appear as **Absent** / **Holiday** /
  **Day off** / **On leave**):
  - **Today's Log** — a sortable table: avatar + name, time in / out, computed hours, a
    **status pill** and an **anomaly flag** (late by N, missing time-out, left early,
    unscheduled absence), beside an **exceptions panel** (the day's problems grouped by
    kind, each actionable). A **status filter** narrows it (present / late / … / holiday).
  - **Weekly View** — a matrix: employees down, Mon–Sun across, each cell a status tile
    (a holiday tile names the holiday); clicking a cell jumps to that day's log.
  - **Roster** — the **plan** rather than the record: employees down, the week across,
    each cell the shift the resolver says applies, with the schedule's name under it and
    a **pin** where a one-off override is in force. The one attendance view whose stepper
    is **not capped at today** — planning next week is the point of it. Clicking a cell
    opens the override (another shift's hours for that date, hours of its own, or a day
    off, with a reason); **Assign a schedule** puts the week's roster on a shift from a
    date. Gated on `attendance.roster.view` / `attendance.roster.manage`.
  - **Monthly Report** — one summary row per employee (present days, late count, absences,
    holidays, overtime, attendance-rate %) with an inline worked-hours **sparkline**. A
    holiday is neither attendance nor absence, so it stays out of the rate.

  Opening any record reveals the **day-detail modal** — centred, like every other detail
  surface in the app, and read top to bottom in the order somebody checks a day: the
  header states who and when (person, status, date, **the schedule and holiday the day
  was judged by**, and whether it was entered by hand), a **totals band** under it
  (worked / break / late / overtime) stays put while the body scrolls, and the body
  carries the **punch trail** followed by the remarks and the sign-off.

  Each punch is one row — time, source, GPS pin, note — with **the photo taken at it on
  that row**, large enough to recognise a face and opening full-size in a new tab. A
  punch with no photo still gets a tile, saying which of three things happened: the
  source never takes one (a web, kiosk or biometric punch), the mobile app was expected
  to and did not, or the file has since gone. "No evidence" and "evidence missing" are
  different findings, and an empty space states neither. The section header counts them
  ("4 punches · 2 with a photo"), and the remarks and approval blocks are likewise always
  drawn — an unwritten remark says so rather than leaving a gap. HR actions (correct,
  **re-apply schedule**, approve, delete) sit in a pinned footer.

  **Recording or correcting a day** opens its own centred modal, with the four punches on
  one row in the order they happen and a running read-out of what they add up to
  ("8h 30m worked after a 45m break") — lateness and overtime stay the server's, since
  they need the employee's schedule. Times are readings on the organisation's clock for
  the work date; one earlier than the time before it is the next morning, so a night
  shift is entered as in 22:00, out 06:00.

  **Re-apply schedules** (page header, `attendance.manage`) re-judges every recorded day
  in the period on screen — the day, week or month, narrowed to the department filter —
  by each employee's current schedule and the holiday calendar. See *Frozen rules* below.
- **`/attendance/me`** — employee **self-service**: a live **clock card** (the
  organisation's time and date) whose primary button flips with the day's state
  (Clock in → Start break → End break → Clock out), capturing geolocation (and an optional
  selfie) on each punch; plus today's punch timeline, a this-month summary, and recent DTR
  history. The card shows the **current shift**: a night-shift worker at 02:00 sees the
  shift they started last night, not an empty new date.
- **The assistant** reads attendance two ways: `find_attendance` lists an employee's
  records on request, and — when a chat turn is *about* somebody — the module contributes
  a 30-day read-out (days worked against days scheduled, punctuality, absences, holidays,
  average hours, overtime, the last five days) to the retrieved brief the answer is
  composed from. Anybody's needs `attendance.view`; your own needs nothing, because
  `/attendance/me` needs nothing. See
  [ADR 0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md).
- **Mobile API** (`/api`, token-authenticated) — the same clock engine for the DTR app:
  `POST /api/auth/login`, `GET /api/attendance/today` (the current shift, as on the web
  card), `POST /api/attendance/punch` (with GPS + selfie), `GET /api/attendance/records`,
  `GET /api/attendance/summary`. The session payload's `organization.timezone` is the
  clock the app shows every time on.

The daily log stays a per-person table — the right tool for "what happened today" — while
the weekly/monthly tabs give the depth and the exceptions panel gives the utility (so it
reads as an ERP module, not a spreadsheet with a stylesheet). Self-service is a **single
big clock**, the way a punch clock should feel.

## Data model

Two tables hold the record (see [attendance tables](../database/attendance-tables.md));
three more hold the plan (see [scheduling tables](../database/scheduling-tables.md)):

- **`attendance_records`** — one row per employee per day: what the day is judged
  against (`work_schedule_id`, the schedule's clock-face `scheduled_start/end`, the shift
  as UTC instants `scheduled_start_at/end_at`, and the frozen `rules`), the derived
  `status` and minute totals (`worked / break / late / undertime / overtime`),
  `first_in_at` / `last_out_at`, an `is_manual` flag, `remarks`, and a correction/overtime
  approval lifecycle (`approval_status`, `approved_by/at`). Unique on
  `(employee_id, work_date)`.
- **`attendance_punches`** — the raw punch events the summary is built from: `type`
  (`clock_in | clock_out | break_start | break_end`), `punched_at`, `source`
  (`web | mobile | kiosk | biometric | manual`), GPS (`latitude / longitude / accuracy`),
  an optional `photo` selfie, a `note`, and `recorded_by` (null when self-punched).
- **`work_schedule_days`**, **`employee_schedule_assignments`** and
  **`shift_roster_entries`** — a template's cycle, who works it over which dates, and the
  one-off overrides (ADR 0037).

## How it computes

The punch engine and calculator are **canonical support classes** (`app/Support/Attendance`)
shared by the web, the mobile API and the assistant, so a punch behaves identically
everywhere.

### Local time

Storage is UTC; judgement is on the organisation's clock (`organizations.timezone`, set
on the [Company Profile](./company-profile.md)). `App\Support\OrganizationClock` is the
only code that knows the zone — `now()`, `today()`, `at($date, $time)` (a wall-clock
reading as the UTC instant it names), `localDate()` and `local()`. The board's "today",
the self-service month, the assistant's 30-day window, the export's time columns and the
seeder all read it. The web app and the mobile app format times with the same zone.

### Which shift applies

**`ShiftResolver::for(employee, date)`** is the only answer to "what is this person due
to work?" — the board, the roster, the punch engine, the mobile session and the assistant
all ask it. It walks one precedence chain, most specific first:

**roster override → dated assignment → `employees.work_schedule_id` → department default
→ organisation default → fallback** (Mon–Fri 08:00–17:00, eight hours).

It returns a `ResolvedShift`: the day's `type`, whether it is a working day, its
`segments` as clock-face pairs *and* as ordered UTC instants (each rolled past the one
before it, so a split shift's evening half stays after its morning half), required and
grace minutes, the core and accept windows, the schedule's name, and **`source`** — which
link in the chain won, so the roster can say *why*. `forMany()` answers for a whole roster
over a whole range in **five queries whatever the range**.

A template's cycle is indexed by weekday for a week, and by days elapsed from its anchor
(plus the assignment's `cycle_offset`) for a rotation — so two crews four apart on a
four-on, four-off shift never work the same day.

### The work date

**`AttendanceClock::workDateFor(employee, instant, type)`** decides which day a punch
belongs to:

1. an **open shift** — clocked in within the last 16 hours (`MAX_SHIFT_SPAN_HOURS`) and not
   out — claims it, so a night shift's 06:00 clock-out closes the day that opened at 22:00;
2. otherwise a **clock-in** belongs to today's or yesterday's *working* day whose window —
   from 4 hours (`EARLY_CLOCK_IN_HOURS`) before its start to its end — contains it, so a
   21:30 or a late 00:30 clock-in belongs to the 22:00 shift;
3. otherwise the organisation's calendar date.

### Punching

**`AttendanceClock::punch(employee, type, context)`** runs in a transaction with a row
lock on the employee: resolve the work date, find the day (or build it **unsaved**, with
its rules frozen), **validate the transition** (no double clock-in, no clock-out before
clock-in, breaks only while clocked in), and only then save the day, write the punch and
recompute. **A refused punch writes nothing.** `nextExpected()` / `allowed()` drive the
UI's buttons; `currentRecord()` is the day the clock card shows. `applyManualPunches()`
backs HR manual entry and corrections.

### Frozen rules

When a day opens, `AttendanceClock::snapshot()` stores the resolved shift's edges, its
instants (the end rolled to the next morning when it is at or before the start) and a
**`DayRules`** snapshot in `rules`: `grace_minutes`, `required_minutes`, `is_working_day`,
`work_schedule_id`, `schedule_name`, `holiday_type`, `holiday_name`, and — from
`version: 2` — the schedule's `type`, the day's `segments`, the flexible core window as
instants, `unpaid_break_minutes` and the resolver's `source`. Every
recompute reads the snapshot, so **editing a schedule never changes a day already
recorded**. `reapplySchedule()` — the day modal's *Re-apply schedule* and the board's
*Re-apply schedules* — replaces it with the employee's current schedule and holiday, and is
activity-logged. A record from before snapshots existed is given one from what it recorded
(the schedule it names, archived or not) the first time it is recomputed.

### The calculator

**`AttendanceCalculator::recompute(record, DayRules, onLeave)`** is pure — no database,
no clock. It walks the ordered punches as a state machine: worked minutes (on-the-clock,
excluding breaks), break minutes, **overtime** (worked − `required_minutes`), and the
**status**. **Late** and **undertime** depend on the snapshot's schedule type:

| Type | Late | Undertime |
| --- | --- | --- |
| `fixed` | after `scheduled_start_at + grace` | clocked out before `scheduled_end_at` |
| `flexible` | after `core_start_at + grace` | the worse of leaving before `core_end_at` and falling below `required_minutes` |
| `hours_only` | never | `required_minutes − worked` |

A **split shift** needs no special case: clocking out ends the on-clock stretch, so the
gap between the halves is neither worked nor break; late is against the first segment's
start and undertime against the last segment's end.

A day with no punches resolves, in order:
`on_leave` (approved leave) → `holiday` (a `regular` or `special_non_working` holiday) →
`day_off` (not a working day) → `absent`. A `special_working` holiday is an ordinary
working day. A clocked-in-but-not-out day is `incomplete`; a holiday somebody worked is
judged like any day and keeps its holiday in the snapshot. The roster queries synthesise
no-punch days through the same `noPunchStatus()`, loading the range's holidays once.

Leave-awareness checks approved leave covering the date, so an approved leave day is
never flagged absent.

## Maintenance commands

- **`php artisan attendance:recompute {--organization=} {--from=} {--to=} {--dry-run}`** —
  gives every day without a snapshot the snapshot it should have had, recomputes each day
  in range from its punches, and prints a table of the days whose status or totals moved
  ("late: 0 → 30"). `--dry-run` prints the same and writes nothing. Organisations are
  walked one at a time on their own clocks; `--organization` takes an id or a slug. It
  never re-applies a *changed* schedule to a day that has a snapshot — that stays HR's
  explicit action.
- **`php artisan attendance:prune-orphan-days {--organization=} {--dry-run}`** — deletes the
  empty days refused overnight clock-outs used to leave: no punches, not entered by hand,
  no remarks, and the same employee's previous day left open. Run with `--dry-run` first.

Demo data written before ADR 0036 stored clock-face times as UTC, so it is re-seeded
rather than recomputed (`AttendanceSeeder` now writes real instants, skips rest days and
non-working holidays, and never seeds a punch that has not happened yet).

## Permissions

`attendance.view` (the board & records), `attendance.manage` (manual entry, corrections,
re-applying schedules, approvals), `attendance.clock` (record your own punches),
`attendance.roster.view` (the roster tab) and `attendance.roster.manage` (overrides and
assigning schedules). Built-in roles: **HR Manager** gets all five; **Staff** gets
`attendance.clock` for self-service. Assigning a schedule from the **employee profile**
reuses `employees.update` instead — it is an edit to that person's record.

## Assistant

The agent's **Attendance** capability (gated by `attendance.view`) exposes:

- **`find_attendance`** — an employee's recent DTRs.
- **`record_punch`** — clock an employee in/out (gated by `attendance.manage`).
- **`find_shifts`** — "who works Saturday?", "what is Ana's shift next week?" — the
  roster, not the records, with each shift's source spelled out. Gated by
  `attendance.roster.view`; your own shifts need nothing. Capped at 31 days, 200 people
  and 40 cards.
- **`set_roster_entry`** — "put Ben on the night shift on the 20th" (gated by
  `attendance.roster.manage`).

Punches route through `AttendanceClock` and overrides through `RosterWriter`, so totals,
status, the shift a night punch belongs to, and the history left behind are the same
whoever asked. Card times are shown on the organisation's clock.
