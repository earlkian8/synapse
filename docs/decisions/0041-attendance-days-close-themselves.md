# 0041 — Attendance days close themselves

- **Status:** Accepted
- **Date:** 2026-09-19
- **Builds on:** [0036 — Attendance judged in local time on shift-anchored dates](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md),
  [0038 — Attendance policies: presets and typed options, snapshotted per day](./0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md),
  [0039 — Attendance requests and period lock](./0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md)
- **Related:** [Attendance module](../modules/attendance.md),
  [Notifications](../modules/notifications.md),
  [attendance tables](../database/attendance-tables.md),
  [0040 — Punch capture](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md)

## Context

Nothing about attendance ran on a schedule except period generation (ADR 0039):

- **A day nobody punched was never written.** The board, the report and the payroll
  summary *synthesised* `absent` at read time, from today's plan. So a past absence
  could quietly become a day off after somebody changed an assignment, and the period
  lock froze nothing for it, because there was no row.
- **A forgotten clock-out stayed `incomplete` forever.** The policy's
  `missing_clock_out` options (flag, or close automatically) were typed and shown as
  *pending* (ADR 0038).
- **Nobody was told.** A manager learnt of an absence by opening the board.
- **Nothing followed a change of plan.** Approving leave for a day already recorded
  absent left it absent. A holiday added afterwards, or a roster change, did not reach
  days already written. Leave has no reference to attendance.

## Decision

### `attendance:close-day`, hourly

- **Hourly, for every organisation, on its own clock.** Midnight is a different moment
  in every zone, and a night shift is only over the morning after. `DayCloser` walks
  the dates from the day after `organizations.attendance_closed_through` up to
  yesterday. The first run starts at yesterday. The walk reaches at most **7 days
  back**, so a scheduler that stopped for a week catches up without rewriting a month.
- **A date is closed in three steps, each only once nobody can still change it by
  punching:**
  1. **Materialise.** Everybody due at work that date (employment status `active` or
     `on_leave`, hired by then) whose shift made it a working day and who has no record
     gets one: `absent`, `on_leave`, `holiday`, or present on official business. The
     record carries its frozen rules like any other (`AttendanceClock::materialise()`).
     This happens only once *their* shift has ended, because after that no clock-in
     can open the day. Rest days are not written: a day off is the default and needs no
     row.
  2. **Handle forgotten clock-outs** as each day's policy says
     (`AttendanceClock::closeForgottenDay()`), once the shift can no longer claim a
     punch, which is `max_shift_span_minutes` after the clock-in:
     - `flag` leaves the day for HR (`missing_clock_out`).
     - `auto_close_at_shift_end` and `auto_close_after_minutes` write a `clock_out`
       with **`source = system`**. It is placed at the shift's end (plus the minutes),
       never after the maximum span, never in the future, and never before the day's
       last punch. The day is flagged `auto_closed`.

     Either way the record gets `closed_at` and is not handled twice.
  3. **Tell people.** When nothing about the date is still waiting,
     `attendance_closed_through` moves to it and **one digest per recipient** goes out.
     Everybody holding `attendance.view` gets the whole company's. A manager without
     it gets their own reports'. The digest lists absent without leave, still missing a
     clock-out, closed automatically, punched away from the site, device punches out of
     order, and stamped by a clock that was off. It names three people per kind, then
     "and N more". A date with nothing to report sends nothing.
- **Dates close in order.** A later date's digest waits for an earlier one still open
  (a night shift still running). `attendance_closed_through` only ever advances over
  contiguous closed dates. `attendance_closed_from` remembers the first date the job
  ever closed.
- **Sign-off needs no step of its own.** `auto_closed` is a review flag (ADR 0040), so
  an auto-closed day is `pending` by derivation (ADR 0039).
- **Idempotent.** A closed date is not walked again, a record is never written twice,
  and a day already closed is not closed again. A date inside a locked period counts as
  closed already.

### Recompute when inputs change

