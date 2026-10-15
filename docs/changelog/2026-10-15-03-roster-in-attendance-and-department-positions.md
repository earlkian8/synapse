# The shift roster moves to Attendance, and suggested departments bring their positions

The shift roster sat in Company Setup and in the setup wizard. Planning who works which
shift is weekly attendance work, though, and on day one the wizard's roster step could
only be skipped (no employees yet). It is now a section of Attendance, beside the time
records it explains. Separately, the wizard's Departments step created departments with
no positions; each suggested department now brings its usual positions, and any
department added there can take positions typed for it.

See [ADR 0090](../decisions/0090-the-roster-is-attendances-and-departments-bring-their-positions.md).

## Highlights

- **Attendance → Shift roster** at `/attendance/roster`. Attendance's header switches
  between **My attendance** ‖ **Time records** · **Shift roster**, each shown only to
  people who may open it. The sidebar has one Attendance entry; someone who may only
  see the roster lands on it.
- **Gone from Company Setup**: no Shift Roster in the sidebar's Company Setup section,
  and no roster step in the wizard (11 steps). `/setup/roster` redirects to the new
  address.
- **Departments with positions.** Ticking a suggested department (Finance, IT, …) opens
  its usual positions under the card, already selected; untick any, or type your own.
  Departments you name yourself, or customise, have the same field. The toast says what
  was made: "3 departments and 9 positions added."

## Backend

- Roster routes moved to `routes/attendance.php` (`attendance.roster.*`). The controller
  is now `Attendance\ShiftRosterController` and the page `attendance/roster`;
  `Queries\ShiftRosterScreen` builds its props. `AttendanceController@index` sends a
  roster-only viewer to the roster. A 301 redirect keeps `/setup/roster` working.
- Migration `2026_10_15_000000_move_the_shift_roster_into_attendance` renames
  `setup.roster.view` / `.manage` → `attendance.roster.view` / `.manage` in place,
  keeping every role's grant (checked both ways on a scratch database); the registry
  lists them under Attendance.
- `CompanySetup`: the roster step is removed from `STEPS`, `ABILITIES`, `SCREENS` and
  `configured()`. The system guide's step names are updated to match.
- The assistant's roster tools, the data export (the roster table is under
  *Workforce*), the Help Center (the *Shift roster* article moves to Workforce) and the
  system guide use the new names and address.
- `SetupBlueprints::departments()` gives each suggested department its positions.
  `WizardDepartmentsRequest` accepts `positions` (keyed by ticked code) and
  `custom.*.positions`: trimmed, kept once regardless of capital letters, at most 30 a
  department, and only for a department being added. `SetupDefinition` and
  `SetupInstaller::departments()` create them, and the installer now returns both
  counts.

## Frontend

- `features/attendance/components/attendance-nav.tsx`. The board's header holds the
  sections; **Sign off N days** and **Re-apply rules** moved into its toolbar.
  My attendance shows the sections instead of a back link. Its layout no longer runs
  off the right edge on a phone: the clock card was cut off at 390 px.
- `pages/attendance/roster.tsx` replaces `pages/setup/roster.tsx`. `RosterManager` no
  longer takes a heading or a reload target (the wizard was its other user), and
  **Assign a schedule** sits in the roster's toolbar.
- `app-navigation.ts`: Attendance shows for `attendance.view` or
  `attendance.roster.view`; the Setup entry is removed.
- Setup wizard: the roster step, its type and its screen are removed.
  `components/position-picker.tsx` is new, and the departments step uses it on ticked
  suggestions and on your own rows.
- Help articles: *Setting up your company* (11 steps), *Shift roster* (now in
  Workforce), *Work schedules and holidays* (links).

## Notes

- Role grants carry over with the rename, so nobody gains or loses roster access.
- A company that already answered the old roster step keeps that answer stored; nothing
  reads it now.
- Positions come in with titles only. Salary grades are set afterwards on the
  department board.
- Checked in Chromium at 1440 and 390 px: the board, the roster and My attendance with
  their sections, the `/setup/roster` redirect, an 11-step wizard, and the departments
  step (Finance with Accountant unticked and *Tax Specialist* typed, IT as offered, a
  custom *Quality Assurance* with *QA Lead*), which reported "3 departments and 8
  positions added." and created exactly those.
