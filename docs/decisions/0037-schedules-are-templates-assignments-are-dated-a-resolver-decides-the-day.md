# 0037 — Schedules are templates, assignments are dated, and a resolver decides the day's shift

- **Status:** Accepted
- **Date:** 2026-09-17
- **Builds on:** [0036 — Attendance judged in local time on shift-anchored dates](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
- **Related:** [Attendance module](../modules/attendance.md),
  [Work Schedule & Holidays](../modules/work-schedule-holidays.md),
  [attendance tables](../database/attendance-tables.md),
  [scheduling tables](../database/scheduling-tables.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0034 — A company writes its own setup](./0034-a-company-writes-its-own-setup.md)

## Context

ADR 0036 made a day's numbers correct for any timezone and any shift. It left
*which* shift untouched, and that was still one pair of times on one row:

```
work_schedules: name, start_time, end_time, work_days ["Mon","Tue",…], grace, required_hours
employees:      work_schedule_id
```

Five things a generic customer configures could not be said at all.

1. **A week is not uniform.** "Mon–Fri 8–5, Sat 8–12" needs per-day hours; one
   `start_time` gives every working day the same ones.
2. **A shift is not always one stretch.** A split shift (morning and evening, with
   the afternoon off) has two, and the gap is neither work nor break.
3. **Not every company judges arrival time.** Flexible hours care about a core
   window; an hours-only contract cares about the hours and nothing else. There was
   one rule — late against `start_time` — for everybody.
4. **Not every roster is weekly.** A four-on, four-off rotation repeats over eight
   days, and two crews run it four days apart.
5. **A schedule change was retroactive.** `employees.work_schedule_id` has no dates,
   so moving somebody to nights changed what the resolver said about last month too
   — and ADR 0036's snapshot only protects days that already have a record. A day
   with no record (an absence, a rest day) is synthesised on every read, so the board
   silently re-judged the past.

There was also no way to change one person's shift for one day. A swap, a Saturday
call-in or a day off meant editing the schedule everybody shares.

## Decision

- **A schedule is a template with a day pattern.** `work_schedules` gains `type`
  (`fixed` | `flexible` | `hours_only`), `cycle_length_days` (7 for a week, N for a
  rotation), `cycle_anchor_date` (which date is day 1 of a rotation) and
  `weekly_required_minutes`. A new **`work_schedule_days`** holds one row per day of
  the cycle: `is_rest_day`, `segments` (a JSON list of `{start, end}` clock-face
  pairs — one for a normal shift, two or more for a split one, an end at or before
  its start crossing midnight), `required_minutes`, the flexible `core_start` /
  `core_end` and `earliest_start` / `latest_end`, and `unpaid_break_minutes`.

- **Who works it is a dated assignment.** **`employee_schedule_assignments`** gives
  an employee a schedule over `[effective_from, effective_to]` (open-ended when
  `effective_to` is null) with a `cycle_offset` for rotations. Ranges never overlap:
  `ScheduleAssigner` is the only writer, and it closes, trims or **splits** whatever
  the new range lands on — so a fortnight on another shift returns the old one
  afterwards. It also keeps `employees.work_schedule_id` pointing at whichever
  assignment covers *today*, not simply the one just written.

- **One date can be overridden.** **`shift_roster_entries`** is one row per employee
  per date: another template's hours for that day, hours of its own, or a rest day,
  with a reason. Setting one twice corrects it; clearing it hands the day back to the
  chain.

- **Defaults cover anyone with no assignment.** `departments.default_work_schedule_id`
  and `organizations.default_work_schedule_id`.

- **One resolver answers the question.** `App\Support\Attendance\ShiftResolver` walks
  a single precedence chain, most specific first:

  **roster → assignment → employee (the denormalised pointer) → department →
  organisation → fallback** (Mon–Fri 08:00–17:00, eight hours — the historical
  behaviour, so an unconfigured tenant's numbers do not move).

  It returns a readonly `ResolvedShift`: the date, type, whether it is a working day,
  the segments both as clock-face pairs and as ordered UTC instants (each rolled
  forward past the one before it, so an overnight half of a split shift stays after
  the morning half), required and grace minutes, the core and accept windows, the
  schedule's id and name, and **`source`** — so every screen can say *why* a shift
  applies. `forMany()` answers for a whole roster over a whole range in **five
  queries whatever the range**, which is what the board, the weekly grid, the monthly
  report and the roster all use.

- **The calculator judges by type.** `DayRules` is bumped to `version: 2` in the same
  column, adding `type`, `segments`, the core window as instants, the unpaid break and
  the source. `AttendanceCalculator` then reads:
  - `fixed` — late against the first segment's start (after grace), short against the
    last segment's end. A split shift's gap needs no special case: clocking out ends
    the on-clock stretch, so the punch walk never counts it.
  - `flexible` — late only after `core_start`; short by whichever is worse, leaving
    before `core_end` or falling below the day's required minutes.
  - `hours_only` — never late; short by the hours alone.

- **Nothing in the attendance path reads `$employee->workSchedule` any more.** The
  punch engine, both roster queries, the daily board, the weekly and monthly views,
  self-service, the mobile session and the assistant all go through the resolver.
  `employees.work_schedule_id` survives as a denormalised "current" pointer for the
  employee screens — which is why it also sits in the precedence chain, one step
  below an assignment, so a row created without one (a factory, a seeder, an import)
  still resolves to the shift it names.

- **Permissions.** `attendance.roster.view` and `attendance.roster.manage` gate the
  roster tab and its writes. Assigning on the employee profile reuses
  `employees.update`, because that is what it is — an edit to that person's record.
  Template editing stays under `setup.schedule.manage`.

- **The assistant grows with it.** `find_shifts` reads the roster (capped at 31 days,
  200 people and 40 cards) and reports each shift's source; `set_roster_entry` writes
  an override through the same `RosterWriter` the board uses.

- **The migration keeps every existing tenant's numbers identical.** Each schedule's
  flat `work_days` + `start_time`/`end_time` becomes seven day rows; each employee
  with a `work_schedule_id` gets one open-ended assignment dated from their hire date
  (or their record's `created_at`). No recompute is needed and none is run.

## Consequences

- **`start_time`, `end_time` and `work_days` stay, read-only**, maintained by
  `SchedulePatternWriter` as a summary of the pattern: the first working day's first
  segment, its last segment's end, and the weekday names that are not rest days. The
  employee screens, the assistant's employee module and `EmployeeDisclosure` still read
  them. They go when those readers move to the resolver. A rotation has no weekday
  names to summarise, so its `work_days` is empty and only the roster tells the truth
  about it.
- **The schedule form no longer posts `start_time` / `end_time` / `work_days`.** It
  posts `type`, the cycle and a `days` array. Any other client of
  `POST /setup/schedule/work-schedules` must do the same.
- A day already recorded still keeps the rules it opened with (ADR 0036). Assigning a
  new schedule from a past date does **not** re-judge those days; "Re-apply schedules"
  remains the deliberate way, and it now resolves the whole chunk at once rather than
  per record.
- **The roster reads forward.** It is the one attendance view whose date stepper is not
  capped at today, because planning next week is the point of it.
- `AttendanceClock::snapshot()` takes a `ResolvedShift` instead of a `?WorkSchedule`,
  and `DayRules::fromSchedule()` is replaced by `DayRules::fromShift()`.
  `AttendanceCalculator::isWorkingDay()` is gone — the resolver decides.
- The editor's 14-day preview is computed **in the browser** from the form's state,
  mirroring the resolver's day-index rule, so an unsaved pattern can be checked. The
  two rules have to be kept in step; the alternative was a round-trip per keystroke.
- One override per person per date. A day split between two different borrowed shifts
  cannot be expressed, and does not need to be until Phase 4's work locations.
- Overlap is resolved by the writer rather than by a database constraint. Postgres
  exclusion constraints would enforce it in the engine, but the committed default
  connection is SQLite, which has none.
