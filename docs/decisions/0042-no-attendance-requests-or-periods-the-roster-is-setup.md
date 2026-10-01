# 0042 — No attendance requests or periods; the roster is setup

- **Status:** Accepted
- **Date:** 2026-09-23
- **Supersedes:** [0039 — Attendance requests and period lock](./0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md),
  except its sign-off
- **Amends:** [0037 — Schedules are templates, assignments are dated](./0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)
  (where the roster lives)
- **Related:** [Attendance module](../modules/attendance.md),
  [Work Schedule & Holidays](../modules/work-schedule-holidays.md),
  [attendance tables](../database/attendance-tables.md)

## Context

ADR 0039 added two features to attendance. **Requests** let an employee ask for a
correction, overtime, official business or remote work, and let a reviewer decide.
**Periods** closed attendance on a pay calendar, and a lock froze every day inside one.
Both reached into the core: every write in the engine checked the lock, and the
evaluator read approved requests on every day it judged. The board had grown to six
tabs.

The attendance module is meant to be the record: who punched, what the day came to,
and what HR corrected. The requests and the period lock are a workflow layered on top of
that record, and this system does not need it. HR's manual entry, which is logged and
keeps the punches it replaces, already covers a correction.

The roster was also a tab on the attendance board. It is not a record. It is the plan
each day is judged against, made of the schedules in Company Setup.

## Decision

- **Requests are removed**, with every path to them: the web inbox and self-service, the
  mobile screens and API, the assistant's three tools, the notifications and the
  permissions `attendance.request` and `attendance.requests.review`. The evaluator no
  longer reads official business or remote work. A day is judged by its punches again,
  and the geofence excuses nobody.
- **Periods and the lock are removed**: the calendar, `PeriodLock` and its guard on
  every engine write, the payroll file kept at a lock, `attendance:periods`, and the
  permissions `attendance.period.manage` and `attendance.period.unlock`. The board's
  **Payroll summary** download (ADR 0038) stays. It was only ever the same columns.
- **Sign-off stays.** `approval_status` is still derived from the review flags. The
  overtime a sign-off grants (`signed_off_overtime_minutes`) is now the only thing the
  evaluator reads as granted.
- **HR's edits still leave a trail.** Punches stay soft-deletable. A punch an edit
  replaces is kept and shown struck through.
- **The roster moves to Company Setup** as its own page, `/setup/roster`, beside Work
  Schedule & Holidays. Its abilities are renamed in place, so every role that held them
  still does: `attendance.roster.view` / `.manage` → `setup.roster.view` / `.manage`.
  The board keeps what happened: Today's Log, Weekly View and Monthly Report.
- **One forward migration** (`…_remove_attendance_requests_and_periods`). It drops both
  tables, the punch columns that named a request and the organisation's period
  calendar. It turns `correction` punches into `manual` ones and clears activity-log
  pointers to the rows it drops. Its `down()` brings back the structure, not the data.

## Consequences

- An employee can no longer ask for a fix themselves. They tell HR, who enters it. The
  refusals that used to say "ask for a correction" now say so.
- Nothing is ever final. A day can be edited or re-judged at any time, so payroll takes
  the summary download as it stands. If a period close is needed again, it returns as a
  decision of its own, not by bringing ADR 0039 back.
- Official business and remote work have no representation. A day away from the site
  under a `flag` geofence waits for sign-off, and under `block` it is refused.
- The attendance engine has one fewer cross-cutting guard, and the evaluator's context
  is one number smaller.