- **`AttendanceInputs::watch()`** registers model events on what a day depends on but
  does not own:
  - `LeaveRequest`: approved, un-approved, or moved while approved; the old and new
    dates.
  - `Holiday`: created, moved, deleted or restored. A recurring holiday covers every
    year's occurrence back to the first recorded day.
  - `EmployeeScheduleAssignment` and `ShiftRosterEntry`: written or removed; the dates
    they covered before and after.

  Watching the models rather than the controllers catches every path at once: the web,
  the mobile API and the assistant.
- **`RecomputeAttendanceRange(organization, employees?, from, to)`** does what it may
  and nothing more, because a day keeps the rules it was judged by (ADR 0036, 0038):
  - **Every recorded day in range is re-evaluated**, so leave and approved requests,
    which are read live, reach it. An approval turns `absent` into `on_leave`.
  - **The holiday is re-read from the calendar** for every day. A holiday is a fact
    about the date, not a rule.
  - **A placeholder day** (no punches, not entered by hand, no remarks;
    `AttendanceRecord::isPlaceholder()`), such as the job's absence or a day opened
    ahead for official business, is **re-judged by the plan as it now stands**. A new
    assignment that makes the date a rest day turns that absence into a day off.
  - **A day somebody punched keeps its schedule and policy.** Re-applying current rules
    stays HR's explicit action.
  - **Working days the job already closed that now have no record get one**, as the
    job would have written, but never before `attendance_closed_from`, so the first
    change after deploy does not back-fill a year of absences.
  - **Nothing inside a locked period moves.**
  - It writes one activity entry when anything changed.
- **It runs after the response, in the same process (`dispatchAfterResponse`).** The
  app is often served without `queue:work`, the same reason `SystemNotification` pins
  itself to `sync`, and a recompute that silently never runs is worse than one that runs
  a moment late. It is idempotent, so a deployment that does queue it loses nothing.
- **A location or fence change queues nothing.** A punch was judged where it was made
  (ADR 0040).

### `attendance:remind`, every fifteen minutes

- Somebody whose working shift started at least the policy's
  `reminders.clock_in_after_minutes` ago (5–240), is still under way, and has no
  clock-in is told **once per shift** (`Cache::add` on the employee and the work
  date).
- Nobody is reminded on a rest day, on approved leave, on a holiday nobody works, or on
  approved official business or remote work.
- A policy with no minutes sends nothing, **which is the built-in default**. A company
  turns reminders on.
- The reminder is an ordinary notification (in the app, by email and by web push, as
  each person chose). The mobile app has no push channel of its own, so a phone hears
  about it only through those.

### The board and the assistant

- **"Not clocked in yet"** is a live filter for today on the board. It shows people due
  at work whose shift has started and who have not clocked in. People on leave, on a
  holiday or on official business are not listed; remote workers are, because they
  still punch (`AttendanceRecordsIndexQuery::notClockedInYet()`).
- The assistant's **`find_attendance_exceptions`** (gated on `attendance.view`) answers
  "who hasn't clocked in?", "who is missing a clock-out?", and "who punched outside the
  office this week?". It covers not clocked in, missing clock-out, outside the site,
  auto-closed, absent, device anomalies, clock skew, or all of them. The 30-day brief
  counts the new flags.

## Consequences

- **Past working days are rows.** The board, report and payroll summary read them. The
  read-time synthesis still fills in whatever has no row: today, the future, rest days,
  and dates the job has not reached.
- **The job must be scheduled.** Without `schedule:run` in cron nothing closes. After
  deploying, run `php artisan attendance:close-day` once. It closes yesterday, which
  sets `attendance_closed_from`.
- **A digest arrives once the whole date is over**, which for a company with a night
  shift is the morning after, not midnight.
- **Recompute can re-judge a placeholder day by today's plan.** That is deliberate. It
  recorded nothing anybody did, so it has no history to protect.
- **Reminders need somebody to opt in** per policy, and on a phone they arrive only as
  email or web push until the mobile app has push.
- **Only the last week is caught up.** A scheduler that was down longer leaves older
  dates unmaterialised. They still synthesise at read time, and HR can record them.
