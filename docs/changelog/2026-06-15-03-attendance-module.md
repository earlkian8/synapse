# 2026-06-15 — Attendance module (DTR), mobile-ready

The sidebar linked **Workforce → Attendance** but nothing was behind it. This adds a
complete **Daily Time Record** module: an HR daily board, employee self-service clocking,
full time computation against each employee's work schedule, and a **token-authenticated
API** so a future mobile DTR app can clock punches with GPS and a selfie — built now,
before the app exists. The `work_schedules` lookup (which every employee already belongs
to) finally has a consumer.

## Highlights

- **HR daily board** (`/attendance`) — a date-stepped roster of the whole team for any
  day, as a **card grid** (default) or compact **list**, with stat cards and search /
  status / department filters. Employees with no punches still appear as Absent / Day off
  / On leave. Each card opens a **day-detail drawer** with the full punch timeline (time,
  source, GPS pin, selfie) and HR actions: correct times, approve, delete.
- **Self-service clock** (`/attendance/me`) — a live clock card whose primary button
  flips with the day's state (Clock in → Start break → End break → Clock out), capturing
  geolocation and an optional selfie on each punch, plus today's timeline, a month summary
  and recent history.
- **Real computation** — worked hours, lateness (past the schedule's grace), undertime,
  overtime and a derived status, all server-side; approved **leave** shows as On leave,
  not Absent.
- **Mobile API (Sanctum)** — `POST /api/auth/login` issues a personal access token; the
  `auth:sanctum` group exposes `me`, `attendance/today`, `attendance/punch` (GPS + selfie)
  and `attendance/records`. The same punch engine as the web.

## Backend

- Migration `…_create_attendance_tables`: `attendance_records` (one computed row per
  employee/day) + `attendance_punches` (raw punch events with source, GPS, selfie). Models
  `AttendanceRecord` (hashid, `STATUSES`, scopes) and `AttendancePunch` (`photo_url`).
- Canonical services in `app/Support/Attendance`: **`AttendanceClock`** (punch + transition
  rules + manual entry + recompute) and **`AttendanceCalculator`** (worked/break/late/
  undertime/overtime + status, on UNIX-timestamp arithmetic). Leave-awareness reuses
  `LeaveRequest::coversDate()`.
- Web: `routes/attendance.php`; `AttendanceController` (board, show, manual store/update,
  approve, delete) + `MyAttendanceController` (self-service index + punch);
  `AttendanceRecordsIndexQuery` (the daily roster, synthesising rows for no-punch
  employees), `AttendanceStatistics`, `AttendanceRecordResource`, and FormRequests
  (`PunchRequest`, `StoreAttendanceRecordRequest`, `UpdateAttendanceRecordRequest`).
- API: installed **Laravel Sanctum**, added `HasApiTokens` to `User`, registered
  `routes/api.php` and appended `SetCurrentOrganization` to the `api` middleware group so
  the tenant is bound from the token's user. `Api\AuthController` + `Api\AttendanceController`.
- Permissions: new **Attendance** group (`attendance.view` / `.manage` / `.clock`) in
  `PermissionRegistry`. **HR Manager** granted all three; **Staff** granted
  `attendance.clock`. Permissions synced + roles re-provisioned.
- Assistant: `AttendanceModule` (`find_attendance`, `record_punch`) registered alongside
  the other modules; the orchestrator's scope note no longer excludes attendance.

## Frontend

- New `features/attendance/`: `types.ts`, `routes.ts`, `constants.ts` (status colours,
  punch metadata, duration/time formatters), `hooks/use-attendance-filters.ts`,
  `hooks/use-clock.ts` (live time + geolocation), `api.ts` (self-service punch), and
  components — stats, toolbar (date stepper + tabs + view toggle), roster card & list row,
  punch timeline, day-detail drawer, manual-entry sheet, clock card.
- Pages `attendance/index.tsx` (HR board) and `attendance/me.tsx` (self-service). Sidebar:
  Attendance gated by `attendance.view`; a new **My Attendance** entry gated by
  `attendance.clock`.

## Docs

- [Attendance module](../modules/attendance.md),
  [ADR 0010](../decisions/0010-attendance-and-mobile-api.md),
  [attendance tables](../database/attendance-tables.md).

## Notes

- Verified: `php -l` and `vendor/bin/pint` clean on all PHP; a tinker smoke test drove a
  full clock_in → break → clock_out day and confirmed the computed late / worked / break /
  overtime and status, the transition guard, the daily roster + statistics, and Sanctum
  token issuance; `tsc`, ESLint, Prettier and `npm run build` all green. The Pest suite
  can't run on this machine (no `pdo_sqlite`); migrations ran against Postgres.
- Deliberately out of scope: a holiday calendar (the `holiday` status exists but is unset),
  biometric-device ingestion (`source` models it; no integration), and work-schedule CRUD
  (a separate Company-Setup surface).
