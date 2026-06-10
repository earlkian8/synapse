# 2026-06-10 — Employees module + organisation foundation

The HR hub: a full ERP-grade Employee module, plus the organisation lookups
(departments, positions, work schedules) it builds on.

## Summary

- New **Employees** module at `/employees` (stats, filtered directory, sectioned
  create/edit drawer, tabbed profile drawer, bulk actions, CSV export).
- **201 file**: managed documents & certifications per employee.
- **Career history** auto-recorded on position/salary change.
- **Employee ↔ User** kept separate (nullable, unique link) — see ADR 0004.
- Foundation lookups seeded: departments, positions, work schedules.

## Backend

- Migrations: `…_create_organization_tables` (departments, positions,
  work_schedules) and `…_create_employees_tables` (employees + documents,
  certifications, promotions; deferred `departments.head_id` FK).
- Models: `Employee` (relations, `full_name`/`initials`, `search` scope),
  `Department`, `Position`, `WorkSchedule`, `EmployeeDocument`,
  `EmployeeCertification`, `EmployeePromotion`; `User` gains `employee()`.
- Controllers: `EmployeeController` (index/show/store/update/destroy/restore/
  forceDelete), `EmployeeStatusController`, `EmployeeBulkActionController`
  (archive/restore/delete/set-status, per-action gate), `EmployeeExportController`,
  `EmployeeDocumentController`, `EmployeeCertificationController`.
- Requests: `StoreEmployeeRequest`, `UpdateEmployeeRequest` (self-manager guard,
  unique user link), `BulkEmployeeActionRequest`. Resources for the employee and
  each sub-record. `EmployeesIndexQuery` + `EmployeeStatistics`.
- `employee_no` auto-generates (`EMP-NNNNN`); promotions auto-recorded on
  position/salary change; mutations logged (`logName: 'employees'`).
- `PermissionRegistry` gains an **Employee Management** group (8 permissions),
  seeded to Super Admin / Administrator / HR Manager.
- `routes/employees.php` wired into `web.php`; factories + `OrganizationSeeder`
  (wired into `DatabaseSeeder`).

## Frontend

- `features/employees/` — types, routes, constants, filter hook, and components
  (stats, toolbar with department/status/type filters, table, row actions, status
  badge, avatar, bulk bar, pagination, **sectioned form sheet**, **tabbed detail
  sheet** with documents/certifications/history, confirm dialog).
- `pages/employees/index.tsx` — orchestration with permission gating + selection.
- Detail drawer lazy-loads the full record (`GET /employees/{id}` JSON); uploads
  post `FormData` and re-fetch.
- Sidebar Workforce → Employees gated on `employees.view`.

## Tests

- `tests/Feature/Employee/EmployeeTest.php` — listing/filters, create + auto
  number + validation, promotion-on-update, self-manager guard, archive/restore/
  force-delete, quick status, export, bulk, documents & certifications, the
  authorization matrix, and the unique user link.
- `tests/Unit/EmployeeModelTest.php` — accessors (DB-free).

## Verification

`tsc`, ESLint, Prettier, Pint clean; `npm run build` succeeds (`employees` chunk
67 kB). Unit suite green (12). Migrations + seeders ran against live Postgres
(5 departments, 14 positions, 2 schedules, 25 employees); index query, resources,
stats and gates verified there. (Feature suite needs `pdo_sqlite` / CI.)

## ⚠️ Migration note

Run `php artisan migrate` and (optionally) `php artisan db:seed`. The new
`/employees` route is gated by `employees.view` — re-seed roles
(`php artisan db:seed --class=RolePermissionSeeder`) so existing roles pick up the
Employee Management permissions.
