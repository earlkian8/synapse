# 0023 — Identity vs employment: one user, many organisations

- **Status:** Accepted
- **Date:** 2026-06-28
- **Supersedes:** the "one user ⇒ one organisation" decision of
  [0005 — Multi-tenancy](./0005-multi-tenancy.md), and replaces
  [0022 — Mobile multi-workspace sessions](./0022-mobile-multi-workspace-sessions.md)
- **Related:** [0001 — User identity](./0001-user-identity-and-management.md),
  [0002 — RBAC](./0002-rbac-authorization.md),
  [0004 — Employee ↔ User](./0004-employee-user-separation.md),
  [0010 — Attendance & mobile API](./0010-attendance-and-mobile-api.md),
  [Multi-tenancy module](../modules/multi-tenancy.md),
  [Mobile app module](../modules/mobile-app.md)

## Context

[ADR 0005](./0005-multi-tenancy.md) tied **identity to tenant**: `users.organization_id`
was a single non-null FK, `users.email` was globally unique, and the tenancy core
resolved the current tenant *from the logged-in user's one organisation*. A person
employed by two companies therefore needed two separate accounts with two different
emails — and the hire bridge couldn't even reuse an email across tenants. ADR 0005
named users-in-many-organisations (M:N) a real feature, deliberately deferred.

[ADR 0022](./0022-mobile-multi-workspace-sessions.md) worked around this on the phone
by letting the app juggle several independent accounts. That solved the *symptom*
(switching on mobile) but not the *cause*: it was still two identities for one person,
two emails, no shared identity, and nothing for the web. We now adopt the standard
SaaS model properly.

## Decision

**A `User` is a global identity (login / email / password). Belonging to a company is
a separate membership.** Operational data is unchanged — `Employee` already *is* the
per-organisation employment record (it carries `user_id` + `organization_id` and all
DTR / leave / performance data FKs to it), and row-level `organization_id` tenancy
still isolates every table. We changed only the identity/auth layer.

- **Membership table.** New `organization_user` pivot (`organization_id`, `user_id`,
  `is_default`, `joined_at`, unique per pair) is the source of truth for "may this
  identity act in this organisation." `User::memberships()` / `Organization::members()`.
  `users.organization_id` is dropped; `users.email` stays globally unique as the
  identity key.
- **Active organisation is per-request, never global.** `Tenancy`, `OrganizationScope`
  and `BelongsToOrganization` are untouched; only their *input* changed.
  `SetCurrentOrganization` now resolves the active org from the **session**
  (`active_organization_id`, web) or the **token**
  (`personal_access_tokens.organization_id`, mobile) and **always validates it against
  the user's memberships** — so no one can ever bind an organisation they don't belong
  to. It defaults to the user's `is_default` membership.
- **RBAC needs no new machinery.** Roles are already organisation-scoped (ADR 0005), so
  `$user->roles` and `$user->employee` automatically resolve to the *active*
  organisation once it is bound. Role assignment is reconciled **within the active
  org's roles only**, so a multi-org user never loses roles elsewhere.
- **Hire links, never collides.** `EmployeeAccountProvisioner` resolves the identity by
  email: it reuses an existing account (adding a membership + the org's `staff` role +
  an `Employee` row) or creates a new one. The credential email is sent only for a
  brand-new identity. `employees.user_id` is now unique **per organisation**, so one
  identity has one employee per company.
- **Switching.** Web posts to `organization.switch` (session); mobile calls
  `POST /api/auth/switch`, which mints a fresh token bound to the chosen org and
  revokes the old one. Both surfaces ship a switcher (companies render as squares,
  people as circles; active ringed in teal).

## Alternatives considered

- **Keep the two-accounts model + document the limitation** (ADR 0005/0022). Works, but
  it's two identities per person and never coheres on the web. Rejected in favour of the
  correct model now that the project warranted it.
- **Re-key operational data under a new `Employment` entity.** Unnecessary and dangerous:
  `Employee` already is that entity, and re-keying ~20 modules would risk the tenant
  isolation that row-level `organization_id` already guarantees. Rejected.
- **Database/schema-per-tenant.** Same heavy operational burden ADR 0005 rejected, and
  orthogonal to the identity question. Rejected.

## Consequences

- **Positive:** one person, one login, many companies — switch on web or mobile; the
  hire bridge links an existing email instead of failing; isolation is unchanged
  (membership is validated on every bind); operational modules needed no changes.
- **Negative / watch-outs:**
  - `User` is no longer tenant-scoped, so **every** admin query over users must scope by
    membership (`User::inCurrentOrganization()` / the `resolveRouteBindingQuery` guard).
    Missing one silently widens visibility — covered by the Users module audit and tests.
  - `role_user` is global while roles are org-scoped, so role edits must reconcile only
    within the active org (a plain `sync()` would wipe other orgs' roles).
  - Code outside a request (jobs, seeders) still binds a tenant explicitly via
    `Tenancy::set()` / `runFor()` and adds memberships via `OrganizationProvisioner::addMember()`.
