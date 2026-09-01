# Multi-tenancy

SYNAPSE is a multi-tenant SaaS: every registration creates an **organisation**
(tenant), and all business data is isolated per organisation. A **user is a global
identity that can belong to several organisations** and switch the active one
([ADR 0023](../decisions/0023-identity-and-organization-membership.md)); the original
single-org design and the row-level isolation are in
[ADR 0005](../decisions/0005-multi-tenancy.md). This document explains how isolation
works and how to keep new code tenant-safe.

## The moving parts

| Piece | File | Role |
| --- | --- | --- |
| `Organization` model | `app/Models/Organization.php` | The tenant; also the company profile. |
| `Tenancy` | `app/Support/Tenancy.php` | Request-scoped singleton holding the current tenant. |
| `OrganizationScope` | `app/Models/Scopes/OrganizationScope.php` | Global scope filtering reads to the current tenant. |
| `BelongsToOrganization` | `app/Models/Concerns/BelongsToOrganization.php` | Trait: adds the scope + stamps `organization_id` on create. |
| `SetCurrentOrganization` | `app/Http/Middleware/SetCurrentOrganization.php` | Resolves + binds the active organisation per request, validated against membership. |
| `organization_user` | membership pivot | Which organisations an identity belongs to (`User::memberships()` / `Organization::members()`); `is_default` is the login landing org. |
| `OrganizationProvisioner` | `app/Support/OrganizationProvisioner.php` | Creates an organisation's built-in roles; `addMember()` records a membership. |

## How a request is scoped

1. The user authenticates (login is **not** tenant-scoped — email is globally unique
   and identifies the person, not a tenant).
2. `SetCurrentOrganization` resolves the **active organisation** — from the session
   (`active_organization_id`, web) or the token (`personal_access_tokens.organization_id`,
   mobile) — **validates it against the user's memberships**, defaults to their
   `is_default` membership, and calls `Tenancy::set()`. It never binds an organisation
   the user doesn't belong to.
3. Every query on a model using `BelongsToOrganization` is filtered to that
   organisation by `OrganizationScope`; every create is stamped with it.

Because isolation lives at the query layer, it holds **regardless of permissions** —
an organisation's super-admin still cannot see another tenant's rows. The active
organisation is shared to the front-end as `auth.organization`, with the full list as
`auth.organizations` for the **workspace switcher** in the sidebar header; switching
posts to `organization.switch` (web) or `POST /api/auth/switch` (mobile).

## Choosing a workspace after login

Because an identity can belong to several companies, sign-in lands on a **workspace
picker** rather than dropping straight into one — the "pick a project" pattern people
know from Supabase or Vercel.

- **Web:** Fortify's `home` is `/workspaces` (`WorkspaceController@index`). A user with
  a **single** membership has nothing to choose, so the controller forwards them to the
  dashboard; everyone else gets the picker (`resources/js/pages/workspaces.tsx`, a
  full-screen page with no app shell). Each card shows the company, the user's role
  there, and the headcount — computed per organisation via `Tenancy::runFor()`, since
  roles and employees are tenant-scoped. Picking a workspace posts to
  `organization.switch` (same endpoint as the sidebar switcher) and redirects to the
  dashboard. The sidebar switcher links back here as "Browse all workspaces".
- **Mobile:** a fresh multi-company login routes to `app/select-workspace.tsx` before
  the tab shell (the `hasEnteredWorkspace` gate in `lib/auth.tsx`, enforced by the root
  navigator). Single-company logins and restored sessions skip it. Picking a workspace
  calls `enterWorkspace()`, which switches the token only when a different company is
  chosen.

## Registration provisions a tenant

`CreateNewUser` (Fortify) wraps registration in a transaction:

1. Create the `Organization` (unique slug derived from the name).
2. Bind it as the current tenant.
3. Create the user as a global identity, then `OrganizationProvisioner::addMember()`
   records their (default) membership of it.
