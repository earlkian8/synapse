# People ask for what the clock missed, and a closed period stays closed

Until now an employee could not fix their own day. A forgotten clock-out meant HR
reopening the record and retyping every punch, with no trail of who asked or why. The
approval fields on a day did nothing: "Approve all" and the pending count were always
empty, and overtime under a policy that needed approval could never be approved. A day
at a client's office was an absence. And nothing was ever final, so the summary sent to
payroll could stop matching the system the next morning.

This is Phase 3 of making attendance fit any company (ADR 0039). Employees ask for
corrections, overtime, official business and remote work. A reviewer decides. Days
that need a manager's eye wait for sign-off. A closed period is frozen, and the file
payroll received is kept with it.

## Highlights

- **Requests.** Four kinds, one flow:
  - **A correction.** Give only the times that change: "Time out 5:30 PM". Every other
    punch stays as it was.
  - **Overtime.** A future date makes it a pre-approval.
  - **Official business.** The day counts as a full working day even with no punches:
    nothing late, nothing short.
  - **Remote work.** Marked on the day. Punches are still required.

  Employees file from **My Attendance**, using **Request** or **Fix** on any day in
  their history. HR can file on someone's behalf. The mobile app and the assistant file
  the same four kinds.
- **A Requests tab on the board**, with how many are waiting. Each row reads the ask in
  words and can be approved or rejected in place. Several can be decided at once with
  one note.
- **A review modal that shows the record, not just the ask.** A correction is drawn as
  the day's punches next to the ones asked for, with each changed row reading
  "was → becomes". Overtime is a bar of what was worked past the shift against what is
  asked, saying exactly what approving would grant.
- **Nothing is erased.** A punch a correction (or an HR edit) replaces is kept and struck
  through in the day modal. The requests that concern a day are listed under its trail.
- **Nobody decides their own request, or signs off their own day.** The modal hides the
  buttons, the server refuses, and a bulk decision skips them and says so.
- **Sign-off means something.** A day with overtime awaiting approval is pending. **Sign
  off** approves its overtime as it stands, and **Sign off all** clears the queue. If a
  later correction adds overtime, the day goes back to pending.
- **A Periods tab.** Attendance closes on the company's calendar: weekly, every two
  weeks, twice a month (the default) or monthly. Each open period lists what stands
  between it and a lock: requests waiting, days missing a clock-out, days awaiting
  sign-off. Locking with any of those open needs a reason. Locking saves the **payroll
  file**, which can be downloaded again at any time. Unlocking is a separate permission,
  needs a reason, and is logged.
- **A locked day cannot change through any path.** That covers punches, HR edits,
  corrections, requests, sign-off, re-apply, delete and recompute, from the web, the
  mobile app or the assistant. The day modal and the employee's history show the lock.
- **Notifications.** A new request goes to every reviewer except the employee. A
  decision goes to the employee (and whoever filed it), with the reviewer's note. A
  reminder goes to period managers when a period is about to end.

## Backend

- **New tables** (`…_create_attendance_requests_and_periods`):
  - `attendance_requests`: type, dates, JSON payload, reason, attachment, status,
    decision, and who filed it; soft-deleted.
  - `attendance_periods`: dates, status, the lock and unlock audit, the saved file's
    path, and when the reminder went out.

  The migration also adds:
  - `attendance_punches`: soft deletes, plus `attendance_request_id` and
    `replaced_by_request_id`.
  - `attendance_records`: `signed_off_overtime_minutes`.
  - `organizations`: `attendance_period_frequency` and `attendance_lock_reminder_days`.

  It grants the new permissions to every existing company's built-in roles. Tested
  rolled back and re-run.
- **One validation, one filer, one approver.** `StoreAttendanceRequestRequest`,
  `AttendanceRequestFiler` and `AttendanceRequestApprover` are used by the web, the
  mobile API and the assistant alike.
- **Overtime approval lives in the evaluator.** `DayContext::grantedOvertimeMinutes` is
  the larger of HR's sign-off and the approved overtime requests. Approved overtime is
  `min(computed, granted)`. A rejection settles the day. A pre-approval is matched
  whenever the day is evaluated. Recompute and re-apply keep all of it.
