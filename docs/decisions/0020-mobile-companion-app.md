# 0020 — Mobile employee companion app & hire-time account provisioning

- **Status:** Accepted
- **Date:** 2026-06-27
- **Related:** [Recruitment](../modules/recruitment.md),
  [Onboarding](../modules/onboarding.md),
  [Leave](../modules/leave.md),
  [Awards](../modules/awards.md),
  [Attendance](../modules/attendance.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0006 / 0007 — Hire bridge & onboarding provisioning](./0007-onboarding-provisioning.md)

## Context

SYNAPSE is a web HR ERP. Employees had no on-the-go surface of their own, and —
more fundamentally — **a hired applicant had no way to sign in at all**:
`ApplicantHirer::hire()` created an `Employee` but never a login account. The
mobile API foundation already existed (`routes/api.php`: token login + the
self-service DTR clock through the canonical `AttendanceClock`), but the other
employee self-service surfaces (leave, awards, profile, attendance metrics) had
no API, and there was no client.

## Decision

**1. Provision the login account at hire time.** A new
`App\Support\EmployeeAccountProvisioner` creates one `User` per employee
(temporary password, `staff` role, `Employee.user_id` linked) and is called from
inside the existing hire transaction. `ApplicantHirer::hire()` gained a
`bool $sendCredentials` argument; when set, the new hire is emailed their
temporary password via `EmployeeCredentialsNotification` (mail-only, synchronous,
so it works under `MAIL_MAILER=log` with no SMTP). The recruiter toggles the
email from the hire confirmation dialog (`send_credentials`, default on). The
`staff` role gained `leave.request` so rank-and-file can file leave; it already
had `attendance.clock`.

**2. Extend the token API, self-scoped.** New `Api` controllers (`ProfileController`,
`AwardController`, `LeaveController`, and `AttendanceController@summary`) sit
behind `auth:sanctum` and resolve **the caller's own** `Employee` via
`$request->user()->employee()` — exactly the guard the existing DTR endpoints
use. They **reuse** the web's support classes and resources wholesale
(`LeaveCalculator`, `HolidayCalendar`, `LeaveBalanceService`, `LeaveRequestResource`,
`EmployeeAwardResource`, …) so the figures and shapes match the web. Leave filing
forces `employee_id` to the caller and never trusts client-supplied `days`. A new
`EmployeeProfileResource` masks government IDs / bank details and omits salary.

**3. Build the client in `mobile/`** (Expo SDK 54 + expo-router): a token stored
in SecureStore, a single `fetch` client that surfaces Laravel 422 field errors, a
themed design system matching the web brand (navy + teal), and five feature
surfaces + a Home dashboard — DTR clock (GPS + optional selfie), attendance
calendar with metrics, leave balances/filing/history, profile, and awards.

## Consequences

- Every hired employee can sign in immediately; the credential email is optional
  and demoable without a mail server.
- The mobile and web self-service paths share one set of business rules — no
  divergence in how days, balances or punches are computed.
- Demo accounts (`dev@synapse.com`, the `mock.staff*` roster) are linked to
  employees in seeding so the app works on first sign-in; this requires a
  `migrate:fresh --seed` to take effect on an existing database.
- The native client adds dependencies (`expo-secure-store`, `expo-location`,
  `expo-image-picker`, `@react-native-async-storage/async-storage`,
  `@react-native-community/datetimepicker`), installed via `expo install`.
