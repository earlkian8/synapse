# 0005 — Multi-tenancy: one organisation per registration

- **Status:** Accepted
- **Date:** 2026-06-11
- **Supersedes:** Design Decision #1 of the [ERD](../database/erd.md) (single-tenant)
- **Related:** [Multi-tenancy module](../modules/multi-tenancy.md), [0001 — User identity](./0001-user-identity-and-management.md), [0002 — RBAC](./0002-rbac-authorization.md), [0004 — Employee ↔ User](./0004-employee-user-separation.md)

## Context

SYNAPSE began as a single-tenant ERP: one company per installation, no public
sign-up, accounts provisioned from within. The ERD flagged single-tenant as the one
decision that is *expensive to retrofit* (Design Decision #1) and called it out as
Open Question #2.

We now want SYNAPSE to be **generic**: anyone can register, get their own
organisation, and manage their own isolated data — a multi-tenant SaaS. Making this
change while only a handful of modules exist (System, RBAC, Notifications, Employees)
is far cheaper than after the operational modules (Attendance, Leave, Payroll, …)
are built, because every one of those would otherwise be written single-tenant and
retrofitted twice.

## Decision

Adopt **single-database, row-level multi-tenancy**, keyed by an `organizations`
table. One organisation is created per registration.

- **Tenant = `organizations` row.** It also *is* the company profile — there is no
  separate `company_profiles` singleton. Each business table carries a non-null
  `organization_id` FK.
- **Isolation at the query layer.** Tenant-owned models use the
  `BelongsToOrganization` trait, which adds an `OrganizationScope` global scope
  (every read is filtered to the current tenant) and a `creating` hook (every write
  is stamped with it). Because isolation lives in the query, it holds **regardless of
  permissions** — an organisation's super-admin can never reach another tenant's rows.
- **Tenant resolution is login-based.** The `SetCurrentOrganization` middleware binds
  the authenticated user's organisation into a request-scoped `Tenancy` singleton; the
  scope and trait read from it. No subdomains required (they can be layered on later
  without a data-model change). Outside a request (login, registration, console) no
  tenant is bound and scoping is a deliberate no-op.
- **One user belongs to one organisation.** `users.organization_id` is non-null;
  `users.email` stays globally unique, so login resolves a user → their organisation
  with no tenant hint. A person in two organisations would need two accounts — an
  accepted limitation for now. **(Superseded by
  [ADR 0023](./0023-identity-and-organization-membership.md): identity is now decoupled
  from tenant via an `organization_user` membership table; one user can belong to many
  organisations and switch the active one. The rest of this ADR — row-level
  `organization_id` isolation, the scope, the trait — still stands.)**
- **RBAC is per-organisation.** *Permissions* remain global and code-defined
  (`PermissionRegistry`); *roles* and their grants are scoped to the organisation.
  Registration provisions a fresh built-in role set (Super Admin, Administrator,
  HR Manager, Staff) via `OrganizationProvisioner` and makes the registrant the
  organisation's Super Admin.
- **Per-tenant uniqueness.** What was globally unique becomes composite:
  `(organization_id, name)` for roles, `(organization_id, code)` for departments,
  `(organization_id, employee_no)` for employees.

The platform-operator view (one super-admin who sees *all* tenants) is intentionally
**out of scope** for this change.

## Alternatives considered

- **Database-per-tenant / schema-per-tenant** (e.g. `stancl/tenancy`). Stronger
  isolation, but a heavy operational burden (migrating many databases, switching
  connections) that the project does not need. Rejected.
- **Stay single-tenant.** Simplest, but forecloses the generic SaaS goal and would
  force the expensive retrofit later. Rejected.
- **Users in many organisations (M:N).** A real feature, but it complicates login and
  every query for a need we do not have yet. Deferred.

## Consequences

- **Positive:** clean tenant isolation enforced once, centrally; new modules inherit
  it by using one trait; registration is self-service; the change landed additively,
  preserving existing data (folded into a "Default Organization").
- **Negative / watch-outs:**
  - **Every** tenant-owned model must use `BelongsToOrganization`; forgetting it on a
    new table silently disables isolation for that table. New-module checklists and
    the [module doc](../modules/multi-tenancy.md) call this out.
  - Code that runs **outside a request** (jobs, commands, seeders) must set the tenant
    explicitly (`Tenancy::set()` / `runFor()`), or queries span all tenants.
  - `activity_logs.organization_id` is **nullable** (system events may have no tenant);
    those rows are invisible to per-tenant views by design.
  - Tests must bind a tenant in setup so factory data and the acting user share one
    organisation (handled by the `testOrganization()` Pest helper).
