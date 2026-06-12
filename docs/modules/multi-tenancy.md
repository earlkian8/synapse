# Multi-tenancy

NEXO is a multi-tenant SaaS: every registration creates an **organisation**
(tenant), and all business data is isolated per organisation. This document explains
how isolation works and how to keep new code tenant-safe. The *why* is in
[ADR 0005](../decisions/0005-multi-tenancy.md).

## The moving parts

| Piece | File | Role |
| --- | --- | --- |
| `Organization` model | `app/Models/Organization.php` | The tenant; also the company profile. |
| `Tenancy` | `app/Support/Tenancy.php` | Request-scoped singleton holding the current tenant. |
| `OrganizationScope` | `app/Models/Scopes/OrganizationScope.php` | Global scope filtering reads to the current tenant. |
| `BelongsToOrganization` | `app/Models/Concerns/BelongsToOrganization.php` | Trait: adds the scope + stamps `organization_id` on create. |
| `SetCurrentOrganization` | `app/Http/Middleware/SetCurrentOrganization.php` | Binds the auth user's organisation per request. |
| `OrganizationProvisioner` | `app/Support/OrganizationProvisioner.php` | Creates an organisation's built-in roles. |

## How a request is scoped

1. The user authenticates (login is **not** tenant-scoped — email is globally unique).
2. `SetCurrentOrganization` runs in the `web` group, reads `auth()->user()->organization`,
   and calls `Tenancy::set()`.
3. Every query on a model using `BelongsToOrganization` is filtered to that
   organisation by `OrganizationScope`; every create is stamped with it.

Because isolation lives at the query layer, it holds **regardless of permissions** —
an organisation's super-admin still cannot see another tenant's rows. The current
organisation is shared to the front-end as `auth.organization` (see
`HandleInertiaRequests`) and shown in the sidebar footer.

## Registration provisions a tenant

`CreateNewUser` (Fortify) wraps registration in a transaction:

1. Create the `Organization` (unique slug derived from the name).
2. Bind it as the current tenant.
3. Create the user as a member of it, marked active.
4. `OrganizationProvisioner::provisionRoles()` seeds the built-in role set
   (Super Admin, Administrator, HR Manager, Staff) **for that organisation**.
5. Attach **Super Admin** to the registrant — they own the organisation.

The register form collects an **Organization name** alongside the user's details.

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
  queries span every tenant.
- **Uniqueness** that used to be global is now composite — scope unique rules to the
  organisation.

## Tests

The `testOrganization()` Pest helper creates and binds a tenant on first use;
`actingAsSuperAdmin()` / `actingAsUserWith()` build the acting user inside it, so
factory data and the user share one organisation. `tests/Feature/Tenancy/TenancyTest.php`
covers directory isolation, cross-tenant access denial, per-tenant roles and
employee numbering, and registration provisioning.

## Boundaries (for now)

- **One user, one organisation.** No multi-org membership yet.
- **No platform-operator console** — there is no super-admin across tenants.
- `activity_logs.organization_id` is nullable (system events may be tenant-less).