- **`approval_status` is derived** on every evaluation (`DayResult::REVIEW_FLAGS`).
- **`AttendanceClock::applyCorrection()`** replaces only the punches it names.
  **`signOff()`** grants the day's overtime at that moment. **`deleteRecord()`**.
  Punches are soft-deleted by HR's edits too.
- **`PeriodLock`**, one guard for every path. The engine asks it before every write.
  Bulk paths skip locked days and count them. A lock refusal is an
  `AttendanceLockedException`, a kind of refused punch, so every existing path reports
  it. `AttendanceException` is the new base class for every attendance refusal.
- **`PeriodCalendar`**: contiguous periods on the company's calendar, starting one period
  back the first time. **`PeriodLocker`**: the checklist, lock and unlock.
  **`PeriodSummaryExport`**: the payroll summary, moved out of the export controller.
  The board's download and a locked period's file use it.
- **`attendance:periods`** runs daily at 00:15. It generates the current and next period
  and sends each due reminder once. **`attendance:recompute`** skips locked days and now
  reports sign-off changes. **`attendance:prune-orphan-days`** leaves days of official
  business alone.
- **`Notifier::toPermission()`**: every active member of the organisation who holds a
  permission, with exceptions.
- **HTTP:**
  - Requests: `POST /attendance/requests`, `GET …/{request}`, `PATCH …/{request}/review`,
    `PATCH /attendance/requests/review` (bulk), `PATCH …/{request}/cancel`.
  - Periods: `POST /attendance/periods/generate`, `PATCH …/settings`,
    `POST …/{period}/lock`, `POST …/{period}/unlock`, `GET …/{period}/export`.
  - The board gains `tab=requests` and `tab=periods`.
  - Mobile: `GET|POST /api/attendance/requests`, `GET …/{id}`, `PATCH …/{id}/cancel`.
    Filing is always for the token's own employee.
  - Every write is logged.
- **Permissions:**
  - `attendance.request`: Staff, Department Head, HR Manager.
  - `attendance.requests.review`: Department Head, HR Manager.
  - `attendance.period.manage` and `attendance.period.unlock`: HR Manager.
- **Assistant:** three new tools, `file_attendance_request`, `find_attendance_requests`
  and `review_attendance_request`. The attendance tools are now offered to anyone who
  files or reviews requests. The retrieved brief lists pending requests and days on
  official business.

## Frontend

- **`features/attendance`** gains `RequestsInbox`, `RequestReviewDialog` (the
  before/after punch diff and the overtime bar), `FileRequestDialog` (one dialog for the
  four kinds, choosing the kind first), `PeriodsPanel` (the calendar settings, the period
  rail and the lock/unlock dialog), `MyRequests` / `RequestMenu`, and
  `RequestStatusBadge`.
- **The board** gains the Requests and Periods tabs. The stat cards, the toolbar and
  **Re-apply rules** only show on the day views. **Approve N pending** is now **Sign off
  N days**.
- **The day modal** shows the lock, the replaced punches and the related requests. A
  locked day offers no actions.
- **My Attendance** gains **Request**, **Fix** on each history day, **Request a
  correction** on today's punches, and **My requests**.
- **Mobile app:** **Request a correction** on the day screen (unless locked), a
  **Requests** button on the Attendance tab, and two new screens,
  `app/attendance/requests.tsx` (the list, with cancel) and `app/attendance/request.tsx`
  (the form).

## Notes

- **A correction is one day, and touches one break.** A day with two breaks still needs
  HR's edit for the second.
- **Overtime granted in part stays partly unapproved without waiting.** A second request
  can ask for the rest.
- **Approving official business opens the future days in its range.** They carry the
  rules in force at approval. Re-apply changes them, as for any day.
- **Only a pending request can be cancelled.** Reversing a decision means an HR edit or
  a new request.
- **Remote work does not exempt anything yet.** The geofence arrives in Phase 4, and a
  pending test marks the gap.
- **After deploying**, run `php artisan attendance:recompute` to derive
  `approval_status` for days already recorded. On the dev database it changed none of
  676 days. Then open the **Periods** tab (or wait for tonight's `attendance:periods`) to
  lay out the first periods.
