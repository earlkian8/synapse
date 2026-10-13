# Admin Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A separate, platform-level admin portal (`admin/`) that monitors SYNAPSE across every tenant (organisations, ERP accounts), manages its own admin accounts, and keeps an append-only log of what admins did, over a hardened JSON API in the ERP (`/api/admin/*`).

**Architecture:** Admins are a new identity (`admins` table, `App\Models\Admin`), entirely separate from ERP `users`, authenticated by a dedicated Sanctum guard (`admin`, provider `admins`) with short-lived, idle-limited bearer tokens issued only after password + mandatory TOTP. Routes live in `routes/admin.php`, mounted at `api/admin` under their own `admin-api` middleware group (never the tenant-binding `api` group). The portal is a static Vite SPA, like `landing/`, that mirrors the ERP's shell (inset sidebar, top bar with breadcrumbs, stat tiles, table cards).

**Tech Stack:** Laravel 13, Sanctum 4, PragmaRX Google2FA (already vendored via Fortify), Pest 4 · React 19, TypeScript 6, Vite 8, Tailwind v4, shadcn/ui (new-york, copied from the ERP's `components/ui`), Radix, lucide-react, Motion 12, TanStack Query 5, React Router 7, sonner, qrcode.react, input-otp.

**Spec:** the user's brief (2026-10-13) — "make an admin portal… lucide, tailwind, motion, shadcn… something that is few modules to monitor only… user management (admin accounts) and activity logs… very secure… API (env: development ERP URL, production commented)… table based, layout similar to the ERP, no avatar for the admin account". No separate design doc; the decisions are recorded in ADR 0085.

## Global Constraints

- Never run mutating git; commit text goes to `docs/commit-messages/2026-10-13-03-admin-portal.txt`.
- Never migrate/seed the database `server/.env` names; tests run on `synapse_test` (see test-harness memory).
- Admin identities never touch tenant scoping: no `SetCurrentOrganization`, no `BelongsToOrganization`.
- Mandatory TOTP for every admin. Session token lifetime 480 min (`ADMIN_SESSION_LIFETIME`), idle limit 30 min (`ADMIN_IDLE_TIMEOUT`).
- Passwords: min 12, mixed case, letters, numbers, symbols (`uncompromised()` in production only, like `Password::defaults`).
- Monitoring is read-only. Responses carry no secrets (no join codes, password hashes, 2FA secrets, tokens, government numbers).
- `admin/.env`: `VITE_ERP_URL=http://localhost:8000` active; production `# VITE_ERP_URL=https://synapse-lzsdm.ondigitalocean.app` commented.
- UI copy in sentence case; no avatar for admins; tables for every list.

## Review Focus

1. An ERP user's mobile token (or an ERP web session cookie) sent to `/api/admin/*` must get 401, never an `Admin`-less pass — covered in Task 1 tests.
2. An admin token sent to the mobile API (`/api/me`) must get 401 (Sanctum's default guard accepts any tokenable) — Task 1 sets `auth.guards.sanctum.provider = users` and tests it.
3. A replayed TOTP code (same code twice inside its window) must fail the second time — Task 2.
4. Removing the last active super admin (demote, deactivate, delete) must be refused — Task 3.
5. A token idle for more than 30 minutes must stop working even though it has not reached `expires_at` — Task 2.

---

### Task 1: Admin identity, guard isolation, audit log

**Files:**
- Create: `server/database/migrations/2026_10_13_000000_create_admin_portal_tables.php` (`admins`, `admin_activity_logs`)
- Create: `server/app/Models/Admin.php`, `server/app/Models/AdminActivityLog.php`, `server/database/factories/AdminFactory.php`
- Create: `server/config/admin.php`, `server/routes/admin.php`
- Create: `server/app/Support/Admin/AdminAudit.php`
- Create: `server/app/Http/Middleware/Admin/{EnsureAdminSession,EnsureAdminPasswordIsCurrent,EnsureSuperAdmin,NoStore}.php`
- Create: `server/app/Providers/AdminPortalServiceProvider.php` (rate limiters, idle check via `Sanctum::authenticateAccessTokensUsing`)
- Modify: `server/config/auth.php` (guards `sanctum`→provider users, `admin`→sanctum/admins; provider `admins`), `server/bootstrap/app.php` (mount routes, `admin-api` group, aliases), `server/bootstrap/providers.php`
- Test: `server/tests/Feature/Admin/AdminGuardTest.php`

**Interfaces (produces):**
- `admins`: id, name, email (unique), password, role (`super_admin|admin`), is_active, must_change_password, two_factor_secret (encrypted, nullable), two_factor_confirmed_at, last_login_at, last_login_ip, password_changed_at, created_by (nullable FK admins, nullOnDelete), timestamps.
- `admin_activity_logs`: id, admin_id (nullable FK, nullOnDelete), actor_name, actor_email, event, description, subject_type, subject_id, subject_label, properties (json), ip_address, user_agent, created_at (indexed), no updated_at.
- `Admin::ROLE_SUPER_ADMIN`, `Admin::ROLE_ADMIN`, `Admin::ROLES`; `isSuperAdmin(): bool`, `hasTwoFactor(): bool`, `scopeSearch`, `static activeSuperAdminCount(): int`, `revokeSessions(?int $except = null): int`.
- `AdminActivityLog::EVENTS` (event => label); `scopeSearch`.
- `AdminAudit::record(string $event, string $description, ?Admin $actor = null, ?Model $subject = null, array $properties = [], ?string $subjectLabel = null, ?string $actorEmail = null): AdminActivityLog` — actor defaults to `auth('admin')->user()`.
- Middleware aliases: `admin.session`, `admin.password-current`, `admin.super`, `admin.no-store`.

- [ ] Write `AdminGuardTest`: an admin token reaches `GET /api/admin/me` (200); an ERP user's Sanctum token → 401 on `/api/admin/me`; an admin token → 401 on `/api/me`; a deactivated admin's token → 401 and the token is deleted; responses carry `Cache-Control: no-store`.
- [ ] Run it — fails (routes missing).
- [ ] Implement the files above; `/api/admin/me` lives in Task 2's controller, stub-free: add it here with `AdminSessionController@me`.
- [ ] Run the test — passes. `php -l`, Pint.

### Task 2: Sign-in, mandatory TOTP, session, own account

**Files:**
- Create: `server/app/Support/Admin/{AdminTwoFactor,LoginChallenge,AdminSession}.php`
- Create: `server/app/Http/Controllers/Admin/{AdminSessionController,AdminAccountController}.php`
- Create: `server/app/Http/Requests/Admin/{AdminLoginRequest,AdminTwoFactorRequest,AdminPasswordRequest}.php`, `server/app/Concerns/AdminPasswordRules.php`
- Create: `server/app/Http/Resources/Admin/AdminResource.php`
- Test: `server/tests/Feature/Admin/AdminAuthTest.php`

**Interfaces:**
- `POST /api/admin/auth/login {email,password}` → `{status: 'two_factor'|'two_factor_setup', challenge, expires_in, setup?: {secret, otpauth_url}}`; wrong email/password → 422 `errors.email` "These credentials do not match our records." (same message, Hash::check against a dummy hash when unknown); inactive (password right) → 422 "This admin account is deactivated."; throttle `admin-login` 5/min per email|ip, 20/min per ip.
- `POST /api/admin/auth/two-factor {challenge, code}` → `{token, expires_at, idle_minutes, admin: AdminResource}`; bad code → 422 `errors.code`; 5 bad codes burn the challenge; unknown/expired challenge → 422 `errors.challenge` "Your sign-in timed out. Sign in again."; throttle `admin-two-factor`.
- `GET /api/admin/me`, `POST /api/admin/auth/logout`, `PUT /api/admin/account/password {current_password,password,password_confirmation}`, `POST /api/admin/account/sessions/revoke-others`.
- `AdminTwoFactor::generateSecret(): string`, `otpauthUrl(Admin, string $secret): string`, `verify(Admin, string $secret, string $code): bool` (replay-guarded per admin via cache `admin-2fa:{id}`).
- `LoginChallenge::issue(Admin): string`, `resolve(string): ?Admin`, `fail(string): void`, `forget(string): void` (cache key = sha256 of the challenge, TTL 5 min, 5 attempts).
- `AdminSession::open(Admin, Request): array` — mints `createToken('admin-portal', ['admin'], now()+lifetime)`, stamps last_login_at/ip, audits `sign_in`.
- `AdminResource`: id, name, email, role, role_label, is_active, two_factor_enabled, must_change_password, last_login_at/_human, last_login_ip, created_at, created_by name, is_self.

- [ ] Tests: setup flow (login → `two_factor_setup` with secret → code from Google2FA → token, `two_factor_confirmed_at` set, `two_factor_enrolled` + `sign_in` logged); challenge flow for an enrolled admin; wrong password and unknown email give the identical 422 and `sign_in_failed` is logged; replayed code fails; 6th code on one challenge → `errors.challenge`; inactive admin refused; idle token (last_used_at 31 min ago) → 401; expired token → 401; logout deletes the token; `must_change_password` admin gets 403 `{reason:'password_change_required'}` on `/api/admin/overview` but can change password, after which the flag clears and other tokens are revoked; revoke-others keeps the current token.
- [ ] Implement; tests pass; Pint.

### Task 3: Admin accounts (super admin only)

**Files:**
- Create: `server/app/Http/Controllers/Admin/AdminUserController.php`, `server/app/Http/Requests/Admin/{StoreAdminRequest,UpdateAdminRequest,AdminIndexRequest}.php`, `server/app/Support/Admin/AdminAccounts.php`
- Test: `server/tests/Feature/Admin/AdminUsersTest.php`

**Interfaces:**
- `GET /api/admin/admins?search&status(all|active|inactive)&role&sort(name|email|last_login_at|created_at)&direction&per_page&page` → paginated `AdminResource` + `stats{total, active, super_admins, without_two_factor}`.
- `POST /api/admin/admins {name,email,role}` → 201 `{admin, temporary_password}` (24-char generated, must_change_password = true).
- `PATCH /api/admin/admins/{admin} {name,email,role}`; `PATCH …/{admin}/status {is_active}`; `POST …/{admin}/reset-password` → `{temporary_password}`; `POST …/{admin}/reset-two-factor`; `DELETE …/{admin}` (only when deactivated).
- `AdminAccounts` guards (throw `ValidationException`): not on oneself (role, status, reset, 2FA reset, delete), never leave zero active super admins. Every mutation revokes the target's sessions where access changes, and is audited.

- [ ] Tests: plain admin → 403 on all; create returns a temp password that signs in; self-guards; last-super-admin guard on demote and deactivate; delete refused while active; reset password and 2FA reset revoke tokens; audit rows per action; email uniqueness (case-insensitive).
- [ ] Implement; tests pass; Pint.

### Task 4: Monitoring and activity log

**Files:**
- Create: `server/app/Http/Controllers/Admin/{OverviewController,OrganizationController,AccountController,ActivityLogController}.php`
- Create: `server/app/Queries/Admin/{OrganizationsQuery,AccountsQuery}.php`, `server/app/Http/Resources/Admin/{OrganizationResource,AccountResource,AdminActivityResource}.php`, `server/app/Http/Requests/Admin/ListRequest.php`
- Test: `server/tests/Feature/Admin/AdminMonitoringTest.php`, `server/tests/Feature/Admin/AdminActivityTest.php`

**Interfaces:**
- `GET /api/admin/overview` → `{stats{organizations, organizations_this_month, in_setup, accounts, active_30d, unverified, employees, mobile_sessions}, newest_organizations[5], recent_sign_ins[5], recent_activity[6]|null (super admin only)}`.
- `GET /api/admin/organizations?search&status(all|ready|setup|archived)&sort(name|created_at|members_count|employees_count|last_activity_at)…` and `/{id}` (withTrashed) → detail adds legal_name, phone, address, timezone, departments_count, pending_join_requests, activity_30d, owners[], members[≤10 newest].
- `GET /api/admin/accounts?search&status(all|active|inactive|unverified|archived)&sort(name|email|created_at|last_login_at|memberships_count)…` and `/{id}` → detail adds memberships[{organization, joined_at, is_default, roles[]}], mobile_sessions.
- `GET /api/admin/activity?search&event&admin_id&sort(created_at|event)&direction…` (super admin) → paginated + `events` catalogue + `admins` filter list. No write endpoints (append-only).

- [ ] Tests: counts across two tenants; search/status/sort; detail payload has no `join_code`, `tin`, `password`, `two_factor_secret`; archived org reachable in detail; per_page outside the whitelist → 15; plain admin sees `recent_activity: null` and 403 on `/activity`; activity filters by event and admin.
- [ ] Implement; tests pass; Pint.

### Task 5: Console commands

**Files:** Create `server/app/Console/Commands/{CreateAdmin,ResetAdminTwoFactor}.php`; Test `server/tests/Feature/Admin/AdminCommandsTest.php`.

- `admin:create {email} {--name=} {--role=super_admin}` — prompts for the password twice (hidden), validates with `AdminPasswordRules`, audits `admin_created` with no actor.
- `admin:reset-two-factor {email}` — break-glass: clears the secret, revokes sessions, audits.
- [ ] Tests (`expectsQuestion`), implement, pass.

### Task 6: Portal scaffold, shell and sign-in

**Files (admin/):** `package.json`, `vite.config.ts` (alias `@`, Tailwind, build-time CSP plugin), `index.html`, `.env`, `.env.example`, `.prettierrc`, `.prettierignore`, `components.json`, `src/index.css` (the ERP's tokens + Instrument Sans), `src/main.tsx`, `src/app.tsx` (router), `src/components/ui/*` (copied from the ERP), `src/lib/{api,session,utils,format,env}.ts`, `src/features/auth/*`, `src/components/{app-shell,app-sidebar,app-header,breadcrumbs,theme-toggle,app-logo,session-guard}.tsx`, `public/{synapse-mark.svg,synapse-mark-reversed.svg,favicon.svg}`.

- API client: `api<T>(path, {method, body, signal})` → JSON; attaches `Authorization: Bearer`; 401 → clears session, routes to `/sign-in?reason=expired`; 403 `password_change_required` → `/change-password`; throws `ApiError {status, message, errors}`.
- Session: `sessionStorage['synapse.admin.session'] = {token, expires_at, idle_minutes}`; activity clock updated on every successful response; idle dialog 2 min before the idle limit ("Stay signed in" pings `/me`); signs out at idle or absolute expiry. Queries: `refetchOnWindowFocus: false`, no polling (background traffic would keep an unattended session alive).
- Sign-in: ERP-style split screen — navy panel whose memorable element is the sign-in sequence drawn as three steps (Password → Authenticator → Session) that light up as the person moves through them; form panel on `#F4F6FA`. Setup step shows the QR (qrcode.react) + the secret in groups of four, and a 6-digit `input-otp`.
- Shell: inset sidebar (collapsible to icons) with the mark + "SYNAPSE / Admin portal", groups Monitor (Overview, Organizations, Accounts) and Administration (Admin accounts, Activity log — super admins only); footer shows the email. Top bar: trigger, breadcrumbs, theme toggle, an account menu button showing the name (no avatar).
- [ ] `tsc -b`, ESLint, Prettier, `npm run build` green.

### Task 7: Portal pages

**Files:** `src/components/data-table/*` (the ERP's kit, router-agnostic), `src/features/{overview,organizations,accounts,admins,activity,account}/…` (types.ts, api.ts, hooks, components), `src/pages/*`.

- Every list: PageHeader, StatTiles, ListToolbar (SearchInput + FilterSelect + Reset), TableCard/DataTable with SortableHead, TablePagination; filters in the URL query string; row opens a detail Sheet.
- Admin accounts: create sheet → temporary password dialog (copy, shown once); row menu: Edit, Reset password, Reset two-factor, Deactivate/Activate, Delete (deactivated only); confirms for each destructive action; self row shows "You" and no self-destructive items.
- Activity log: table (Admin, Event badge, Description, Target, IP, When) + detail sheet with properties and user agent.
- Account: change password (with the ERP's requirement checklist), sign out other sessions, session facts (expires at, idle limit).
- [ ] tsc/ESLint/Prettier/build green; Playwright screenshots at 1440 and 390 for every page, light and dark; no console errors.

### Task 8: Docs and wrap-up

- ADR `docs/decisions/0085-a-platform-admin-portal.md`; module doc `docs/modules/admin-portal.md`; `docs/database/` tables; `admin/README.md`; `docs/README.md` links; changelog `docs/changelog/2026-10-13-03-admin-portal.md`; commit message; memory note.
- [ ] Full Pest suite on `synapse_test` (serial), Pint, and the portal's checks — report real counts.
