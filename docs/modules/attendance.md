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
[Attendance Policies](./attendance-policies.md)) and
[ADR 0039](../decisions/0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md)
(requests, sign-off, periods and the lock) and
[ADR 0040](../decisions/0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md)
(geofences, capture rules, devices, offline punches — the sites and devices themselves are
in [Work Locations](./work-locations.md) and [Attendance Devices](./attendance-devices.md))
and [ADR 0041](../decisions/0041-attendance-days-close-themselves.md) (the end-of-day
job, recompute when inputs change, reminders); this is the *how*. Everything is
tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/attendance` · API prefix: `/api` (Sanctum)
> Sidebar: Workforce → Attendance (HR) and Workforce → My Attendance (self-service)

## Surfaces

- **`/attendance`** — the **HR attendance workspace**: stat cards (present / late /
  absent / on-leave / avg hours), a **period-aware stepper** (prev / today / next +
  picker, stepping by day / week / month — "today" is the organisation's), search +
  department filters, and tabs over the same roster (which is built from *every*
  employee, so people with no punches still appear as **Absent** / **Holiday** /
  **Day off** / **On leave**):
  - **Today's Log** — a sortable table: avatar + name, time in / out, computed hours, a
    **status pill** and an **anomaly flag** (late by N, missing time-out, left early,
    unscheduled absence, a half day, a break that ran over), beside an **exceptions
    panel** (the day's problems grouped by kind — including **half days** — each
    actionable — also **punched away from the site**, **closed automatically**, **device
    punches out of order** and **clock was off**). A **status filter** narrows it
    (present / late / half day / … / holiday), and on today's date offers **Not clocked
    in yet**: people due at work whose shift has started and who have not clocked in,
    leaving out leave, holidays and official business.
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
  - **Requests** (`attendance.requests.review`, with a count of those waiting) — the
    inbox: status tabs (pending first, oldest waiting at the top), a kind filter, search
    and department. Each row says who, which day and what they asked for in words ("Time
    out 5:30 PM", "1h 18m overtime"), with approve / reject beside it; several can be
    decided at once with one note. The reviewer's own request, a decided one or one in a
    locked period cannot be selected. **File for someone** (`attendance.manage`) files on
    an employee's behalf. See *Requests* below.
  - **Periods** (`attendance.period.manage`) — the calendar attendance closes on and the
    reminder lead time, then the periods newest first on a rail: an open one lists what
    stands between it and a lock (requests waiting, days missing a clock-out, days
    awaiting sign-off) or says it is ready; a locked one says who locked it and why, and
    offers the **payroll file** it kept and **Unlock** (`attendance.period.unlock`). See
    *Periods and the lock* below.

  Opening any record reveals the **day-detail modal** — centred, like every other detail
  surface in the app, and read top to bottom in the order somebody checks a day: the
  header states who and when (person, status, date, **the schedule, holiday and
  attendance policy the day was judged by**, and whether it was entered by hand), a
  **totals band** under it (worked / regular / break / late / overtime — noting overtime
  that awaits approval — plus night, rest-day and holiday minutes when the day has any)
  stays put while the body scrolls, and the body opens with the day's **flags** as chips
  ("Half day", "Unpunched break deducted", "Overtime awaiting approval", …) before the
  **punch trail**, the remarks and the sign-off. Under the trail sit the punches an edit
  or a correction **replaced** (struck through, saying which), and the **requests that
  concern the day** — each opening the review modal. A day in a **locked period** says so
  in its header and offers no action but the reminder to unlock the period.

  Each punch is one row — time, source, GPS pin, note — with a **capture line** under it
  (on site or off, with the nearest site and the distance — "Off site: 1.2 km from Main
  Office"; the device that sent it; **sent offline** with when it arrived; the clock's
  skew when it was off) and **the photo taken at it on that row**, large enough to recognise a face and opening full-size in a new tab. A
  punch with no photo still gets a tile, saying which of three things happened: the
  source never takes one (a web, kiosk or biometric punch), the mobile app was expected
  to and did not, or the file has since gone. "No evidence" and "evidence missing" are
  different findings, and an empty space states neither. The section header counts them
  ("4 punches · 2 with a photo"), and the remarks and approval blocks are likewise always
  drawn — an unwritten remark says so rather than leaving a gap. HR actions (correct,
  **re-apply rules**, **sign off**, delete) sit in a pinned footer.

  **Reviewing a request** opens its own centred modal: the ask in a strip under the
  header, then the record it would change — a correction as the day's punches beside the
  ones asked for, each changed row reading "was → becomes"; overtime as a bar of what was
  worked past the shift against what is asked, saying exactly what an approval would
  grant — then the reason, the note and the decision.

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
  history. **Request** (`attendance.request`) asks for a correction, overtime, official
  business or remote work; each history day has **Fix** (a correction for that day,
  showing what it recorded beside each time) unless its period is locked; **My requests**
  lists what was asked and decided, each opening the same modal a reviewer sees, with
  **Cancel request** while it is pending. The card shows the **current shift**: a night-shift worker at 02:00 sees the
  shift they started last night, not an empty new date.
- **The assistant** reads attendance two ways — and files and decides requests (see
  *Assistant*): `find_attendance` lists an employee's records on request, and — when a chat turn is *about* somebody — the module contributes
  a 30-day read-out (days worked against days scheduled, punctuality, absences, half days,
  holidays, average hours, overtime and how much of it awaits approval, night, rest-day and
  holiday minutes, over-long breaks, the last five days, days on official business and
  requests awaiting a decision) to the retrieved brief the answer
  is composed from. Anybody's needs `attendance.view`; your own needs nothing, because
  `/attendance/me` needs nothing. See
  [ADR 0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md).
- **Mobile API** (`/api`, token-authenticated) — the same clock engine for the DTR app:
  `POST /api/auth/login`, `GET /api/attendance/today` (the current shift, as on the web
  card), `POST /api/attendance/punch` (with GPS + selfie), `GET /api/attendance/records`,
  `GET /api/attendance/summary`, and the employee's own requests:
  `GET|POST /api/attendance/requests`, `GET /api/attendance/requests/{id}`,
  `PATCH /api/attendance/requests/{id}/cancel`. The session payload's `organization.timezone` is the
  clock the app shows every time on. A punch may carry `client_id` (a resend returns
  `duplicate`), `punched_at` (the phone's time, for a punch queued offline) and
  `sent_at` (the phone's clock when it sent, to measure skew) — see
  [Mobile App](./mobile-app.md).
- **Kiosks and scanners** (`/kiosk` and `/api/devices`, device-key authenticated) —
  see [Attendance Devices](./attendance-devices.md).

The daily log stays a per-person table — the right tool for "what happened today" — while
the weekly/monthly tabs give the depth and the exceptions panel gives the utility (so it
reads as an ERP module, not a spreadsheet with a stylesheet). Self-service is a **single
big clock**, the way a punch clock should feel.

## Data model

Two tables hold the record, two more what was asked and what is closed, and three more
where punches come from (see [attendance tables](../database/attendance-tables.md));
three more hold the plan (see [scheduling tables](../database/scheduling-tables.md)):

- **`attendance_records`** — one row per employee per day: what the day is judged
  against (`work_schedule_id`, the schedule's clock-face `scheduled_start/end`, the shift
  as UTC instants `scheduled_start_at/end_at`, and the frozen `rules`), the derived
  `status` and `flags`, the minute totals (`worked / break / late / excused late /
  undertime`) and the **buckets** (`regular / overtime / approved overtime / night /
  rest day / holiday`, ADR 0038),
  `first_in_at` / `last_out_at`, an `is_manual` flag, `remarks`, and the sign-off
  (`approval_status` — derived, ADR 0039 — `approved_by/at` and
  `signed_off_overtime_minutes`), and `closed_at` — when the end-of-day job handled a
  forgotten clock-out. Unique on `(employee_id, work_date)`.
- **`attendance_punches`** — the raw punch events the summary is built from: `type`
  (`clock_in | clock_out | break_start | break_end`), `punched_at`, `source`
  (`web | mobile | kiosk | biometric | manual | correction | system`), GPS
  (`latitude / longitude / accuracy`), an optional `photo` selfie, a `note`, and
  `recorded_by` (null when self-punched). What capture established (ADR 0040): the
  nearest `work_location_id`, `distance_meters`, `within_geofence`; the
  `attendance_device_id` and the device's own `external_id` (unique together);
  `device_punched_at` (the phone's time for an offline punch), `received_at` and
  `clock_skew_seconds`. Soft-deleted when an edit or a correction
  replaces it; `attendance_request_id` / `replaced_by_request_id` name the correction
  that wrote or replaced it.
- **`attendance_requests`** — corrections, overtime, official business and remote work,
  with their payload, reason, status and decision.
- **`attendance_periods`** — the periods attendance closes on, their lock and unlock
  audit, and the path of the file written when each locked.
- **`work_locations`** / **`employee_work_locations`** — sites and their fences, and who
  is based where ([Work Locations](./work-locations.md)).
- **`attendance_devices`** — kiosks and scanners, each with a hashed key
  ([Attendance Devices](./attendance-devices.md)).
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
→ the primary work location's default → organisation default → fallback** (Mon–Fri
08:00–17:00, eight hours).

It returns a `ResolvedShift`: the day's `type`, whether it is a working day, its
`segments` as clock-face pairs *and* as ordered UTC instants (each rolled past the one
before it, so a split shift's evening half stays after its morning half), required and
grace minutes, the core and accept windows, the schedule's name, and **`source`** — which
link in the chain won, so the roster can say *why*. `forMany()` answers for a whole roster
over a whole range in **six queries whatever the range**.

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

**`AttendanceClock::capture(employee, ?type, context)`** — which `punch()` wraps — runs
in a transaction with a row lock on the employee: return the punch already received
under the same device or client id, infer the type when none is given, resolve the work
date, check the period lock, find the day (or build it **unsaved**, with its rules
frozen), and then, **for a person** (web, mobile, kiosk, manual): the policy allows the
source, an offline punch is inside its window, the transition is valid **at the instant
given** (no double clock-in, no clock-out before clock-in, breaks only while clocked
in — counting punches recorded after it), and the capture rules hold (the web IP
allowlist, the mobile selfie). Then it **places** the punch — the nearest site, the
distance and the fence verdict, refusing a person under a `block` geofence unless the
day is approved remote work or official business — and only then saves the day, writes
the punch and recomputes. **A refused punch writes nothing.** A **scanner's** punch is
`record_only`: it skips the person checks and is never blocked, and the evaluator flags
the day instead (ADR 0040). `nextExpected()` / `allowed()` drive the
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

## Requests

Filed through **one validation** (`StoreAttendanceRequestRequest`), **one filer**
(`AttendanceRequestFiler`) and decided through **one approver**
(`AttendanceRequestApprover`), whichever of the web, the mobile API and the assistant
asked. Every request needs a reason; nobody decides their own; a range touching a
locked period is refused at filing and at decision; a second pending request of the
same type for the same day is refused.

| Type | Asks for | Approval does |
| --- | --- | --- |
| `correction` | Any of time in, break start, break end, time out, for a day that has begun | Opens the day if needed and replaces only the punches it names (the first clock-in, first break, last clock-out). The replaced ones are soft-deleted and name the request; the new ones are `source = correction`. The day is marked manual and recomputed. |
| `overtime` | Minutes; a future day is a pre-approval | The day's approved overtime becomes `min(asked, worked past the shift)` and the day stops awaiting a decision. A pre-approval is matched when the day is evaluated. A rejection settles the day too. |
| `official_business` | Up to 31 days, optional hours and place | Every working day in range is present, neither late nor short, and worth at least the shift's hours — even with no punches. Flag `official_business`. Leave and holidays still win. |
| `remote_work` | Up to 31 days, optional hours and place | The days carry the `remote_work` flag; punches are still required, but the geofence neither blocks nor flags them. |

The grant is read by the evaluator (`DayContext::grantedOvertimeMinutes`) on every
evaluation, so it survives a recompute and a re-apply.

**Sign-off.** `approval_status` means *needs sign-off* and is derived on every
evaluation: `pending` while the day carries a review flag (`unapproved_overtime`,
`outside_geofence`, `source_not_allowed`, `device_sequence_anomaly`, `clock_skew`,
`auto_closed`),
`approved` once signed off, null otherwise. **Sign off** grants the day's overtime as it
stands (`signed_off_overtime_minutes`); overtime a later correction adds puts the day
back to pending. **Sign off all** walks only the pending days, leaving the signer's own
and any in a locked period.

## Periods and the lock

Periods follow `organizations.attendance_period_frequency` — weekly (Mon–Sun), every two
weeks, twice a month (1–15, 16–end; the default) or monthly — and are **contiguous**:
each starts the day after the last ends, so a change of calendar makes one short period
rather than a gap or an overlap. A company's first periods start one back, so the period
that just ended can be locked. `attendance:periods` keeps the current and next one
generated; **Add periods** does it on demand.

**Locking** shows a checklist — requests pending in range, days still incomplete, days
awaiting sign-off — and asks for a reason when any is open. It writes the
[period summary](./attendance-policies.md#payroll-period-summary) to the private disk and
keeps it as the period's **payroll file**. **Unlocking** needs `attendance.period.unlock`
and a reason, and is logged; the file from the lock is kept.

**One guard, every path.** `PeriodLock` refuses a punch, HR's entry or edit, a
correction, a sign-off, a re-apply, a delete, a request filed or decided, and the
recompute of any day inside a locked period — from the engine, so the web, the mobile
API and the assistant all hit it. Bulk re-apply, sign off all and recompute leave locked
days as they are and say how many they left.

## Closing days

**`attendance:close-day`** (hourly, ADR 0041) closes each date on the organisation's own
clock, from the day after `organizations.attendance_closed_through` (at most seven days
back) up to yesterday:

1. **Materialise** — everybody due at work that date (active or on leave, hired by then)
   with no record gets one once their shift has ended: `absent`, `on_leave`, `holiday`,
   or present on official business, snapshot included. Rest days are not written.
2. **Forgotten clock-outs** — once `max_shift_span_minutes` has passed since the
   clock-in, the day's policy decides: `flag` leaves it for HR; the two auto-close
   actions write a `clock_out` with `source = system` at the shift's end (plus the
   policy's minutes), flagged `auto_closed` and so pending sign-off. The record gets
   `closed_at` either way.
3. **Digest** — once nothing about the date is still waiting, `attendance_closed_through`
   advances and each holder of `attendance.view` gets one notification of the date's
   exceptions (a manager without it gets their own reports').

**Recompute when inputs change.** `AttendanceInputs` watches leave, holidays, schedule
assignments and roster entries, and dispatches `RecomputeAttendanceRange` after the
response over the dates a change touches: every recorded day is re-evaluated and its
holiday re-read, a day that records nothing anybody did is re-judged by the current
plan, closed working days with no record get one (never before
`attendance_closed_from`), and a locked period is left alone. A day somebody punched
keeps its schedule and policy. A location change queues nothing.

**Reminders.** `attendance:remind` (every 15 minutes) tells anybody whose working shift
started at least the policy's `reminders.clock_in_after_minutes` ago and who has not
clocked in — once per shift, never on leave, a non-working holiday, a rest day, or
approved official business or remote work. Off unless the policy sets the minutes.

## Maintenance commands

- **`php artisan attendance:recompute {--organization=} {--from=} {--to=} {--dry-run}`** —
  gives every day without a snapshot the snapshot it should have had, recomputes each day
  in range from its punches, and prints a table of the days whose status or totals moved
  ("late: 0 → 30"). `--dry-run` prints the same and writes nothing. Organisations are
  walked one at a time on their own clocks; `--organization` takes an id or a slug. It
  never re-applies a *changed* schedule or policy to a day that has a snapshot — that
  stays HR's explicit action. It walks days in date order, and it is how the minute
  buckets ADR 0038 added are filled for days recorded before them (a pre-policy snapshot
  is judged by the built-in fallback, so no status or total moves). A day in a locked
  period is skipped and counted.
- **`php artisan attendance:periods {--organization=}`** — scheduled daily at 00:15:
  generates the current and next period on each company's calendar, and reminds holders
  of `attendance.period.manage` once when an open period's end is within
  `attendance_lock_reminder_days`.
- **`php artisan attendance:close-day {--organization=}`** — scheduled hourly; see
  *Closing days*. Run it once after deploying.
- **`php artisan attendance:remind {--organization=}`** — scheduled every 15 minutes.
- **`php artisan attendance:prune-orphan-days {--organization=} {--dry-run}`** — deletes the
  empty days refused overnight clock-outs used to leave: no punches, not entered by hand,
  no remarks, not a day of official business, and the same employee's previous day left
  open. Run with `--dry-run` first.

Demo data written before ADR 0036 stored clock-face times as UTC, so it is re-seeded
rather than recomputed (`AttendanceSeeder` now writes real instants, skips rest days and
non-working holidays, and never seeds a punch that has not happened yet).

## Permissions

`attendance.view` (the board & records), `attendance.manage` (manual entry, corrections,
re-applying rules, sign-off, filing a request for somebody else), `attendance.clock`
(record your own punches), `attendance.roster.view` (the roster tab),
`attendance.roster.manage` (overrides and assigning schedules — including naming an
attendance policy on the assignment), `attendance.request` (file and cancel your own
requests), `attendance.requests.review` (decide other people's), `attendance.period.manage`
(the periods tab, generating and locking) and `attendance.period.unlock`. Built-in
roles: **HR Manager** gets all of them; **Department Head** gets `attendance.request` and
`attendance.requests.review`; **Staff** gets `attendance.clock` and `attendance.request`.
The policies themselves are `setup.attendance-policies.view` / `.manage`; sites are
`setup.locations.view` / `.manage`, and devices `setup.devices.manage`. The device API
takes a device key, not a user. Assigning a schedule from the **employee profile**
reuses `employees.update` instead — it is an edit to that person's record.

## Assistant

The agent's **Attendance** capability (available with `attendance.view`,
`attendance.request` or `attendance.requests.review`) exposes:

- **`find_attendance`** — an employee's recent DTRs (your own need no permission).
- **`record_punch`** — clock an employee in/out (gated by `attendance.manage`).
- **`find_shifts`** — "who works Saturday?", "what is Ana's shift next week?" — the
  roster, not the records, with each shift's source spelled out. Gated by
  `attendance.roster.view`; your own shifts need nothing. Capped at 31 days, 200 people
  and 40 cards.
- **`set_roster_entry`** — "put Ben on the night shift on the 20th" (gated by
  `attendance.roster.manage`).
- **`file_attendance_request`** — "I forgot to clock out yesterday, I left at 6", "log 2
  hours overtime for Friday" (gated by `attendance.request`; somebody else's needs
  `attendance.manage`). Validated exactly as the web validates it.
- **`find_attendance_requests`** — pending by default; without review rights, only your
  own.
- **`review_attendance_request`** — approve or reject (gated by
  `attendance.requests.review`); refuses your own and anything in a locked period.
- **`find_attendance_exceptions`** — "who hasn't clocked in?", "who is missing a
  clock-out?", "who punched outside the office this week?": not clocked in, missing
  clock-out, outside the site, auto-closed, absent, device anomalies, clock skew, or all
  of them (gated by `attendance.view`).

The 30-day brief also counts days punched away from the site, closed automatically,
still missing a clock-out after closing, and with scanner punches out of order.

Punches route through `AttendanceClock`, overrides through `RosterWriter`, and requests
through `AttendanceRequestFiler` / `AttendanceRequestApprover`, so totals,
status, the shift a night punch belongs to, and the history left behind are the same
whoever asked. Card times are shown on the organisation's clock.
