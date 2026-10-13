# Module: Admin portal

> Status: **Active** · App: [`admin/`](../../admin) (static React app) · API: `/api/admin` in
> `server/` · Decision: [ADR 0085](../decisions/0085-a-platform-admin-portal.md)

The console for the people who run SYNAPSE itself. It watches every organisation and ERP
account across tenants (read-only), manages its own admin accounts, and keeps an
append-only log of what admins did. It is a separate app with separate accounts: an
ERP login, even an HR Manager's, does not open it.

---

## 1. Feature overview

| Screen | Who | What it shows / does |
| --- | --- | --- |
| **Sign-in** | anyone | Password, then a TOTP code, every time. A first sign-in sets the authenticator up (QR code + key). |
| **Choose a password** | an admin holding an issued password | Nothing else opens until they choose their own. |
| **Overview** | all admins | Organisations (and how many are in setup), ERP accounts (and unverified), sign-ins in 30 days, employees, open app sessions; the newest organisations, the latest ERP sign-ins; for a super admin, the latest admin activity. |
| **Organisations** | all admins | Every organisation: status (ready / in setup / archived), members, employees, last activity, joined. Search, status filter, sort, paging. A detail dialog: size (members, employees, departments, waiting to join), activity (joined, setup finished, last activity, actions in 30 days), contact, HR Managers, newest members. |
| **Accounts** | all admins | Every ERP sign-in account: status (active / unverified / inactive / archived), workspaces, two-factor, last sign-in. Filters include "in no workspace". A detail dialog: workspaces with roles, email verification, two-factor, password age, open app sessions. |
| **Admin accounts** | super admins | Add (one-time password shown once), edit name / email / role, reset password, reset authenticator, deactivate / activate, delete (deactivated only). |
| **Activity log** | super admins | Every sign-in, failed password or code, sign-out, password change, ended session and admin-account change, with IP and browser. Filter by event and admin. Read-only. |
| **Account and security** | every admin | One's own details, password change, authenticator status, the session's end and idle limit, "Sign out other sessions". |

Monitoring is read-only on purpose: changing an ERP account or an organisation stays with
the organisation's own HR, inside the ERP.

---

## 2. Security model

- **Separate identity.** `admins` table, `App\Models\Admin`, `admin` guard (Sanctum,
  provider `admins`). The mobile API's `sanctum` guard is pinned to `users`, so neither
  kind of token passes for the other (`tests/Feature/Admin/AdminGuardTest.php`).
- **Own middleware group.** `admin-api` (bootstrap/app.php): `NoStore` (every response
  `Cache-Control: no-store`) and route bindings. There is no session, no cookie and no
  tenant: `SetCurrentOrganization` never runs, and monitoring queries lift the
  organisation scope explicitly.
