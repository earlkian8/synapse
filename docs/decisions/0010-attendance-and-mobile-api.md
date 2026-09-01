# 0010 — Attendance (DTR): a punch-event model and a token API for mobile

- **Status:** Accepted
- **Date:** 2026-06-15
- **Related:** [Attendance module](../modules/attendance.md),
  [attendance tables](../database/attendance-tables.md), [ERD](../database/erd.md),
  [0004 — Employee ↔ User](./0004-employee-user-separation.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0009 — Leave management](./0009-leave-management.md)

## Context

After Leave, the other half of HR operations is **attendance** — the Daily Time Record.
`work_schedules` already existed (start/end time, `work_days`, `grace_minutes`,
`required_hours`) and every employee belongs to one, but nothing consumed it. The new
need also has a twist the other modules don't: it must **talk to a future mobile app** so
employees can clock in/out from a phone with location and a selfie — and that app doesn't
exist yet, so the backend has to be built first.

Two design questions drove this:

1. **How to store a day's punches.** A single row with fixed `time_in / break_out /
   break_in / time_out` columns is simplest, but it caps the day at four punches, can't
   carry per-punch context (where/how each punch happened), and has nowhere to attach a
   selfie or GPS fix — exactly the audit trail a mobile DTR needs.
2. **How a phone authenticates.** The web uses session-based Fortify. A mobile client
   can't ride a session cookie; it needs a bearer token.

## Decision

Build **Attendance** as an operational module (route prefix `/attendance`, its own
`routes/attendance.php`) to the established pattern, with a **two-table** model and a
**Sanctum token API** for mobile.

- **A daily summary + raw punch events.** `attendance_records` holds one computed row per
  employee per day (status + minute totals + schedule snapshot + an approval lifecycle);
  `attendance_punches` holds each punch as an event (`type`, `punched_at`, `source`, GPS,
  selfie, note, `recorded_by`). The summary is **always derived from the punches** — never
  trusted from the client — so it can't drift, mirroring how leave balances are derived.
  This also models split shifts and multiple breaks naturally, and makes every punch
  auditable (where it came from, where the device was, who recorded it).

- **One canonical punch engine.** `AttendanceClock` (punch + transition rules +
  recompute) and `AttendanceCalculator` (the pure computation) live in
  `app/Support/Attendance` and are the single path for **web self-service, the mobile API
  and the assistant**. The model only ever *decides* to punch; the engine *enforces* the
  rules (valid transitions) and *computes* the totals. Time arithmetic is done on UNIX
  timestamps so it is agnostic to the app's `CarbonImmutable` default.

- **Computation against the schedule.** Late = `first_in − (scheduled_start + grace)`;
  undertime vs `scheduled_end`; overtime = worked − `required_hours`; a weekday outside
  the schedule's `work_days` is a `day_off`. Approved **leave** (reusing
  `LeaveRequest::coversDate()`) makes a no-punch day `on_leave`, not `absent`.

- **Mobile auth via Sanctum personal access tokens.** Installed Laravel Sanctum and added
  `HasApiTokens` to `User`. `POST /api/auth/login` exchanges credentials (rejecting
  inactive accounts) for a token; the `auth:sanctum` group covers `me`, `attendance/today`,
  `attendance/punch` and `attendance/records`. The web's Fortify flow is untouched —
  tokens are a *parallel* surface for non-browser clients. The existing
  `SetCurrentOrganization` middleware is appended to the **`api`** middleware group so the
  tenant is bound from the token's user and every query stays isolated.

## Consequences

- Recomputation reads a day's punches on every punch. Days are tiny (a handful of rows),
  so this is cheap and keeps the summary honest.
- A correction is just a re-write of a record's punches (`applyManualPunches`) followed by
  the same recompute — HR corrections and live punches converge on one code path.
- **Out of scope (deliberate):** a holiday calendar (the `holiday` status exists but
  nothing sets it yet) and biometric-device ingestion (`source` models it; no integration
  is built). Work-schedule CRUD remains a separate Company-Setup concern.
- Sanctum's `personal_access_tokens` table is standard and works on Postgres and SQLite.
