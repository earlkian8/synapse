# The admin portal

SYNAPSE has a console for the people who run it, in `admin/`. It watches every
organisation and ERP account across tenants, manages its own admin accounts, and keeps
an append-only log of what admins did. Admins are a new identity with their own guard,
mandatory two-factor and short sessions. An ERP account, however senior, cannot open
the portal ([ADR 0085](../decisions/0085-a-platform-admin-portal.md)).

## Highlights

- **Three monitoring screens, read-only.** *Overview*: organisations (and how many are
  in setup), ERP accounts (and unverified), sign-ins in 30 days, employees and open app
  sessions; the newest organisations, the latest ERP sign-ins and, for a super admin,
  the latest admin activity. *Organisations*: status, members, employees, last activity,
  with a sheet showing size, activity, contact, HR Managers and newest members.
  *Accounts*: every sign-in identity with its status, workspaces, two-factor and last
  sign-in, with a sheet of its workspaces and roles, security and app sessions.
- **Admin accounts** (super admins): add an admin and get a one-time password shown
  once; edit; reset a password or an authenticator; deactivate, activate, and delete
  once deactivated. Nobody acts on their own account here, and the last active super
  admin cannot be removed.
- **Activity log** (super admins): every sign-in, failed password or code, sign-out,
  password change, ended session and account change, with IP and browser. Filter by
  event and admin. Nothing edits or deletes an entry.
- **Signing in shows its own steps.** The navy panel lights Password → Authenticator →
  Session as they are passed, and states the session's limits before it starts. A
  first sign-in sets up the authenticator from a QR code or a typed key.
- The layout is the ERP's: inset sidebar, top bar with breadcrumbs, stat tiles, table
  cards, sheets, the same tokens and type. The admin's menu shows their name, with no
  avatar.

## Backend (server/)

- Migration `2026_10_13_000000_create_admin_portal_tables`: `admins`,
  `admin_activity_logs`. Models `Admin`, `AdminActivityLog`; `AdminFactory`.
- Guards: `admin` (Sanctum, provider `admins`). The mobile API's `sanctum` guard is now
  pinned to `users`: before, Sanctum would have accepted any model's token there.
- `routes/admin.php`, mounted at `api/admin` on a new `admin-api` group (no session,
  no tenant, `Cache-Control: no-store`). Middleware `admin.session`,
  `admin.password-current`, `admin.super`.
- `App\Support\Admin`: `AdminSignIn` (constant-time password check, challenge in the
  cache, 5 minutes, 5 wrong codes), `AdminTwoFactor` (TOTP on Google2FA, each code
  accepted once per admin), `AdminSession` (token: 8 hours, ended after 30 minutes
  idle), `AdminPasswords`, `AdminAccounts` (the governance rules, under a row lock),
  `AdminAudit`, `Dates`.
- `AdminPortalServiceProvider`: rate limits `admin-login`, `admin-two-factor`,
  `admin-api`, and the idle check through `Sanctum::authenticateAccessTokensUsing`
  (portal tokens only).
- Controllers in `Http/Controllers/Admin`, requests in `Http/Requests/Admin`, resources
  in `Http/Resources/Admin` (no join codes, employer numbers, hashes, secrets or
  tokens), queries in `Queries/Admin` with a shared `ReadsListFilters`.
- `Organization::scopeSearch()` (name, legal name, slug, email).
- Console: `admin:create` (hidden password prompt) and `admin:reset-two-factor`.
  Scheduler: `sanctum:prune-expired --hours=24` daily at 03:15 (only tokens with an
  expiry, which mobile tokens lack).
- `config/admin.php`; `.env.example` lists `ADMIN_SESSION_LIFETIME`,
  `ADMIN_IDLE_TIMEOUT`, `ADMIN_TWO_FACTOR_ISSUER`.

## Frontend (admin/)

- Vite 8, React 19, TypeScript, Tailwind v4, shadcn/ui (the ERP's `components/ui`
  copied, adapted to localStorage for sidebar and theme), Radix, lucide-react, Motion,
  TanStack Query, React Router (routes lazy-loaded), sonner, qrcode.react, input-otp,
  Instrument Sans self-hosted. The ERP's list kit (`components/data-table`) is copied
  with its two Inertia calls replaced.
- The session token is kept in `sessionStorage` (per tab, never a cookie). Requests go
  out with `credentials: 'omit'` and `no-store`, a 401 ends the session, and nothing
  refetches in the background. An idle warning comes two minutes before the limit.
- The build injects a Content-Security-Policy meta tag (scripts from the portal,
  requests to `VITE_ERP_URL` only). `noindex`, `no-referrer`.
- `.env` / `.env.example`: `VITE_ERP_URL=http://localhost:8000` for development, the
  production address commented out under it.

## Notes

- First admin: `php artisan admin:create <email>` on the ERP. There is no seeded admin
  and no default password.
- Opening the portal in a second tab means signing in again. That comes from keeping
  the session in `sessionStorage`.
- Docs: ADR 0085; module doc `modules/admin-portal.md`; `database/admin-portal-tables.md`
  and ERD §13 (framework tables are now §14); `admin/README.md`; `deployment.md`.

## Verified

- Pest: 49 new tests in `tests/Feature/Admin` (guards and token isolation, sign-in,
  setup, replayed and wrong codes, throttling, idle and expired sessions, forced
  password change, admin governance, monitoring payloads without secrets, the log,
  console commands), run three times. Full suite: 1,979/1,979 on the local Postgres
  harness.
- Pint on every changed PHP file; `tsc -b`, ESLint, Prettier and `npm run build` in
  `admin/`.
- In Chromium (Playwright) against a scratch ERP: the first sign-in with a wrong
  password, authenticator setup, a wrong code and the right one; every screen at 1440
  px (light and dark) and 390 px; both detail sheets; adding an admin and the one-time
  password; deactivating; the log's sheet; signing out; a second tab asked to sign in.
  The only console errors were the two deliberate 422s. No sideways scroll at 390 px.
  The schema was checked against the doc through `information_schema`.
