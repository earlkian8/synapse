# Attendance without requests or periods, and the roster in Company Setup

Attendance had grown past the record it is meant to be. Phase 3 (ADR 0039) added
**requests**: corrections, overtime, official business and remote work, each filed and
then decided. It also added **periods**, a pay calendar whose lock froze every day inside
it. Both reached into the engine, and the board had six tabs. This change removes both
and keeps the record: who punched, what the day came to, and what HR corrected. The
**shift roster** is the plan each day is judged against, not something that happened,
so it moves to Company Setup beside the schedules it is made of (ADR 0042).

## Highlights

- **The board has three tabs again:** Today's Log, Weekly View and Monthly Report.
  Requests and Periods are gone. An old `?tab=requests` / `periods` / `roster` link opens
  Today's Log.
- **Company Setup → Shift Roster** (`/setup/roster`) is the roster's new home. It works
  as before: the week across, a pin on one-off overrides, click a cell to override it,
  **Assign a schedule**, step forward past today. It gains its own search and department
  filter.
- **My Attendance** is the clock, today's punches, the month and the history again. A
  missed punch is fixed by HR's manual entry.
- **Sign-off stays.** Days with a review flag still wait for it, and **Sign off all**
  still clears them. A sign-off is now the only way overtime that needs approval is
  granted.
- **A day is judged by its punches alone.** Official business no longer makes a day
  present without them. Neither it nor remote work excuses the geofence or a clock-in
  reminder.
- **Nothing is locked.** Every day can be edited and re-judged, and the **Payroll
  summary** download (ADR 0038) is what payroll takes.

## Backend

- **Removed:**
  - `AttendanceRequest` and `AttendancePeriod`, their controllers, form requests and
    resources, and `AttendanceRequestsIndexQuery`.
  - `AttendanceRequestFiler`, `AttendanceRequestApprover`, `PeriodCalendar`,
    `PeriodLock`, `PeriodLocker`, and the `AttendanceLockedException` and
    `AttendanceRequestException` exceptions.
  - The `attendance:periods` command and its schedule.
  - The mobile API's `/api/attendance/requests` routes.
- **`AttendanceClock`:**
  - No lock check on any write, and `applyCorrection()`, `requestContext()` and
    `lockedPeriodsBetween()` are gone.
  - `reapplyMany()` loses its `$skipped` out-parameter.
  - The geofence no longer asks whether a request excuses the day.
  - The refusals that said "ask for a correction" now say to ask HR.
- **Evaluator:**
  - `DayContext` loses `officialBusiness` and `remoteWork`.
  - `grantedOvertimeMinutes` is read from `signed_off_overtime_minutes` alone.
  - `DayResult` drops the `official_business` and `remote_work` flags.
- **Jobs and commands:** `DayCloser`, `RecomputeAttendanceRange`, `attendance:recompute`,
  `attendance:remind` and `attendance:prune-orphan-days` lose their lock and request
  checks.
- **`PeriodSummaryExport` stays** for the board's download, without its locked-period
  `contents()`.
- **Roster:**
  - `ShiftRosterController` moves to `App\Http\Controllers\Setup` and gains `index()`,
    which renders `setup/roster`.
  - Its routes are now `setup.roster.index|store|destroy|assign` under `/setup/roster`.
- **Permissions:**
  - `attendance.roster.view|manage` are now `setup.roster.view|manage`, in the Company
    Setup group.
  - `attendance.request`, `attendance.requests.review`, `attendance.period.manage` and
    `attendance.period.unlock` are gone.
  - Staff and Department Head lose the request abilities.
- **Assistant:**
  - `file_attendance_request`, `find_attendance_requests` and
    `review_attendance_request` are gone, and so are the brief's pending requests and
    official-business days.
  - The module is offered with `attendance.view` or `attendance.clock`.
- **Migration** `…_remove_attendance_requests_and_periods`:
  - Renames the roster permissions in place. If a registry sync already created the new
    names, it moves the grants onto them.
  - Turns `correction` punches into `manual` ones.
  - Clears activity-log pointers to dropped rows.
  - Drops the punch request columns, the organisation's period calendar and both tables.
  - Keeps punch soft deletes and `signed_off_overtime_minutes`.
  - `down()` restores the structure without the data.

## Frontend

- **Removed:** the requests inbox, the review and file dialogs, My requests, the periods
  panel and the request status badge. So are the request, period and lock types, the
  constants and routes, and the day modal's lock badge and request list.
- **New `features/roster/`:** the grid and override dialog, moved from attendance, plus a
  week toolbar, routes and types. The new page is `pages/setup/roster.tsx`.
- **Sidebar:** Company Setup gains **Shift Roster**.
- **Policy editor:** the geofence, offline-window and reminder hints no longer mention
  requests, official business or remote work.
- **Mobile:** the request and requests screens, the day screen's *Request a correction*
  and the Attendance tab's *Requests* button are gone.

## Notes

- **Deploying:** run the migration, then `php artisan attendance:recompute`. A day
  recorded as official business or remote work is judged by its punches from then on.
  Any payroll files written at a lock stay on the private disk, but nothing points to
  them any more.
- **On the dev database** the migration dropped no requests and three unlocked periods.
  It rolled back and re-ran cleanly, and `attendance:recompute --dry-run` moved none of
  676 days.
- **Verification:**
  - Pest: **992 passed, 7007 assertions**, serial on Postgres. The last count was 1049;
    the difference is the deleted request and period suites and the lock and
    remote-work cases, plus two new roster-page tests.
  - Pint, tsc, ESLint (whole project), Prettier and `npm run build` are green.
  - Mobile tsc reports only the existing `app/(tabs)/_layout.tsx` error, and mobile
    ESLint passes on the touched files.
  - Walked in a real browser against the built assets: the three-tab board, Company Setup
    → Shift Roster with the override and assign dialogs, week navigation, My Attendance,
    an old `?tab=requests` link, dark and light, and 390px.
