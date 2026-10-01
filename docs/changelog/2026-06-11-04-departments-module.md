# 2026-06-11 — Departments module (Company Setup: org structure)

The first **Company Setup** surface, at `/setup/departments`: manage the org structure —
departments as a hierarchy, their heads, and the positions under each. See
[ADR 0008](../decisions/0008-company-setup-org-structure.md) and the
[module doc](../modules/departments.md).

## Summary

- **Department tree** — an expandable hierarchy (`parent_id`), each node showing code,
  head and employee/position counts; search and an archived section.
- **Department CRUD** — name, code, parent, head (an employee), description; archive /
  restore / permanent delete.
- **Positions** managed inline in the department drawer (title + salary band).
- **Fixes a multi-tenancy bug**: `departments.code` was globally unique; it is now
  unique **per tenant** (a partial index ignoring archived rows).

## Backend

- Migration `…_make_department_code_unique_per_tenant`: drops the global unique on
  `departments.code`, adds a partial unique `(organization_id, code) WHERE deleted_at IS
  NULL`.
- `Department` gains `HasHashid`, a `search` scope, a `children` relation and a
  `subtreeIds()` helper (cycle-safe re-parenting).
- Controllers `Setup\DepartmentController` (index / show / store / update / destroy=archive
  / restore / forceDelete) and `Setup\PositionController` (store / update / destroy).
- Requests `DepartmentRequest` (per-tenant unique code + cycle-safe parent) and
  `PositionRequest` (max ≥ min); resources `DepartmentResource` / `PositionResource`;
  `DepartmentStatistics`.
- `routes/setup.php` (prefix `setup`, name `setup.*`) wired into web.php; departments by
  hashid, positions by id. Mutations activity-logged (`logName: 'company-setup'`).
- **Company Setup** permission group (`setup.departments.view`, `setup.departments.manage`)
  granted to Super Admin / Administrator / HR Manager. `OrganizationSeeder` now seeds a
  small hierarchy (two sub-departments) and a head per top-level department.

## Frontend

- `features/departments/` — types, routes, and components (stats, toolbar, **tree** +
  recursive **node**, **department form sheet** with a cycle-aware parent picker,
  **detail sheet** with inline positions, **position form sheet**, confirm dialog).
- Page `pages/setup/departments.tsx`; sidebar **Company Setup → Departments** gated on
  `setup.departments.view` (the Company Setup group is now permission-filtered).

## Tests

- `tests/Feature/Setup/DepartmentTest.php` — index/show render, create (code upper-cased)
  + validation, per-tenant duplicate-code rejection + cross-org reuse, update, cycle
  guard, head assignment, archive/restore/force-delete + restore code-clash guard,
  positions CRUD + salary-band validation, authorization matrix, tenant isolation.

## Verification

`tsc`, ESLint, Pint clean; `npm run build` succeeds (`departments` chunk ~36 kB).
Migration ran against live Postgres; the index/show controllers resolve (200), the tree
loads with the seeded hierarchy (7 departments, 2 nested, 14 positions), and every
mutation path was verified there — create (code upper-cased), per-tenant duplicate
rejection, cross-tenant code reuse, the cycle guard, position add, and
archive → restore → force-delete. (Feature suite needs `pdo_sqlite` / CI.)

## ⚠️ Migration note

Run `php artisan migrate` and re-seed roles
(`php artisan db:seed --class=RolePermissionSeeder`) so existing roles pick up the
**Company Setup** permissions. `php artisan db:seed` is idempotent and adds the demo
hierarchy + heads.