- **Middleware on authenticated routes:** `auth:admin`, `admin.session` (an `Admin`
  with a real portal token carrying the `admin` ability and still active; a deactivated
  admin's token is deleted), `throttle:admin-api` (120/min), `admin.password-current`
  (403 `{reason: 'password_change_required'}` while a password is owed), and
  `admin.super` for admin accounts and the log.
- **Sign-in** (`App\Support\Admin\AdminSignIn`): constant-time password check (dummy hash
  for unknown emails), one message for wrong email or password, a challenge (64 chars,
  stored hashed in the cache, 5 minutes, 5 wrong codes), then a TOTP code
  (`AdminTwoFactor`, replay-guarded per admin). Throttles: `admin-login` 5/min per
  email+IP and 20/min per IP; `admin-two-factor` 10/min per IP.
- **Session** (`AdminSession`): a Sanctum token with `expires_at` = sign-in + 480 min,
  ended after 30 min without a request (the idle check runs in
  `AdminPortalServiceProvider` through `Sanctum::authenticateAccessTokensUsing`, before
  `last_used_at` moves). Expired tokens are pruned daily (`sanctum:prune-expired`).
- **Passwords** (`AdminPasswords`): 12+ characters, both cases, a number and a symbol,
  plus a breach check in production. Issued passwords are 24 random characters, shown
  once.
- **Governance** (`AdminAccounts`): nobody acts on their own role, status, password
  reset, authenticator or deletion; the last active super admin cannot be demoted,
  deactivated or deleted (checked under a row lock). Access changes end the target's
  sessions.
- **The browser side:** the token lives in `sessionStorage` (per tab, gone when the tab
  closes, never a cookie), so a second tab signs in again. Requests send
  `credentials: 'omit'` and `cache: 'no-store'`. No background refetching. A
  build-time CSP meta tag allows scripts from the portal only and requests to the ERP
  only. `noindex` and `no-referrer`.

---

## 3. API

Mounted in `bootstrap/app.php` (`then:`), routes in
[`routes/admin.php`](../../server/routes/admin.php), names `admin.*`.

| Method | URI | Controller | Gate |
| --- | --- | --- | --- |
| POST | `/api/admin/auth/login` | `AdminSessionController@login` | `throttle:admin-login` |
| POST | `/api/admin/auth/two-factor` | `AdminSessionController@twoFactor` | `throttle:admin-two-factor` |
| GET | `/api/admin/me` | `AdminSessionController@me` | session |
| POST | `/api/admin/auth/logout` | `AdminSessionController@logout` | session |
| PUT | `/api/admin/account/password` | `AdminAccountController@password` | session, `throttle:6,1` |
| POST | `/api/admin/account/sessions/revoke-others` | `AdminAccountController@revokeOtherSessions` | session |
| GET | `/api/admin/overview` | `OverviewController` | session + password current |
| GET | `/api/admin/organizations`, `/{id}` | `OrganizationController` | session + password current |
| GET | `/api/admin/accounts`, `/{id}` | `AccountController` | session + password current |
| GET/POST | `/api/admin/admins` | `AdminUserController@index/store` | + super admin |
| PATCH/DELETE | `/api/admin/admins/{admin}` | `AdminUserController@update/destroy` | + super admin |
| PATCH | `/api/admin/admins/{admin}/status` | `AdminUserController@status` | + super admin |
| POST | `/api/admin/admins/{admin}/reset-password`, `/reset-two-factor` | `AdminUserController` | + super admin |
| GET | `/api/admin/activity` | `ActivityLogController` | + super admin |

Lists answer Laravel's paginated resource shape (`data`, `links`, `meta`) plus `stats`
and the `filters` as applied. Filters out of range fall back to defaults rather than
failing (`App\Queries\Admin\Concerns\ReadsListFilters`); page sizes are 10, 15, 25, 50
or 100.

Console: `php artisan admin:create <email> [--name=] [--role=super_admin|admin]` (hidden
password prompt) and `php artisan admin:reset-two-factor <email> [--force]`.

---

## 4. Backend layout

| Concern | Where |
| --- | --- |
| Models | `app/Models/Admin.php`, `app/Models/AdminActivityLog.php` |
| Sign-in, TOTP, session, passwords, governance, audit | `app/Support/Admin/` (`AdminSignIn`, `AdminTwoFactor`, `AdminSession`, `AdminPasswords`, `AdminAccounts`, `AdminAudit`, `Dates`) |
| Middleware | `app/Http/Middleware/Admin/` |
| Controllers, requests, resources | `app/Http/{Controllers,Requests,Resources}/Admin/` |
| List queries | `app/Queries/Admin/` |
| Rate limits, idle sessions | `app/Providers/AdminPortalServiceProvider.php` |
| Settings | `config/admin.php` (`ADMIN_SESSION_LIFETIME`, `ADMIN_IDLE_TIMEOUT`, `ADMIN_TWO_FACTOR_ISSUER`) |
| Tests | `tests/Feature/Admin/` (guards, sign-in, admin accounts, monitoring, activity, commands) |

Resources leave secrets out: no join code, employer numbers, password hashes, TOTP
secrets or tokens (asserted in `AdminMonitoringTest`).

---

## 5. Frontend layout (`admin/`)

Vite 8, React 19, TypeScript, Tailwind v4, shadcn/ui (new-york, the ERP's own
`components/ui` copied over), Radix, lucide-react, Motion, TanStack Query, React Router,
sonner, qrcode.react, input-otp, Instrument Sans (self-hosted).

- `src/lib/` — `env.ts` (`VITE_ERP_URL`), `session.ts` (the sessionStorage store),
  `api.ts` (fetch wrapper, `ApiError`, 401 ends the session), `format.ts`, `types.ts`.
- `src/layouts/` — `RequireSession` (session + `/me`, holds an owed password at
  `/change-password`, mounts the idle warning), `RequireSuperAdmin`, `AppLayout` (the
  ERP's inset sidebar + top bar).
- `src/components/` — the shell (sidebar, header, breadcrumbs from route handles, account
  menu showing the name, no avatar), the ERP's list kit in `data-table/`, status pills,
  page states, the idle-session dialog, and `record-dialog.tsx`: every record and form
  opens in a centred dialog (compact header, a body that scrolls inside it, a pinned
  footer), with facts packed into a bordered grid (label over value) and people in a
  bordered row list.
- `src/features/<feature>/` — `api.ts` (types and query hooks) plus pages and
  components: `auth`, `overview`, `organizations`, `accounts`, `admins`, `activity`,
  `account`.
- Filters, sort, page and the open record (`?id=`) live in the URL
  (`hooks/use-list-params.ts`), so a filtered view reloads and can be shared.

See [`admin/README.md`](../../admin/README.md) for running and deploying it.

---

## 6. Extending it

A new monitoring screen: a query in `app/Queries/Admin` using `ReadsListFilters`, with the
organisation scope lifted explicitly; a resource that sends nothing secret; a route
inside the `admin.password-current` group; a test that the payload omits secrets; a
feature folder and a lazy route in `admin/src/app.tsx`; a sidebar entry in
`components/navigation.ts`. A new admin action: put the rule in `AdminAccounts`, record
it with `AdminAudit`, and add its event to `AdminActivityLog::EVENTS`.
