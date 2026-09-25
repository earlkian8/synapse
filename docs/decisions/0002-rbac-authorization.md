# 0002 — Role-based access control & authorization

- **Status:** Accepted
- **Date:** 2026-06-10
- **Supersedes:** —
- **Related:** [0001 — User identity & management](./0001-user-identity-and-management.md), [Roles & Permissions module](../modules/roles-permissions.md)

## Context

User Management and Activity Logs shipped with **no authorization** — any
authenticated, verified user could reach every action. SYNAPSE is an HR ERP with
sensitive data (payroll, performance, PII), so access must be controlled per
capability. We needed a model that is granular, easy to extend per module, and
that the frontend can reflect without becoming the source of truth.

## Decision

A lightweight, **custom RBAC layer**: `roles` grant `permissions`, users are
assigned roles. Effective permissions are the union across a user's roles.

1. **Code is the source of truth for permissions.** `App\Support\PermissionRegistry`
   declares every permission (grouped by module). Gates are defined from it at
   boot; the `permissions` table is *synced* from it for assignment & display.
   Adding a permission is a one-line code change + re-sync.

2. **Laravel's Gate is the enforcement point.**
   - `Gate::before` returns `true` for super admins (full bypass).
   - Every catalogued permission is registered as a gate ability.
   - Routes are protected with `->middleware('can:<permission>')`; multi-action
     endpoints (bulk) call `Gate::authorize()` per action.

3. **A protected `super-admin` role** bypasses all checks, cannot be edited, and
   is granted to `dev@synapse.com` at seed time. `is_system` roles cannot be
   deleted.

4. **The schema mirrors `spatie/laravel-permission`** (`roles`, `permissions`,
   `permission_role`, `role_user`) so the package can be adopted later without a
   migration — we get the package's model shape without its runtime weight now.

5. **The frontend mirrors, never owns, authorization.** `HandleInertiaRequests`
   shares `auth.permissions/roles/is_super_admin`; `usePermissions()` hides
   buttons and sidebar links. Every action is still enforced server-side.

## Alternatives considered

- **Adopt `spatie/laravel-permission` now.** Mature, but adds a dependency and
  caching/teams machinery we don't need yet. We kept its schema shape to preserve
  the option for free.
- **Permissions only in the database (no code registry).** Rejected — gates would
  need a boot-time query, permissions could drift from the code that checks them,
  and code review wouldn't show capability changes.
- **Role checks instead of permission checks** (e.g. `hasRole('admin')`).
  Rejected — couples controllers to role names and can't express fine-grained
  capability differences.

## Consequences

- **Positive:** granular, reviewable, extensible per module; frontend and backend
  share one vocabulary; migration path to spatie preserved.
- **Negative / watch-outs:**
  - Every gated `/system/*` route now `403`s a roleless account — onboarding must
    assign a role. Documented prominently.
  - `permissionNames()` is memoised per request; call `forgetCachedPermissions()`
    after changing assignments within a single request.
  - As new modules land, their permissions must be added to the registry and to
    the relevant seeded roles.
