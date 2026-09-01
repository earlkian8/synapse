# Module: Roles & Permissions

> Status: **Active** · Route prefix: `/system/roles` · Sidebar: System → Roles & Permissions

The access-control centre of SYNAPSE. Defines **roles**, the **permissions** each
role grants, and (via User Management) who holds them. Built to mirror the
[User Management](./user-management.md) design — stats cards, server-side table,
slide-over create/edit and detail — with a grouped **permission matrix** as its
centrepiece.

This module also introduces the system-wide **authorization layer** that now
gates User Management and Activity Logs (see §7).

---

## 1. Feature overview

| Area | Capabilities |
| --- | --- |
| **Listing** | Server-side search, type filter (system / custom), sorting, pagination (10–100 / page). |
| **Stats** | Total roles, system, custom, total permissions, assigned users, users with no role. |
| **Create / Edit** | Slide-over with a grouped, interactive permission matrix and live count; the machine key is auto-derived from the label and is immutable after creation. |
| **Detail** | Read-only slide-over showing every permission grouped, granted/denied, plus member & permission counts. |
| **Per-row actions** | View permissions, edit, delete. |
| **Bulk actions** | Row checkboxes with a contextual action bar; **bulk delete** of the selected custom roles (built-in roles are skipped server-side). |
| **Guards** | The Super Admin role cannot be edited; built-in **system** roles cannot be deleted (single or bulk). |
| **Export** | CSV download honouring the current filters. |

---

## 2. The permission catalogue (code is the source of truth)

`App\Support\PermissionRegistry` is the **single source of truth** for which
permissions exist — not the database. It declares permissions grouped by module:

```php
PermissionRegistry::GROUPS = [
    'User Management'      => ['users.view' => 'View users', ...],
    'Roles & Permissions' => ['roles.view' => 'View roles', ...],
    'Activity Logs'       => ['activity-logs.view' => 'View activity logs', ...],
    'Notifications'       => ['notifications.send' => 'Send & broadcast notifications'],
];
```

- `PermissionRegistry::names()` — flat list, used to **define the gates** and to
  validate incoming permission selections.
- `PermissionRegistry::groups()` — nested name/label pairs, sent to the frontend
  to render the matrix.
- `App\Support\PermissionSyncer::sync()` — reconciles the `permissions` table with
  the registry (creates new, refreshes labels/groups, prunes removed). Run by the
  seeder; safe to re-run.

**To add a permission to a module:** add it to the registry group, re-run the
seeder (or `PermissionSyncer::sync()`), and attach it to the relevant roles.

---

## 3. Routes

Registered in [`routes/system.php`](../../server/routes/system.php) under
`['auth', 'verified']`, name prefix `system.roles.*`. Each route is gated by a
permission via the `can:` middleware.

| Method | URI | Name | Controller | Permission |
| --- | --- | --- | --- | --- |
| GET | `/system/roles` | `index` | `RoleController@index` | `roles.view` |
| GET | `/system/roles/export` | `export` | `RoleExportController` | `roles.view` |
| POST | `/system/roles/bulk` | `bulk` | `RoleBulkActionController` | `roles.view` + per-action `Gate::authorize` |
| POST | `/system/roles` | `store` | `RoleController@store` | `roles.create` |
| PATCH | `/system/roles/{role}` | `update` | `RoleController@update` | `roles.update` |
| DELETE | `/system/roles/{role}` | `destroy` | `RoleController@destroy` | `roles.delete` |

The bulk endpoint mirrors User Management: `roles.view` lets the row checkboxes
load, but each action re-authorises its own permission inside the controller
(`delete` → `roles.delete`), so the action bar can't be used to escalate.

---

## 4. Data model

Four tables (`2026_06_10_200000_create_roles_and_permissions_tables`), detailed in
[the schema reference](../database/roles-permissions-tables.md):

- `roles` — `name` (unique slug), `label`, `description`, `is_system`.
- `permissions` — `name` (unique), `label`, `group`.
- `permission_role` — pivot (composite PK).
- `role_user` — pivot (composite PK).

The shape mirrors `spatie/laravel-permission` so the package can be adopted later
without a schema rewrite (see [ADR 0002](../decisions/0002-rbac-authorization.md)).

Models: `App\Models\Role` (`SUPER_ADMIN` const, `permissions()`, `users()`,
`isSuperAdmin()`, `scopeSearch()`), `App\Models\Permission`, and additions on
`App\Models\User`: `roles()`, `isSuperAdmin()`, `hasRole()`, `hasPermissionTo()`,
`permissionNames()` (memoised), `forgetCachedPermissions()`.

---

## 5. Backend architecture

