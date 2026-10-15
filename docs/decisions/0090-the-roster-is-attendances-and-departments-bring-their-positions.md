# 0090 — The roster is Attendance's, and departments bring their positions

- **Status:** Accepted
- **Date:** 2026-10-15
- **Amends:** [0042 — No attendance requests or periods; the roster is setup](./0042-no-attendance-requests-or-periods-the-roster-is-setup.md)
  (where the roster lives; the rest of it stands)
- **Related:**
  - [0037 — Schedules are templates, assignments are dated](./0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md);
  - [Company setup wizard](../modules/company-setup-wizard.md),
    [Attendance module](../modules/attendance.md),
    [Departments](../modules/departments.md).

## Context

ADR 0042 moved the shift roster into Company Setup, on the grounds that it is a plan
rather than a record. In use, that put it in the wrong place:

- **Setup is done once; the roster is used every week.** Moving a team to the night
  shift, giving someone Saturday off, or checking who is due in tomorrow is the same
  work as reading the time records. HR went to Company Setup for a weekly task, and to
  Attendance to see what came of it.
- **It made the wizard ask a question it could not answer.** On day one a new company
  has no employees, so the wizard's roster step showed an empty grid that could only be
  skipped ("the roster fills in as people join").

The departments step had a smaller gap. Ticking *Finance* created the department with no
positions, so the owner had to open each department again on the board below and type
every job title. Employee records, job postings and appraisal frameworks all need a
position, so the company was not ready to use until that was done.

## Decision

### The roster is a section of Attendance

- **`/attendance/roster`**, beside the time records. Attendance gets a section switcher
  in its header (`AttendanceNav`, the module pattern Leave, Performance and Training
  use): *My attendance* ‖ *Time records* · *Shift roster*. Each shows only to someone
  who may open it.
- **One sidebar entry for Attendance**, shown with `attendance.view` or
  `attendance.roster.view`. Somebody who may see only the roster lands on it from
  `/attendance`.
- **The permissions go back to `attendance.roster.view` / `.manage`**, in the Attendance
  group. A migration renames them in place, so every role that held them still does.
  `down()` renames them back.
- **Company Setup loses the roster.** It is no longer in the sidebar's Company Setup
  section or in the wizard (11 steps, not 12). `/setup/roster` redirects (301) to the
  new address so old links keep working. The schedules the roster is built from stay in
  Company Setup → Work Schedule & Holidays: those are set up once.
- The Help Center article moves to *Workforce*, the data export's roster table to the
  *Workforce* section, and the assistant's roster tools take the renamed permissions.

### A suggested department brings its positions

- Every suggested department carries its usual positions (`SetupBlueprints::departments()`,
  the same titles the demo company uses, e.g. *Finance Manager, Accountant, Payroll
  Officer*).
- Ticking a department opens its positions under the card, **already selected**. The
  owner chose the department, and the titles are shown right there to untick, so the
  "nothing pre-ticked" rule (which is about choices made unseen) still holds.
- Any title can be typed beside them, and a department the company names itself (or
  *Customises*) has the same field. Titles are trimmed, blanks dropped, and each kept
  once regardless of capital letters. A department may bring up to 30.
- Positions arrive as titles only. Salary grades are set afterwards on the department
  board under the step, which is the same board Company Setup → Departments uses.
- A department the company already has is left alone, and so are its positions. The
  toast reports both counts: "3 departments and 9 positions added."

## Consequences

- The roster is next to the records it explains, and setup holds only what is set once.
- The wizard no longer shows a step a new company can only skip. Companies that had
  answered the roster step keep that answer in `setup_steps`, but it is no longer read.
- Roles keep their roster access through the rename. Code and docs that named
  `setup.roster.*` now name `attendance.roster.*`.
- A company that ticks the suggested departments has positions on day one, so the first
  employee record or job posting has a title to choose from.
