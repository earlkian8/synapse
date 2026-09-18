# Attendance

The **Daily Time Record (DTR)**: employees clock in/out (and breaks) from the web or a
mobile app; HR sees the whole team's day, corrects records, and approves. Worked hours,
lateness, undertime and overtime are computed server-side against **the shift that
applies to that person on that day**, on the **organisation's clock**. The *why* is in
[ADR 0010](../decisions/0010-attendance-and-mobile-api.md) (the two-table punch model +
the token API for mobile), [ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
(local time, shift-anchored work dates, frozen rules, holidays) and
[ADR 0037](../decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)
(day patterns, dated assignments, roster overrides, the resolver) and
[ADR 0038](../decisions/0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md)
(attendance policies, the day evaluator, minute buckets and flags — detailed in
[Attendance Policies](./attendance-policies.md)); this is the *how*. Everything is
tenant-scoped (ADR 0005).

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
    unscheduled absence, a half day, a break that ran over), beside an **exceptions
    panel** (the day's problems grouped by kind — including **half days** — each
    actionable). A **status filter** narrows it (present / late / half day / … / holiday).
  - **Weekly View** — a matrix: employees down, Mon–Sun across, each cell a status tile
    (a holiday tile names the holiday); clicking a cell jumps to that day's log.
  - **Roster** — the **plan** rather than the record: employees down, the week across,
    each cell the shift the resolver says applies, with the schedule's name under it and
    a **pin** where a one-off override is in force. The one attendance view whose stepper
    is **not capped at today** — planning next week is the point of it. Clicking a cell
    opens the override (another shift's hours for that date, hours of its own, or a day
    off, with a reason); **Assign a schedule** puts the week's roster on a shift from a
    date. Gated on `attendance.roster.view` / `attendance.roster.manage`.
  - **Monthly Report** — one summary row per employee (present days, late count, **half
    days**, absences, holidays, overtime — with how much of it **awaits approval** — a
    **night** column when any policy counts night work, attendance-rate %) with an inline
    worked-hours **sparkline**. A holiday is neither attendance nor absence, so it stays
    out of the rate; a half day counts as attended. **Payroll summary** downloads the
    month as the [period summary](./attendance-policies.md#payroll-period-summary).

  Opening any record reveals the **day-detail modal** — centred, like every other detail
  surface in the app, and read top to bottom in the order somebody checks a day: the
  header states who and when (person, status, date, **the schedule, holiday and
  attendance policy the day was judged by**, and whether it was entered by hand), a
  **totals band** under it (worked / regular / break / late / overtime — noting overtime
  that awaits approval — plus night, rest-day and holiday minutes when the day has any)
  stays put while the body scrolls, and the body opens with the day's **flags** as chips
  ("Half day", "Unpunched break deducted", "Overtime awaiting approval", …) before the
  **punch trail**, the remarks and the sign-off.

  Each punch is one row — time, source, GPS pin, note — with **the photo taken at it on
  that row**, large enough to recognise a face and opening full-size in a new tab. A
  punch with no photo still gets a tile, saying which of three things happened: the
  source never takes one (a web, kiosk or biometric punch), the mobile app was expected
  to and did not, or the file has since gone. "No evidence" and "evidence missing" are
  different findings, and an empty space states neither. The section header counts them
  ("4 punches · 2 with a photo"), and the remarks and approval blocks are likewise always
  drawn — an unwritten remark says so rather than leaving a gap. HR actions (correct,
  **re-apply rules**, approve, delete) sit in a pinned footer.

  **Recording or correcting a day** opens its own centred modal, with the four punches on
  one row in the order they happen and a running read-out of what they add up to
  ("8h 30m worked after a 45m break") — lateness and overtime stay the server's, since
  they need the employee's schedule. Times are readings on the organisation's clock for
  the work date; one earlier than the time before it is the next morning, so a night
  shift is entered as in 22:00, out 06:00.

  **Re-apply rules** (page header, `attendance.manage`) re-judges every recorded day
  in the period on screen — the day, week or month, narrowed to the department filter —
  by each employee's current schedule, attendance policy and the holiday calendar, in
  date order. See *Frozen rules* below.
- **`/attendance/me`** — employee **self-service**: a live **clock card** (the
  organisation's time and date) whose primary button flips with the day's state
  (Clock in → Start break → End break → Clock out), capturing geolocation (and an optional
  selfie) on each punch; plus today's punch timeline, a this-month summary, and recent DTR
  history. The card shows the **current shift**: a night-shift worker at 02:00 sees the
  shift they started last night, not an empty new date.
- **The assistant** reads attendance two ways: `find_attendance` lists an employee's
  records on request, and — when a chat turn is *about* somebody — the module contributes
  a 30-day read-out (days worked against days scheduled, punctuality, absences, half days,
  holidays, average hours, overtime and how much of it awaits approval, night, rest-day and
  holiday minutes, over-long breaks, the last five days) to the retrieved brief the answer
  is composed from. Anybody's needs `attendance.view`; your own needs nothing, because
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
  `status` and `flags`, the minute totals (`worked / break / late / excused late /
  undertime`) and the **buckets** (`regular / overtime / approved overtime / night /
  rest day / holiday`, ADR 0038),
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

1. an **open shift** — clocked in within its policy's `max_shift_span_minutes` (16 hours
   by default) and not out — claims it, so a night shift's 06:00 clock-out closes the day
   that opened at 22:00;
2. otherwise a **clock-in** belongs to today's or yesterday's *working* day whose window —
   from the policy's `early_clock_in_minutes` (4 hours by default) before its start to its
   end — contains it, so a 21:30 or a late 00:30 clock-in belongs to the 22:00 shift;
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
instants, `unpaid_break_minutes` and the resolver's `source`; from `version: 3` — the
**attendance policy** the day is judged by (id, name, source and complete settings, chosen
by `PolicyResolver`). Every recompute reads the snapshot, so **editing a schedule or a
policy never changes a day already recorded**. `reapplySchedule()` — the day modal's and
the board's *Re-apply rules* — replaces it with the employee's current schedule, policy and
holiday, and is activity-logged. A record from before snapshots existed is given one from what it recorded
(the schedule it names, archived or not) the first time it is recomputed.

### The calculator

**`AttendanceCalculator::evaluate(punches, DayRules, DayContext)`** is pure — no database,
no clock — and returns a `DayResult`; `recompute()` writes one onto a record. It rounds,
pairs the punches into work and break intervals, applies the policy's break rules, judges
late and undertime, splits the minutes into buckets, applies the policy's thresholds, and
sets the status and flags — the full order and every setting are in
[Attendance Policies](./attendance-policies.md#how-a-day-is-evaluated). **Late** and
**undertime** still depend first on the snapshot's schedule type:

| Type | Late | Undertime |
| --- | --- | --- |
| `fixed` | after `scheduled_start_at`, less grace | clocked out before `scheduled_end_at` |
| `flexible` | after `core_start_at`, less grace | the worse of leaving before `core_end_at` and falling below `required_minutes` |
| `hours_only` | never | `required_minutes − worked` |

Grace is the policy's when it sets one, and the schedule's otherwise; a policy can also
stop judging lateness, or judge undertime on hours alone. Under the built-in fallback the
evaluator reaches exactly the numbers the pre-policy calculator did.

A **split shift** needs no special case: clocking out ends the on-clock stretch, so the
gap between the halves is neither worked nor break; late is against the first segment's
start and undertime against the last segment's end.

A day with no punches resolves, in order:
`on_leave` (approved leave) → `holiday` (a `regular` or `special_non_working` holiday) →
`day_off` (not a working day) → `absent`. A `special_working` holiday is an ordinary
working day. A clocked-in-but-not-out day is `incomplete`. A punched working day can be a
**`half_day`**, or `absent`, when the policy's lateness or short-day thresholds say so. A
holiday somebody worked is judged like any day, keeps its holiday in the snapshot, and its
minutes are bucketed as holiday minutes. The roster queries synthesise no-punch days
through the same `noPunchStatus()`, loading the range's holidays once.

Leave-awareness checks approved leave covering the date, so an approved leave day is
never flagged absent.

## Maintenance commands

- **`php artisan attendance:recompute {--organization=} {--from=} {--to=} {--dry-run}`** —
  gives every day without a snapshot the snapshot it should have had, recomputes each day
  in range from its punches, and prints a table of the days whose status or totals moved
  ("late: 0 → 30"). `--dry-run` prints the same and writes nothing. Organisations are
  walked one at a time on their own clocks; `--organization` takes an id or a slug. It
  never re-applies a *changed* schedule or policy to a day that has a snapshot — that
  stays HR's explicit action. It walks days in date order, and it is how the minute
  buckets ADR 0038 added are filled for days recorded before them (a pre-policy snapshot
  is judged by the built-in fallback, so no status or total moves).
- **`php artisan attendance:prune-orphan-days {--organization=} {--dry-run}`** — deletes the
  empty days refused overnight clock-outs used to leave: no punches, not entered by hand,
  no remarks, and the same employee's previous day left open. Run with `--dry-run` first.

Demo data written before ADR 0036 stored clock-face times as UTC, so it is re-seeded
rather than recomputed (`AttendanceSeeder` now writes real instants, skips rest days and
non-working holidays, and never seeds a punch that has not happened yet).

## Permissions

`attendance.view` (the board & records), `attendance.manage` (manual entry, corrections,
re-applying rules, approvals), `attendance.clock` (record your own punches),
`attendance.roster.view` (the roster tab) and `attendance.roster.manage` (overrides and
assigning schedules — including naming an attendance policy on the assignment). Built-in
roles: **HR Manager** gets all five; **Staff** gets `attendance.clock` for self-service.
The policies themselves are `setup.attendance-policies.view` / `.manage`. Assigning a schedule from the **employee profile**
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
