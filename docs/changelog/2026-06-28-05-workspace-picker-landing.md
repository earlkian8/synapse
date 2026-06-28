# Workspace picker landing (web + mobile)

Sign-in now lands on a **workspace picker** — the "pick a company" screen people know
from Supabase or Vercel — instead of dropping straight into one company. Builds on the
one-identity-many-companies model ([ADR 0023](../decisions/0023-identity-and-organization-membership.md))
without any schema change: it reuses the existing membership data and the
`organization.switch` / `POST /api/auth/switch` endpoints. People with a single
membership never see it. See the [multi-tenancy module](../modules/multi-tenancy.md).

## Highlights

- **Choose where you work, every sign-in.** Users in more than one company get a
  full-screen picker listing each workspace with its logo, the user's role there, and
  the headcount. Choosing one binds the session/token to that company and opens it.
- **No detour for single-company users.** A lone membership forwards straight to the
  dashboard (web) or the tab shell (mobile); restored mobile sessions skip it too.
- **Reachable anytime.** The web sidebar switcher gains a "Browse all workspaces" link
  back to the picker; the mobile profile keeps its existing switch sheet.
- **Designed for the brand.** SYNAPSE's deep-navy field with a faint neural node
  backdrop and teal accents; companies render as rounded squares (people stay circles).
  Motion respects `prefers-reduced-motion`.

## Backend (web)

- **`WorkspaceController@index`** (`GET /workspaces`, `auth`+`verified`): lists the
  user's memberships; forwards to `dashboard` when there is only one, otherwise renders
  the `workspaces` page. Per-card role and headcount are computed with the organisation
  bound via `Tenancy::runFor()` (roles and employees are tenant-scoped).
- **Fortify `home`** changed from `/dashboard` to `/workspaces`, so login, registration,
  and email-verification all land on the picker (which then self-forwards as needed).
- Selection reuses `OrganizationSwitchController` (`organization.switch`) — no new
  write path.

## Frontend (web)

- **`resources/js/pages/workspaces.tsx`** — the picker, rendered with no app shell
  (added a `name === 'workspaces' → null` case in `app.tsx`'s layout resolver). Real
  `<button>` cards with focus-visible rings, an in-card "Entering…" state, and a search
  field once there are more than six workspaces.
- **`workspace-switcher.tsx`** — adds a "Browse all workspaces" item (shown only with
  more than one company).

## Mobile

- **`lib/auth.tsx`** — adds a `hasEnteredWorkspace` gate (off after a fresh multi-company
  login; on for single-company logins and restored sessions) and `enterWorkspace()`,
  which only mints a new token when a *different* company is chosen.
- **`app/_layout.tsx`** — the root navigator routes a not-yet-chosen multi-company
  session to `select-workspace` before the tab shell.
- **`app/select-workspace.tsx`** — the picker screen, reusing the `CompanyLogo` mark and
  the navy/teal sign-in aesthetic.

## Notes

- No migrations; no ERD change. Verified: Pint, web `tsc`/ESLint/Prettier/`npm run build`,
  mobile `tsc`/ESLint, and an Expo typed-routes regen for the new screen. Backend query
  mechanics smoke-tested in Tinker against the seeded two-membership `dev@synapse.com`.
  The Pest suite still can't run locally (no `pdo_sqlite`).