4. `OrganizationProvisioner::provisionRoles()` seeds the built-in role set
   (Super Admin, Administrator, HR Manager, Staff) **for that organisation**.
5. Attach **Super Admin** to the registrant — they own the organisation.

The register form collects an **Organization name** alongside the user's details.
`OrganizationProvisioner::create()` also mints the organisation's **join code**
(ADR 0026). Web registration is the *only* way a tenant is created: the mobile app
deliberately cannot, being the employee companion.

## Identity vs membership

A `User` is an identity (login / email / password) and is **not** tenant-scoped.
Belonging to a company is the `organization_user` membership; a person employed by
two companies is one identity with two memberships (and one `Employee` record per
organisation — `employees.user_id` is unique per org).

**Nobody creates that identity on the person's behalf**
([ADR 0026](../decisions/0026-self-served-identity-and-workspace-join.md)). People
register themselves in the mobile app, and *joining* a company is a separate act:
either an `employee_invitations` claim ticket HR issued against a roster line, or the
organisation's `join_code`. Both converge on `OrganizationProvisioner::admit()`, the
single path that records membership, grants the baseline role, and links the employee.
**"Belongs to no organisation" is therefore a valid state** — API sessions return
`organization: null` rather than erroring. The two support classes
(`EmployeeInvitations`, `WorkspaceJoin`) are the only code in the system that reads
past `OrganizationScope` with `withoutGlobalScopes()`, because a code is issued inside
a tenant and answered from outside it; don't copy that escape elsewhere.

Because users are global, admin queries over users must scope by membership —
use `User::inCurrentOrganization()` (the model also guards route-model binding to the
active org via `resolveRouteBindingQuery`). Roles stay organisation-scoped, so
`$user->roles` / `$user->employee` resolve to the active organisation automatically.

## RBAC, scoped

Permissions are **global** and code-defined (`PermissionRegistry`) — the catalogue of
abilities is the same everywhere. **Roles** and their grants are **per-organisation**:
each tenant has its own `super-admin`, `hr-manager`, etc. Role machine-names are unique
*within* an organisation (`(organization_id, name)`), so two tenants can both have a
`super-admin` role.

## Writing tenant-safe code

- **New table owned by a tenant?** Add a non-null `organization_id` FK, and use
  `BelongsToOrganization` on the model. That is all that is needed for isolation.
- **Querying inside a request?** Do nothing special — the scope is automatic. To reach
  across tenants deliberately, use `Model::withoutGlobalScopes()`.
- **Running outside a request** (job, command, seeder)? No tenant is bound, so set one:
  `app(Tenancy::class)->set($org)` or `Tenancy::runFor($org, fn () => …)`. Otherwise
  queries span every tenant. Add memberships with `OrganizationProvisioner::addMember()`.
- **Uniqueness** that used to be global is now composite — scope unique rules to the
  organisation.
- **Querying users (the one non-tenant-scoped model)?** Scope by membership
  (`User::inCurrentOrganization()`); never rely on a global scope. When assigning roles
  to a user, reconcile only within the active org's roles (a plain `sync()` would wipe
  their roles in other organisations).

## Tests

The `testOrganization()` Pest helper creates and binds a tenant on first use;
`actingAsSuperAdmin()` / `actingAsUserWith()` build the acting user inside it (the
`UserFactory` makes each identity a member of the bound tenant), so factory data and
the user share one organisation. `tests/Feature/Tenancy/TenancyTest.php` covers
directory isolation, cross-tenant access denial, per-tenant roles and employee
numbering, and registration provisioning;
`tests/Feature/Tenancy/MultiOrganizationTest.php` covers switching, membership-gated
switching, and cross-org hire linking.

## Boundaries (for now)

- **One identity, many organisations** via `organization_user` (ADR 0023). The same
  email cannot exist twice — it *is* the identity.
- **No platform-operator console** — there is no super-admin across tenants.
- `activity_logs.organization_id` is nullable (system events may be tenant-less).
