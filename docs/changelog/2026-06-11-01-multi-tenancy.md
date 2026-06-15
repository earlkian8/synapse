# 2026-06-11 — Multi-tenancy (one organisation per registration)

SYNAPSE becomes a multi-tenant SaaS: every registration creates an isolated
**organisation**, and all business data is scoped to it. See
[ADR 0005](../decisions/0005-multi-tenancy.md) and the
[module doc](../modules/multi-tenancy.md).

## Summary

- New `organizations` tenant (also the company profile).
- Row-level isolation via a global scope + a request-scoped current-tenant resolver.
- Registration provisions a whole tenant: organisation + built-in roles + owner.
- Per-organisation RBAC, departments, employees, numbering.
- Existing data preserved — folded into a "Default Organization".

## Backend

- Migration `…_add_multi_tenancy`: creates `organizations`; adds `organization_id`
  (FK, backfilled, NOT NULL — nullable on `activity_logs`) to users, roles, employees,
  departments, positions, work_schedules and the employee sub-records; converts
  `roles.name`, `departments.code`, `employees.employee_no` to composite per-tenant
  unique.
- Tenancy core: `Organization` model; `BelongsToOrganization` trait +
  `OrganizationScope`; `Tenancy` singleton (bound in `AppServiceProvider`);
  `SetCurrentOrganization` middleware (wired into the `web` group before Inertia).
- `BelongsToOrganization` applied to `User`, `Role`, `Employee`, `Department`,
  `Position`, `WorkSchedule`, `EmployeeDocument`, `EmployeeCertification`,
  `EmployeePromotion`, `ActivityLog` (+ `organization_id` fillable).
- `OrganizationProvisioner`: per-organisation built-in role set (Super Admin,
  Administrator, HR Manager, Staff) wired to the global permission catalogue.
- `CreateNewUser` (Fortify) rewritten — registration creates the organisation, the
  owner (Super Admin), and the role set in one transaction; `organization_name` added
  to the register form + validation.
- Seeders/factories tenancy-aware: `DatabaseSeeder` / `RolePermissionSeeder` /
  `OrganizationSeeder` resolve and bind a demo tenant; all factories resolve
  `organization_id` from the current tenant; new `OrganizationFactory`.
- `HandleInertiaRequests` shares `auth.organization`.

## Frontend

- Register page gains an **Organization name** field.
- `Auth` type gains `organization`; sidebar footer shows the organisation name.

## Tests

- `tests/Feature/Tenancy/TenancyTest.php`: directory isolation, cross-tenant access
  denial, per-tenant roles & employee numbering, registration provisioning.
- Pest helpers (`testOrganization()`, `actingAs*`) bind a tenant so factory data and
  the acting user share one organisation.

## Verification

`tsc`, ESLint, Pint clean; `npm run build` succeeds. Unit suite green (12). Migration +
seeders ran against live Postgres; two-tenant isolation, per-tenant role provisioning,
and the full registration action verified there; HTTP smoke test (login/register 200,
`/employees` → 302). (Feature suite needs `pdo_sqlite` / CI.)

## ⚠️ Migration note

Run `php artisan migrate`. Existing rows are folded into a **"Default Organization"**;
the seeded `dev@synapse.com` account and all current data stay intact. New sign-ups via
`/register` each get their own organisation. No re-seed required, but
`php artisan db:seed` remains idempotent against the demo tenant.
