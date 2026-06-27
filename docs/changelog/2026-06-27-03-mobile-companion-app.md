# Mobile employee companion app

Adds **SYNAPSE Mobile** — the employee-facing companion in `mobile/` (Expo SDK 54
+ expo-router) — and the backend it needs: hire-time login provisioning and a
self-service token API for leave, awards, profile and attendance metrics. It
talks to the live server over the existing Sanctum token surface and mirrors the
web brand. See [ADR 0020](../decisions/0020-mobile-companion-app.md) and the
[module doc](../modules/mobile-app.md).

## Highlights

- **Hired employees can finally sign in.** Hiring now provisions a `staff` login
  account linked to the new employee, with an optional "email login credentials"
  toggle on the hire dialog (default on).
- **Five product-quality surfaces + Home.** DTR clock (live clock, GPS + optional
  selfie, state-driven Time-In/Break/Time-Out), attendance calendar with a metrics
  summary, leave balances/filing/history with cancel, profile (masked IDs), and
  awards — with skeletons, empty states, pull-to-refresh, toasts, haptics, and
  light/dark themes.

## Backend

- **`EmployeeAccountProvisioner`** — one idempotent `User` per employee (temp
  password, `staff` role, `Employee.user_id` linked), called inside
  `ApplicantHirer::hire()`. New `ApplicantHirer::hire(..., bool $sendCredentials)`
  emails the password via the new **`EmployeeCredentialsNotification`** (mail-only,
  synchronous; works under `MAIL_MAILER=log`). `HireController` reads
  `send_credentials`.
- **`staff` role** gained `leave.request` (already had `attendance.clock`).
- **New `Api` controllers** (self-scoped behind `auth:sanctum`):
  `ProfileController`, `AwardController`, `LeaveController`
  (types/balances/index/store/cancel), and `AttendanceController@summary`. They
  reuse `LeaveCalculator`, `HolidayCalendar`, `LeaveBalanceService` and the
  existing resources; new `EmployeeProfileResource` masks government IDs / bank
  details and omits salary. New `Api\StoreLeaveRequest` forces the employee to
  the caller and computes days server-side.
- **Seeding** links `dev@synapse.com` and the `mock.staff*` accounts to employee
  records so the app is demoable on first sign-in (needs `migrate:fresh --seed`).

## Frontend (mobile)

- Token client (`lib/api.ts`) with 422 field-error parsing; `AuthProvider` with
  SecureStore; themed design system (`theme/`) and a shared UI kit
  (`components/ui/`); feature folders under `features/`.
- Expo Router file tree: auth stack (branded login), a 5-tab shell with an
  elevated centre Clock action, and stack detail screens (attendance day, leave
  new/detail, awards). API base URL is configurable via `app.json`
  `extra.apiUrl` / `EXPO_PUBLIC_API_URL`.

## Notes

- New mobile dependencies installed via `expo install`: `expo-secure-store`,
  `expo-location`, `expo-image-picker`,
  `@react-native-async-storage/async-storage`,
  `@react-native-community/datetimepicker`.
- Verified: backend `php -l` + Pint clean, `migrate:fresh --seed`, tinker smoke
  tests, and a live HTTP run of every endpoint (login, me, profile, awards,
  attendance today/summary, leave types/balances/requests, file + cancel, punch).
  Mobile `tsc` + `expo lint` clean and an Android bundle export succeeds. (Pest is
  not runnable locally — no `pdo_sqlite`.)