```
app/
├── Http/Controllers/RolePermission/
│   ├── RoleController.php            # index, store, update, destroy
│   ├── RoleBulkActionController.php  # invokable: bulk delete (per-action gate)
│   └── RoleExportController.php      # invokable: streamed CSV
├── Http/Requests/RolePermission/
│   ├── StoreRoleRequest.php          # slug rule, unique, permission whitelist
│   └── UpdateRoleRequest.php         # name immutable; label/desc/permissions
├── Http/Resources/RoleResource.php
├── Queries/
│   ├── RolesIndexQuery.php           # filter (type) + sort + paginate + counts
│   └── RoleStatistics.php
├── Models/{Role,Permission}.php
└── Support/
    ├── PermissionRegistry.php        # the catalogue (source of truth)
    └── PermissionSyncer.php          # registry → permissions table
```

Each mutation records an `ActivityLogger::log(..., logName: 'roles')` entry
(created / updated / deleted), so role changes show up in Activity Logs.

---

## 6. Frontend architecture

Feature-folder convention, mirroring `features/users/`.

```
resources/js/
├── pages/system/roles/index.tsx          # Inertia page (orchestration + gating)
├── hooks/use-permissions.ts              # shared can()/hasRole()/isSuperAdmin()
└── features/roles/
    ├── types.ts · routes.ts · constants.ts
    ├── hooks/use-roles-filters.ts        # URL-owned table state
    └── components/
        ├── roles-stats.tsx · roles-toolbar.tsx · roles-table.tsx
        ├── role-row-actions.tsx · role-badge.tsx
        ├── role-bulk-actions-bar.tsx     # selection action bar (bulk delete)
        ├── permission-matrix.tsx         # grouped grid — interactive & read-only
        ├── role-form-sheet.tsx · role-detail-sheet.tsx
        ├── roles-pagination.tsx · confirm-dialog.tsx
```

The **`PermissionMatrix`** is shared between the form (interactive checkboxes with
per-group "select all") and the detail drawer (read-only check/dash). For the
Super Admin role it renders everything granted (`grantAll`) since that role
bypasses all checks.

Query params: `search`, `type` (`system` | `custom`), `sort` (`label` |
`created_at`), `direction`, `per_page`, `page` — defaults omitted from the URL.

---

## 7. Authorization layer (system-wide)

Wired in `AppServiceProvider::configureAuthorization()`:

```php
Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

foreach (PermissionRegistry::names() as $permission) {
    Gate::define($permission, fn (User $user) => $user->hasPermissionTo($permission));
}
```

- **Super admins bypass every gate.** All other abilities resolve to the
  permission catalogue, backed by the user's roles.
- Routes use `->middleware('can:<permission>')`. The User Management bulk endpoint
  authorises per action inside the controller (`Gate::authorize(...)`).
- `HandleInertiaRequests` shares `auth.roles`, `auth.permissions`, and
  `auth.is_super_admin`. The frontend `usePermissions()` hook reads them to hide
  affordances — **UI gating is convenience only; the backend is authoritative.**
- The sidebar hides System links the user lacks permission for.

### Built-in roles (seeded)

| Role | Key | System | Grants |
| --- | --- | --- | --- |
| Super Admin | `super-admin` | yes | Everything (bypasses all gates). |
| Administrator | `administrator` | yes | Every catalogued permission. |
| HR Manager | `hr-manager` | no | User view/create/update, status, password reset, export; roles.view; activity-logs view/export; notifications.send. |
| Staff | `staff` | no | None (baseline). |

The seeded account (`DatabaseSeeder::ACCOUNT_EMAIL`) is granted **Super Admin** by
`RolePermissionSeeder`.

> ⚠️ Because every `/system/*` route is now permission-gated, an authenticated
> account **with no role** receives `403`. Assign roles via User Management (the
> create/edit drawer shows a role picker to anyone with `roles.assign`).

---

## 8. Testing

`tests/Feature/RolePermission/RolePermissionTest.php` — role CRUD, slug derivation,
duplicate/permission validation, system-role & super-admin guards, CSV export,
**bulk delete** (custom roles removed, system roles skipped, permission enforced,
invalid action rejected), and the authorization matrix (deny without permission,
super-admin bypass, role assignment through User Management).

`tests/Unit/PermissionRegistryTest.php` — catalogue integrity (DB-free, runs
locally).

Shared Pest helpers live in `tests/Pest.php`: `actingAsSuperAdmin()`,
`actingAsUserWith([...])`, `makeRole()`, `seedPermissions()`.

(The Feature suite needs `pdo_sqlite` / CI — see the User Management doc for the
local note. Authorization was additionally validated against live Postgres.)
