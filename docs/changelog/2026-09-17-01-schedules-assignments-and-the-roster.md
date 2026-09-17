# A schedule is a pattern of days, and the roster says who works it

A work schedule could say exactly one thing: these hours, on these days. "Mon–Fri 8–5,
Saturday 8–12" could not be written down. Neither could a split shift, flexible hours, an
hours-only contract, or a four-on, four-off rotation.

Worse, a schedule change was retroactive. `employees.work_schedule_id` had no dates, so
moving somebody to nights this month changed what the board said about last month — and
a Saturday call-in meant editing the shift everybody shares and remembering to change it
back.

This is Phase 1 of making attendance fit any company (ADR 0037). One resolver now answers
*which shift applies to this person on this day*, and says **why**.

## Highlights

- **A schedule is a pattern of days.** Each one now has a type — **fixed**, **flexible**
  or **hours only** — a cycle, and a row per day of that cycle: worked or a rest day, one
  or more stretches of hours, what the day asks for, and (for flexible) the core hours
  everyone must be present for. A second stretch of hours makes it a **split shift**. A
  cycle of 8 instead of 7 makes it a **rotation**.
- **The editor shows you the next fortnight before you save it.** Change the pattern and
  the preview redraws, so a rotation's shape is checked rather than discovered on the
  roster a week later.
- **A schedule change has a date.** Put someone on nights from the 1st and everything
  before it stays judged by the shift they were actually on. "Only for a while" puts them
  back on their old shift afterwards, in one action. Their profile lists the history.
- **A new Roster tab** — the plan rather than the record. Employees down, the week
  across, the shift each person is due to work in every cell, with the schedule's name
  under it. It is the one attendance view that reads **forward**: stepping into next week
  is the point of it.
- **Any one day can be changed.** Click a cell: another shift's hours for that date,
  hours of its own, or a day off, with a reason. The cell is pinned so everyone can see it
  is a one-off, and clearing it hands the day straight back.
- **Every cell says why.** A tooltip names the reason the shift applies — a one-off
  override, an assigned shift, a department default, the company default, or the default
  hours. "Why is Ben on nights?" is answerable without leaving the screen.
- **Defaults, so nobody falls through.** A department can set the hours its people work,
  and a company can set its own — the star on a schedule's row.
- **The assistant can read and change the roster.** "Who works Saturday?", "what is Ana's
  shift next week?", "put Ben on the night shift on the 20th."

## How a day is judged now

| Type | Late | Short |
| --- | --- | --- |
| **Fixed hours** | After the shift starts, past the grace period | Left before the shift ends |
| **Flexible hours** | Only after the core window opens | Left before it closes, or below the day's hours — whichever is worse |
| **Hours only** | Never | Below the day's hours |

A split shift needs no special case: clocking out ends the stretch, so the gap between the
halves is neither worked nor break. Lateness is against the first half's start, shortfall
against the last half's end.

## Backend

- **New tables** (`…_create_schedule_patterns_assignments_and_roster`):
  `work_schedule_days` (one row per day of a cycle), `employee_schedule_assignments`
  (dated, with a `cycle_offset` so two crews share one rotation four days apart) and
  `shift_roster_entries` (one per person per date). `work_schedules` gains `type`,
  `cycle_length_days`, `cycle_anchor_date` and `weekly_required_minutes`; `departments`
  and `organizations` each gain a `default_work_schedule_id`.
- **`ShiftResolver`** is the one answer: **roster → assignment →
  `employees.work_schedule_id` → department → organisation → fallback** (Mon–Fri
  08:00–17:00, eight hours — the historical behaviour). It returns a readonly
  `ResolvedShift` carrying the segments as clock-face pairs *and* as ordered UTC instants,
  the required and grace minutes, the core and accept windows, and the **source**.
  `forMany()` answers for a whole roster over a whole range in **five queries whatever
  the range**.
- **`DayRules` is `version: 2`** in the same column — type, segments, the core window as
  instants, the unpaid break and the source. A version 1 snapshot still reads as a fixed
  shift, so nothing already recorded moves.
- **Three canonical writers**, each the only one of its kind:
  `SchedulePatternWriter` (a template's cycle, and the legacy summary columns),
  `ScheduleAssigner` (never overlapping — a new range closes, trims or **splits** what it
  lands on, and keeps `employees.work_schedule_id` pointing at today's assignment) and
  `RosterWriter` (one override per person per date).
- **Nothing in the attendance path reads `$employee->workSchedule` any more** — the punch
  engine, both roster queries, self-service, the mobile session and the assistant all go
  through the resolver. The column survives as a denormalised "current" pointer for the
  employee screens, and sits one step below an assignment in the chain so a row created
  without one still resolves.
- **The bulk re-apply resolves a chunk at a time** rather than per record, so a month of a
  200-person company is five queries, not six thousand.
- New permissions **`attendance.roster.view`** and **`attendance.roster.manage`**;
  assigning on the employee profile reuses `employees.update`. Every write is
  activity-logged.

## Frontend

- **Company Setup → Work Schedule:** the editor is now a centred modal — a type switch, a
  cycle with an anchor date for rotations, a per-day grid (rest-day toggle, one or more
  stretches of hours, what the day asks for, flexible core hours) and the 14-day preview.
  Rows show the pattern, a rotating marker and the company-default star.
- **Attendance → Roster:** the week grid, the override modal and bulk assign. Date
  stepping is uncapped here; export and manual entry, which are about records, are not
  offered on it.
- **Employees:** a schedule history on the profile, with assign and withdraw.
  **Departments:** a default schedule.
- **My Attendance** shows the shift the day is actually judged against, and marks it when
  it is just for today.

## Notes

- **Existing tenants see no change in their numbers.** The migration turns each schedule's
  flat working-day list into seven day rows that say exactly what it said, and gives each
  employee one open-ended assignment dated from their hire date. No recompute is run, and
  none is needed.
- **`start_time`, `end_time` and `work_days` stay, read-only** — a summary of the pattern,
  kept true by the writer, for the employee screens and the assistant that still read
  them. A rotation has no weekday names to summarise, so only the roster tells the truth
  about one.
- **The schedule form's payload changed shape.** It posts `type`, the cycle and a `days`
  array instead of `start_time` / `end_time` / `work_days`.
- A day already recorded still keeps the rules it opened with. Assigning a schedule from a
  past date does not re-judge it — **Re-apply schedules** remains the deliberate way.
- The editor's preview is computed in the browser, mirroring the resolver's day-index
  rule, so an unsaved pattern can be checked without a round-trip per keystroke. The two
  have to be kept in step.
