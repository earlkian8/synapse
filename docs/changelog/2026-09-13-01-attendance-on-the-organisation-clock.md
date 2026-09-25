# Attendance keeps the organisation's clock

Clock in at 08:30 in Manila on an 08:00 shift and, until now, the day came out
**not late — and eight hours short**. The server judged every day in UTC: the punch was
stored as 00:30Z, the shift's "08:00" was read as 08:00Z, and a 07:45 clock-in was filed
under the day before. The browser showed the right times, which is why nobody saw it.

It was one of four things wrong with how a day was worked out. A night shift could not
clock out: the 06:00 clock-out opened an empty record for the new date, was then refused,
and left that empty day on the board as an absence. Correcting an old day re-judged it by
whatever the schedule said today. And holidays were ignored, so everyone was absent on
Independence Day.

This is Phase 0 of making attendance fit any company: no new features, just the right
numbers.

## Highlights

- **Every company keeps a clock.** A time zone on the Company Profile and on step one of
  setup (where it starts from the browser's). Lateness, "today", which day a punch belongs
  to, and every time on the board, the export, the assistant's cards and the mobile app
  are read on it — whatever zone the viewer or the phone is in.
- **Night shifts work.** Clock in at 22:00, out at 06:00: one day, eight hours, on the
  date the shift started. A 21:30 clock-in belongs to that shift, and so does a late one
  at 00:30. At 02:00 the clock card shows the shift under way, not an empty new date.
- **A refused punch leaves nothing behind.** The day is checked before anything is saved.
- **A day keeps the rules it was recorded with.** Editing a schedule's grace period, or
  moving someone to another shift, no longer changes days already recorded. **Re-apply
  schedule** on the day modal, or **Re-apply schedules** over the day, week or month on
  screen, re-judges on purpose, and says so in the activity log.
- **Holidays count.** A regular or special non-working holiday nobody worked reads
  **Holiday** on the board, the weekly grid (with the holiday's name), the monthly report
  (a new *Holidays* column, kept out of the attendance rate) and the export. Approved leave
  still wins; a special *working* holiday is a normal working day; a holiday somebody worked
  is judged like any day.
- **Night shifts are entered by hand the way they are worked.** In 22:00, out 06:00 — a
  time earlier than the one before it is the next morning.

## Backend

- **New** `Support\OrganizationClock` — `now()`, `today()`, `at($date, $time)`,
  `localDate()`, `local()`, and the zone list for the pickers. The only code that knows
  the tenant's zone. Storage stays UTC.
- **New** `Support\Attendance\DayRules` — the versioned snapshot a day is judged by
  (grace, required minutes, working day, schedule, holiday).
- `AttendanceClock` gains `workDateFor()` (open shift within 16 hours → the shift window of
  today's or yesterday's working day, from 4 hours before its start → the local date),
  `currentRecord()`, `snapshot()`, `fillSnapshot()`, `reapplySchedule()`, `evaluate()` and
  `shiftInstants()`. `punch()` runs in a transaction holding a lock on the employee and
  saves the day only after the transition is accepted. `refresh()` no longer takes an
  employee.
- `AttendanceCalculator::recompute()` takes the day's `DayRules` instead of a schedule,
  reads the shift from stored instants, and exposes `noPunchStatus()` — on leave, holiday,
  day off, absent — which the roster queries now share.
- `HolidayCalendar::on()` and `inRange()` return holidays of every type, loaded once per
  range.
- `PATCH /attendance/records/{record}/reapply-schedule` and
  `PATCH /attendance/reapply-schedule` (`from`, `to`, optional `department`; at most 62
  days), both `attendance.manage`, both logged.
- The board, weekly and monthly queries, self-service, the mobile API, the assistant's
  attendance module, the export and the statistics all read the organisation's today.
  `holiday` joins the board's status filter; the monthly report and CSV gain
  `holiday_count`.
- `UpdateCompanyProfileRequest` requires a `timezone` from PHP's canonical list. The zone
  is shared as `auth.organization.timezone` and in the mobile session's `organization`.
- **Commands:** `attendance:recompute {--organization=} {--from=} {--to=} {--dry-run}` —
  fills missing snapshots, recomputes, and prints what moved;
  `attendance:prune-orphan-days {--organization=} {--dry-run}` — deletes the empty days
  refused overnight clock-outs left.
- `AttendanceSeeder` writes real instants on the organisation's clock, runs night shifts
  across midnight, skips rest days and non-working holidays, and never seeds a punch that
  has not happened yet.

## Database

- `organizations.timezone` — string, default `Asia/Manila` (back-fills existing
  organisations).
- `attendance_records.scheduled_start_at`, `scheduled_end_at` (timestamps) and `rules`
  (json), all nullable.

## Frontend

- **New** `TimezoneSelect` — a searchable picker (city, region or "+08"), with the offset
  beside every zone — on the Company Profile (with "use this browser's zone") and in the
  wizard's company step.
- **New** `useOrganizationTimeZone()`. Every attendance time — the log, exceptions, punch
  trail, weekly tiles, history, the live clock card — is formatted on it, and the manual
  entry form reads and writes clock times on it. The toolbar's "today" is the
  organisation's, and its date stepping no longer goes through UTC.
- Day modal: the schedule's name and the holiday in the header, and *Re-apply schedule*
  in the footer. Board header: *Re-apply schedules* for the period on screen, behind a
  confirmation.

## Mobile

- `formatTime()` and `formatLongDate()` take a zone. The clock screen, the home card, the
  attendance list, the day screen and the calendar's "today" use the session's
  `organization.timezone`, falling back to the device clock if the zone is unknown.

## Notes

- Pest: **772 tests, 772 passed** (747 before). The 25 new ones cover: lateness and work
  dates in Manila and New York, manual entry stored as the right instant, a night shift
  clocked and entered by hand, clock-ins either side of midnight, the clock card after
  midnight, refused punches writing nothing, a frozen grace surviving a schedule edit,
  re-applying one day and a period (with the log and the permission), holiday precedence
  on the roster, a worked holiday, holidays in the weekly grid and monthly report, both
  commands (dry run and real run), and the company time zone's validation and sharing.
- Pint, `tsc`, ESLint, Prettier and `npm run build` are green. The mobile app type-checks
  except for one error in `app/(tabs)/_layout.tsx`, a navigation-library type mismatch in a
  file this change does not touch.
- The seeder was run inside a rolled-back transaction against the demo tenant: 667 days,
  no future punches, a night shift recorded 21:55 → 04:39 the next morning on its start
  date, and the dev database left as it was.
- **Existing demo data needs re-seeding.** `attendance:recompute --dry-run` on the dev
  database checked 731 days and would change all 731: the old seeder wrote clock-face times
  as UTC, so those punches are wrong as instants and recomputing them would only relabel
  wrong data. `attendance:prune-orphan-days --dry-run` found none.
- No `attendance:reinterpret-manual` command was built. No tenant had manual punches, and
  the assistant's `record_punch` also writes `source = manual` with correct instants, so a
  blanket shift would corrupt them. The reasoning is recorded in the ADR.
- Not verified in a browser or on a device; the screens were checked by type-check, lint
  and build only.
- See [ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md).
