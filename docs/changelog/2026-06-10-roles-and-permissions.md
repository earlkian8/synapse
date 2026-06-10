# 2026-06-10 — Roles & Permissions + system-wide authorization

A new Roles & Permissions module, plus a custom RBAC layer that now gates User
Management and Activity Logs (routes, controllers, and UI).

## Summary

- New **Roles & Permissions** module at `/system/roles` (stats, filtered table,
  grouped permission matrix, create/edit/detail drawers, delete, CSV export).
- A code-defined **permission catalogue** + Laravel **Gate** integration.
- **User Management** and **Activity Logs** are now permission-gated end to end.
- `dev@staffa.com` is granted the **Super Admin** role.

## Backend

- Migration `…_create_roles_and_permissions_tables` — `roles`, `permissions`,
  `permission_role`, `role_user`.
- Models `Role` (`SUPER_ADMIN`, `permissions()`, `users()`, `isSuperAdmin()`,
  `scopeSearch()`) and `Permission`; `User` gains `roles()`, `isSuperAdmin()`,
  `hasRole()`, `hasPermissionTo()`, `permissionNames()` (memoised),
  `forgetCachedPermissions()`.
- `App\Support\PermissionRegistry` (the catalogue / source of truth) and
  `PermissionSyncer` (registry → table).
- `RoleController` (index/store/update/destroy), `RoleExportController`,
  `StoreRoleRequest` / `UpdateRoleRequest`, `RoleResource`, `RolesIndexQuery`,
  `RoleStatistics`. Mutations log to Activity Logs (`logName: 'roles'`).
- `AppServiceProvider::configureAuthorization()` — `Gate::before` super-admin
  bypass + a gate per catalogued permission.
- `routes/system.php` — `system.roles.*` group; **`can:` middleware added to every
  users / activity-logs / roles route**. Bulk user actions authorise per action.
- `HandleInertiaRequests` shares `auth.roles`, `auth.permissions`,
  `auth.is_super_admin`.
- `UserController` accepts a role picker (`manage_roles` + `roles[]`), syncing only
  when the actor holds `roles.assign`; `UserResource` and `UsersIndexQuery` now
  include assigned roles; the index passes a `can` map + assignable roles.
- `RolePermissionSeeder` seeds the catalogue, four built-in roles, and the dev
  Super Admin grant; wired into `DatabaseSeeder`.

## Frontend

- `features/roles/` — types, routes, constants, filter hook, and components
  (stats, toolbar, table, row actions, role badge, **permission matrix**,
  form/detail sheets, pagination, confirm dialog).
- `pages/system/roles/index.tsx` — orchestration page with permission gating.
- `hooks/use-permissions.ts` — shared `can()` / `hasRole()` / `isSuperAdmin()`.
- User Management UI now hides actions the user lacks permission for, shows a
  **Roles** column and detail group, and a role picker in the create/edit form.
- Activity Logs UI hides delete/clear/export/selection without permission.
- The sidebar hides System links the user cannot access.

## Tests

- `tests/Feature/RolePermission/RolePermissionTest.php` — role CRUD, slug
  derivation, validation, system/super-admin guards, export, and the
  authorization matrix (deny/allow, super-admin bypass, role assignment).
- `tests/Unit/PermissionRegistryTest.php` — catalogue integrity (runs locally).
- `tests/Pest.php` — shared RBAC helpers (`actingAsSuperAdmin`,
  `actingAsUserWith`, `makeRole`, `seedPermissions`); existing User/Activity
  feature suites updated to authenticate as Super Admin.

## Verification

`tsc`, ESLint and Pint clean; `npm run build` succeeds (33.8 kB `roles` chunk).
Unit suite green (9 tests). Gates, queries, resources, stats, and the seeded
Super Admin grant confirmed against live Postgres via bootstrap scripts. (Feature
suite needs `pdo_sqlite` / CI locally.)

## ⚠️ Migration note

Every `/system/*` route is now permission-gated, so an authenticated account with
**no role** will get `403`. Run `php artisan migrate` and
`php artisan db:seed --class=RolePermissionSeeder`, then ensure each active
account holds a role (Super Admin can assign roles from the User Management form).
