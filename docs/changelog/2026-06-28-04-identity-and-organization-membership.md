# One identity, many organisations (membership refactor)

Refactors SYNAPSE so a **person is one identity (one login) that can belong to
several companies**, and switches the active company on web and mobile — the
standard SaaS model. Replaces the earlier same-day multi-token stop-gap. Identity
is now decoupled from tenant via a membership table; the row-level `organization_id`
tenancy and all ~20 operational modules are unchanged. See
[ADR 0023](../decisions/0023-identity-and-organization-membership.md),
[multi-tenancy module](../modules/multi-tenancy.md), and the
[mobile module](../modules/mobile-app.md).

## Highlights

- **One login for every company you work for.** A user is a global identity; the new
  `organization_user` membership table records which companies they belong to.
- **Switch companies, web and mobile.** A sidebar workspace switcher on the web posts
  to `organization.switch` (session-scoped active org); the mobile app calls
  `POST /api/auth/switch`, which mints a fresh org-bound token. Switching rescopes
  every screen to the new company.
- **Hire links, never collides.** Hiring (or admin/import creating) someone whose email
  already exists adds a membership + role + employee record to the new company instead
  of failing; only a brand-new identity is emailed credentials.
- **Isolation preserved.** The active org — from the session (web) or token (mobile) —
  is always validated against the user's memberships; nobody can bind a company they
  don't belong to.

## Backend

- **Migration** `decouple_user_identity_from_organization`: drops `users.organization_id`;
  adds `organization_user` (unique per `(organization_id, user_id)`, `is_default`); makes
  `employees.user_id` unique **per organisation**; adds `personal_access_tokens.organization_id`.
- **`User`**: drops `BelongsToOrganization`; adds `memberships()`, `isMemberOf()`,
  `defaultOrganization()`, the `inOrganization` / `inCurrentOrganization` scopes, and a
  `resolveRouteBindingQuery` guard so route-bound users are confined to the active org.
  `Organization::members()` added.
- **`SetCurrentOrganization`** resolves + validates the active org from the token (API)
  or session (web). New `OrganizationSwitchController` (web) and `Api\AuthController@switch`
  (mobile); `login`/`me` now return the active `organization` + the full `organizations`
  list and mint org-bound tokens. `OrganizationProvisioner::addMember()`.
- **Registration / hire / import**: `CreateNewUser`, `EmployeeAccountProvisioner`,
  `UserController`, and `UserImporter` create-or-link the identity and add a membership;
  role reconciliation is scoped to the active org's roles only.
- **Users module** queries (`UsersIndexQuery`, `UserStatistics`, bulk actions, restore /
  force-delete) are scoped by membership.

## Frontend

- **Web**: `components/workspace-switcher.tsx` in the sidebar header (multi-org users);
  `auth.organizations` shared via Inertia; `auth.organization` is the active tenant.
- **Mobile**: reworked to one identity / token-swap switching — `lib/auth.tsx`
  (`login`/`switchTo`/`logout`), the switcher lists the identity's `organizations`, and
  the obsolete multi-account session store and add-account modal were removed.

## Notes

- Demo: `dev@synapse.com` now belongs to **two** seeded companies (SYNAPSE Demo Co +
  SYNAPSE Labs), with a distinct employee record in each, so switching is demoable.
- Verified on Postgres: `migrate:fresh --seed`, tinker (per-org `employee`/`roles`
  resolution + isolation), and a live HTTP run (login → switch → revoked old token →
  rejected non-member switch). Web `tsc`/`pint` and mobile `tsc`/`expo lint` clean.
  Pest isolation/switch tests added but not runnable locally (no `pdo_sqlite`).
