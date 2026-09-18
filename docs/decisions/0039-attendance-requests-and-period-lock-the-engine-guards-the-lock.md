# 0039 — Attendance requests and period lock; the engine guards the lock

- **Status:** Accepted
- **Date:** 2026-09-18
- **Builds on:** [0036 — Attendance judged in local time on shift-anchored dates](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md),
  [0038 — Attendance policies: presets and typed options, snapshotted per day](./0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md)
- **Related:** [Attendance module](../modules/attendance.md),
  [Mobile app](../modules/mobile-app.md),
  [Notifications](../modules/notifications.md),
  [attendance tables](../database/attendance-tables.md),
  [0009 — Leave management](./0009-leave-management.md)

## Context

After ADR 0038 a day was judged by the company's own rules, but three things around
the judgement were missing:

- **An employee could not fix their own day.** A forgotten clock-out needed HR to open
  the record and retype every punch, which left no trail of who asked, or why.
- **Nothing needed a decision, so nothing got one.** `approval_status` existed on every
  record and the board had an "approve all" button and a pending count, but nothing
  ever set `pending`. Overtime under a policy that required approval sat outside the
  approved bucket with no way in.
- **Nothing was ever final.** Any day, however old, could change, so the summary sent to
  payroll could stop matching the system the next morning.

A day away from the site (a client visit, field work, a remote day) had no
representation either; it was an absence.

## Decision

### Requests

- **One table, four types.** `attendance_requests` holds a `correction`, `overtime`,
  `official_business` or `remote_work` request: whose, which dates (`start_date` /
  `end_date`; the single-day types keep them equal), a JSON `payload` validated per type,
  a required `reason`, an optional attachment, `status`
  (`pending`/`approved`/`rejected`/`cancelled`), the decision (`reviewer_id`,
  `reviewed_at`, `review_note`) and `requested_by` — HR can file on somebody's behalf.
- **One validation, one filer, one approver.** `StoreAttendanceRequestRequest` (the
  rules per type, and the checks rules cannot express) is used by the web, the mobile API
  and the assistant. `AttendanceRequestFiler` files; `AttendanceRequestApprover` decides
  and cancels. The filer refuses a range touching a locked period (it could never be
  approved) and a second pending request of the same type for the same day.
- **A correction changes only what it names.** HR's manual edit retypes the whole day;
  a correction replaces the first clock-in, first break, first break end or last
  clock-out it gives a time for and keeps every other punch the employee actually made.
  The replaced punch is **soft-deleted** and names the request
  (`replaced_by_request_id`); the new one is `source = correction` and names it too
  (`attendance_request_id`). The day is marked manual and recomputed. Punches are
  soft-deleted from now on, by HR's edits as well, so every fixed day keeps what it said
  before.
- **Overtime approval lives in the evaluator, not beside it.** `DayContext` gains
  `grantedOvertimeMinutes`: null while nobody has decided the day's overtime, otherwise
  the larger of HR's sign-off and the approved overtime requests' minutes. The evaluator
  approves `min(computed, granted)`. So an approval is **capped at what was worked**, a
  **pre-approval** filed before the day is matched whenever the day is evaluated, and a
  **rejection** is a decision too — the day stops waiting (`unapproved_overtime` is raised
  only while `granted` is null). Because the grant is read on every evaluation, it
  survives `attendance:recompute` and re-apply.
- **Official business is a full working day.** An approved request makes each working
  day in range present: with no punches, worth the shift's required minutes; with
  punches, never late and never short, and worth at least the shift's hours. Flag
  `official_business`. Approved leave and a non-working holiday still win; a rest day
  stays a rest day. Approval opens a record for every working day in range so the board
  and the payroll summary show it.
- **Remote work is recorded now and exempts later.** Covered days carry the
  `remote_work` flag; punches are still required. Phase 4's geofence reads the flag
  (a pending test says so).
- **Mirror Leave's flow, fix its gaps.** The inbox, the review modal and the notification
  pattern follow Leave (ADR 0009). Two things differ on purpose: reviewers are
  **whoever holds `attendance.requests.review`** (`Notifier::toPermission()`), not one
  hard-coded role; and **nobody decides their own request** — the approver refuses it, the
  resource says `can.review = false`, and a bulk decision skips it and counts it.

### Sign-off

- **`approval_status` means *needs sign-off*, and is derived.** `DayResult` writes it on
  every evaluation: `pending` while the day carries a flag in `DayResult::REVIEW_FLAGS`
  (today `unapproved_overtime`; Phase 4 adds `auto_closed` and `outside_geofence`),
  `approved` once somebody signed the day off and nothing new needs review, null
  otherwise. It cannot drift from the day.
