# 0036 — Attendance is judged in the organisation's local time, on shift-anchored work dates

- **Status:** Accepted
- **Date:** 2026-09-13
- **Supersedes:** the "Computation against the schedule" paragraph of
  [0010 — Attendance](./0010-attendance-and-mobile-api.md)
- **Related:** [Attendance module](../modules/attendance.md),
  [attendance tables](../database/attendance-tables.md),
  [Work Schedule & Holidays](../modules/work-schedule-holidays.md),
  [Company Profile](../modules/company-profile.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md)

## Context

ADR 0010 computed a day as `first_in − (scheduled_start + grace)` against the
employee's schedule. Four things were wrong with how that ran.

1. **Everything was judged in UTC.** `config('app.timezone')` is UTC and no
   organisation stored a zone, so "today" was UTC's today and a stored `"08:00"` was
   read as 08:00Z. In Manila (UTC+8) an 08:30 clock-in (00:30Z) on an 08:00–17:00 shift
   came out `late 0, undertime 480, status undertime`, and a 07:45 clock-in was filed
   under the previous date. The API sent correct ISO instants, so browsers *displayed*
   the right times, which is why it hid. Manual entries and the seeder parsed clock-face
   times as UTC — consistent with the broken judgement, wrong as instants.
2. **A night shift could not clock out.** A 06:00 clock-out after a 22:00 clock-in
   keyed on the new calendar date, `firstOrCreate`d an empty record for it, and only
   then was refused with "You need to clock in first" — leaving the empty row on the
   board as an absence. Even on the right date, an `"06:00"` end combined with the work
   date fell before the shift began.
3. **Past days were re-judged by today's schedule.** A recompute (any HR correction)
   read the employee's *current* schedule, so editing a grace period or moving somebody
   to another shift quietly changed old days.
4. **Holidays were ignored.** `HolidayCalendar` existed and Leave used it; attendance
   never asked, so a regular holiday nobody worked showed as `absent`.

## Decision

- **An organisation keeps a clock.** `organizations.timezone` is an IANA identifier,
  required on the Company Profile and on step one of the setup wizard (prefilled from the
  browser there), validated against PHP's canonical identifiers. Existing organisations
  were back-filled with `Asia/Manila`, the zone every tenant so far actually ran in.
  **Storage stays UTC**; only judgement moves. `App\Support\OrganizationClock` is the only
  code that knows the zone: `now()`, `today()`, `at($date, $time)` (a wall-clock reading
  as the UTC instant it names), `localDate($instant)` and `local($instant)`. It reads the
  bound tenant, so console work walks organisations with `Tenancy::runFor()`.

- **A day's shift is stored as instants.** `attendance_records.scheduled_start_at` and
  `scheduled_end_at` are computed with `OrganizationClock::at()` when the day opens, the
  end rolling to the next date when it is at or before the start. The calculator reads
  them; the `time` columns stay for display.

- **A punch is filed under the shift it belongs to.** `AttendanceClock::workDateFor()`:
  1. an **open shift** — clocked in within the last 16 hours and not out — claims the
     punch, whatever the calendar says;
  2. otherwise a **clock-in** belongs to today's or yesterday's (local) *working* day
     whose window, from 4 hours before its start to its end, contains the instant;
  3. otherwise the organisation's calendar date.

  16 hours (`MAX_SHIFT_SPAN_HOURS`) and 4 hours (`EARLY_CLOCK_IN_HOURS`) are constants
  until attendance policies make them configurable. A rest day has no shift window, so it
  never claims a clock-in from another date.

- **A refused punch writes nothing.** The punch runs in a transaction holding a row lock
  on the employee; the day is built unsaved (as `displayRecord()` already did), the
  transition is checked, and only an accepted punch saves the record and the punch.

- **A day keeps the rules it opened with.** `attendance_records.rules` holds a versioned
  `DayRules` snapshot (`version: 1` — grace, required minutes, working-day verdict,
  schedule id and name, holiday type and name), written when the day opens and read by
  every recompute. **Re-applying the current schedule** — per day from the day modal, or
  over the period on screen — is the one deliberate way it changes, gated on
  `attendance.manage` and always activity-logged. A record from before snapshots is given
  one from what it recorded (the schedule it names, archived or not, and the times it
  copied) — never from the employee's schedule today.

- **Holidays are part of the day.** The holiday is resolved outside the calculator
  (`HolidayCalendar::on()` / `inRange()`, the latter once per roster range) and frozen
  into the snapshot, so the calculator stays pure. A day with no punches resolves
  `on_leave` → `holiday` (`regular`, `special_non_working`) → `day_off` → `absent`; a
  `special_working` holiday is an ordinary working day, as in Leave. A holiday somebody
  worked is judged like any day and keeps its holiday type for later bucketing.

- **Times are shown on the organisation's clock** on web and mobile, not the device's:
  the zone rides on Inertia's shared `auth.organization` and on the mobile session's
  `organization`, and every attendance time is formatted with it.

- **Existing data is repaired by commands, not by the migration.**
  `attendance:recompute {--organization=} {--from=} {--to=} {--dry-run}` fills missing
  snapshots and recomputes, reporting each day that moved. `attendance:prune-orphan-days`
  deletes the empty days refused overnight clock-outs left (no punches, not manual, no
  remarks, and an open day on the previous date). Seeded data is re-seeded — the seeder now
  writes real instants.

## Consequences

- An 08:30 clock-in is 30 minutes late in Manila and in New York alike, and the seeded
  Night Shift clocks in and out across midnight on one record, through the web clock, the
  mobile API and the assistant's `record_punch` — all three call the same engine.
- **A late fix to a schedule or the holiday calendar does not reach days already
  recorded** until HR re-applies it. That is the point of the snapshot, and a cost: a
  holiday added the week after is not reflected by itself. Recomputing on input changes
  (without overriding the snapshot's schedule) is a later, automated step.
- One zone per organisation. A company with sites in several zones judges every site on
  its own zone until work locations can carry one.
- HR's manual times are clock readings on the work date; a reading earlier than the one
  before it is the next morning, so a night shift is entered as it is worked (in 22:00,
  out 06:00).
- **Manual punches were not reinterpreted.** A `source = manual` punch written before this
  change is a UTC clock-face time; the assistant's `record_punch` also writes
  `source = manual`, with a correct instant, so a blanket shift by the zone offset would
  corrupt those. No tenant had manual punches when this shipped, so no such command was
  built; recorded here in case real data ever needs it.
- The profile request now requires `timezone`, so any client posting a company profile
  must send it.
- `AttendanceCalculator::recompute()` takes a `DayRules` instead of a `WorkSchedule`, and
  `AttendanceClock::refresh()` no longer takes an employee.
