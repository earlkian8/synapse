# Mobile app (employee companion)

The employee-facing companion to the web ERP, living in `mobile/` (Expo SDK 54 +
expo-router + TypeScript). It talks to the same backend over the
token-authenticated API in `server/routes/api.php` (Sanctum personal access
tokens), and mirrors the web brand so the two read as one product. See
[ADR 0020](../decisions/0020-mobile-companion-app.md).

## Who can sign in

A login account is provisioned **when an applicant is hired**
(`EmployeeAccountProvisioner`, called from `ApplicantHirer::hire()`): one `User`
with the `staff` role, linked to the `Employee`, with a generated temporary
password. The recruiter can opt to email the credentials at hire time
(`send_credentials`, default on) — `EmployeeCredentialsNotification`, mail-only
and synchronous, so it works under `MAIL_MAILER=log` with no SMTP. The `staff`
role grants `attendance.clock` and `leave.request`; all read endpoints are
self-scoped and need no further permission.

HR can re-issue access later from the **Employees** module: the **Reset
password** row action (`POST employees/{employee}/reset-password`,
`can:employees.update`) opens a right-side confirmation drawer that rotates the
password via `EmployeeAccountProvisioner::resetPassword()` — provisioning the
account first if the employee never had one — and re-sends the credentials email.

## Surfaces

- **Home** — greeting, today's clock state, quick actions, leave-balance
  mini-cards, latest award, pending-request badge.
- **Clock (DTR, the hero)** — live clock, today's shift, a state-driven primary
  button (Time In → Break → Time Out) driven by the server's `allowed` /
  `next_expected`. Captures real GPS (`expo-location`) and an optional selfie
  (`expo-image-picker`), submitted as multipart to `POST /attendance/punch`
  through the canonical `AttendanceClock`. Live worked-hours counter + status chip.
- **Attendance** — month calendar with status dots + legend, a metrics summary
  card (present/late/absent, hours rendered, late/OT minutes) from
  `GET /attendance/summary`, a list view, and a per-day punch-timeline detail.
- **Leave** — balances per type, a file-leave form (type → dates → half-day →
  reason) with server-computed days and inline 422 errors, history with status
  pills, and a detail screen with cancel.
- **Profile + Awards** — the 201 profile (government IDs masked, salary omitted)
  and the employee's recognitions.

## API (all behind `auth:sanctum`, self-scoped)

| Method & path | Purpose |
|---|---|
| `POST /api/auth/login`, `GET /api/me`, `POST /api/auth/logout` | Token session |
| `GET /api/attendance/today` · `POST /api/attendance/punch` · `GET /api/attendance/records` · `GET /api/attendance/summary` | DTR + metrics |
| `GET /api/profile` | Own 201 profile (masked IDs) |
| `GET /api/awards` | Own recognitions |
| `GET /api/leave/types` · `GET /api/leave/balances` · `GET /api/leave/requests` · `POST /api/leave/requests` · `PATCH /api/leave/requests/{id}/cancel` | Self-service leave |

Leave filing reuses `LeaveCalculator` + `HolidayCalendar` (days computed
server-side, never trusted from the client) and auto-approves types that don't
require approval — identical to the web `LeaveRequestController`.

## Running it

1. **Server:** `php artisan serve --host 0.0.0.0` (so a phone on the LAN can reach
   it). Seed first with `php artisan migrate:fresh --seed` — this links the demo
   accounts (`dev@synapse.com` / `password`, and `mock.staff*`) to employees.
2. **Point the app at your machine:** set `expo.extra.apiUrl` in
   `mobile/app.json` to `http://<your-LAN-IP>:8000/api`, or export
   `EXPO_PUBLIC_API_URL`. (Find your IP with `ipconfig`.)
3. **App:** in `mobile/`, `npx expo start`, then open in Expo Go on the phone.

## Conventions

- `lib/api.ts` — the single fetch client (base URL + Bearer token + 422 parsing).
- `lib/auth.tsx` — token in SecureStore, `/me` hydration, `login` / `logout`.
- `theme/` — design tokens (navy `#0F2044`, teal `#0ABFBF`, shared status colours),
  light + dark.
- `components/ui/` — the shared kit (Button, Card, Pill, Input, Sheet, Toast, …).
- `features/<module>/` — `api.ts` + components, mirroring the web feature folders.