- **Signing a day off grants its overtime as it stands.**
  `AttendanceClock::signOff()` stores `signed_off_overtime_minutes` = the day's overtime
  at that moment, so overtime a later correction adds is *not* quietly approved with it —
  the day goes back to pending. Nobody signs off their own day. "Approve all" only
  touches pending days, one at a time through the engine, leaving (and counting) the
  signer's own and any in a locked period.

### Periods and the lock

- **`attendance_periods`**, generated on the organisation's calendar
  (`organizations.attendance_period_frequency`: `weekly`, `bi_weekly`, `semi_monthly` —
  the default — or `monthly`). **Contiguous and never overlapping**: each new period
  starts the day after the last one ends and runs to the next boundary of the current
  frequency, so a change of calendar produces one short period rather than a gap or two
  periods claiming one day. A company's **first** periods start one period back, so the
  period that just ended can be locked. `attendance:periods` runs daily to keep the
  current and next period generated; HR can generate on demand.
- **Frequency is per organisation** (open decision 4 of the plan): one company rarely
  closes attendance on two calendars. It is set on the Periods tab, not the company
  profile — the person who closes periods chooses their calendar.
- **A checklist before locking**: requests still pending in range, days still
  `incomplete`, days still awaiting sign-off. HR can lock with the checklist open only by
  giving a reason, kept on the period (`lock_note`) and in the log.
- **The engine guards the lock, not the controllers.** `PeriodLock` is the one guard.
  `AttendanceClock` asks it before a punch, `openRecord()`, a manual edit, a correction,
  a sign-off, a re-apply and a delete; `AttendanceRequestApprover` before any decision;
  the filer before filing; `attendance:recompute` before each day. Bulk paths (re-apply
  over a range, approve all, recompute) leave a locked day exactly as it is and say how
  many they left. A lock refusal is an `AttendanceLockedException`, a kind of
  `AttendancePunchException`, so every path that already reports a refused punch — web,
  mobile, assistant — reports it without knowing about periods. The guard reads the
  database fresh before every write, and the locked ranges once per request for display.
- **Locking writes the period summary and keeps it.** `PeriodSummaryExport` (extracted
  from the export controller, which now uses it too) writes the ADR 0038 summary to the
  private `local` disk inside the transaction that locks; the period keeps the path. What
  payroll received can always be downloaded again, even after an unlock and a correction.
- **Unlock is its own permission, always needs a reason, and is always logged.** The file
  from the lock is kept; locking again writes a new one.

### Permissions, notifications, assistant

- New abilities: `attendance.request` (Staff, Department Head, HR Manager),
  `attendance.requests.review` (Department Head, HR Manager), `attendance.period.manage`
  and `attendance.period.unlock` (HR Manager — the plan's "or Admin only" is not a role
  this app has). The migration grants the first two to every existing organisation's
  built-in roles.
- Notifications: a new request → every reviewer but the employee; a decision → the
  employee and whoever filed it, with the note; a period coming due (its end within
  `attendance_lock_reminder_days`, default 2) → holders of `attendance.period.manage`,
  once.
- The assistant gains `file_attendance_request`, `find_attendance_requests` and
  `review_attendance_request`, through the same validation, filer and approver. Its
  attendance module is now available to anybody who files or reviews requests, and
  `find_attendance` answers for yourself without `attendance.view`. The retrieved brief
  lists the subject's pending requests and days on official business.

## Consequences

- **A correction is a single day.** Official business and remote work span up to 31
  days; a correction or an overtime request concerns one date. Shift swaps between
  employees are a possible fifth type, later.
- **A correction collapses at most one break.** It replaces the *first* break start and
  end, as the manual-edit form does; a day with two breaks needs HR's edit for the second.
- **Overtime a decision granted less of stays unapproved without waiting.** Asking for
  60 of 90 worked minutes and being approved leaves 30 unapproved and the day settled.
  A second overtime request can ask for the rest.
- **Approving official business opens records ahead of time** for future working days in
  range, frozen with the rules in force at approval. Re-apply changes them, as for any
  day.
- **A pending request can be cancelled; a decided one cannot** — reversing an approval is
  HR's edit or a new request.
- **Every evaluation reads the day's decided requests** — one more query per day
  evaluated, the same cost as the approved-leave check beside it.
- **The period calendar is one per organisation**, and a bi-weekly calendar starts from
  whichever Monday its first period did.
- **Existing records get a derived `approval_status`** the next time they are evaluated;
  on the dev database `attendance:recompute --dry-run` moved nothing.
