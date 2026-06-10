# `roles`, `permissions` & pivots

The lightweight RBAC schema introduced by
`2026_06_10_200000_create_roles_and_permissions_tables`. Backs the
[Roles & Permissions module](../modules/roles-permissions.md) and the system-wide
authorization layer.

## `roles`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `name` | string, **unique** | Machine slug, e.g. `super-admin`, `hr-manager`. Immutable after creation. |
| `label` | string | Human display name, e.g. "HR Manager". |
| `description` | string, nullable | What the role is responsible for. |
| `is_system` | boolean, default `false` | Built-in role — **cannot be deleted** through the UI. |
| `created_at` / `updated_at` | timestamps | |

`name === 'super-admin'` is the protected god role: it cannot be edited and
bypasses every gate (`Role::SUPER_ADMIN`).

## `permissions`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `name` | string, **unique** | Dotted ability, e.g. `users.view`. Matches a gate. |
| `label` | string | Human label, e.g. "View users". |
| `group` | string | UI grouping, e.g. "User Management". |
| `created_at` / `updated_at` | timestamps | |

Rows are **synced from `App\Support\PermissionRegistry`** (code is the source of
truth) via `PermissionSyncer::sync()`. Do not edit this table by hand.

## `permission_role` (pivot)

| Column | Type | Notes |
| --- | --- | --- |
| `permission_id` | FK → `permissions`, cascade on delete | |
| `role_id` | FK → `roles`, cascade on delete | |
| | | Composite primary key `(permission_id, role_id)`. |

## `role_user` (pivot)

| Column | Type | Notes |
| --- | --- | --- |
| `role_id` | FK → `roles`, cascade on delete | |
| `user_id` | FK → `users`, cascade on delete | |
| | | Composite primary key `(role_id, user_id)`. |

## Relationships

- `Role` ⇄ `Permission` (many-to-many via `permission_role`).
- `Role` ⇄ `User` (many-to-many via `role_user`).
- A user's **effective permissions** = the union of permissions across their
  roles (`User::permissionNames()`, memoised per request).

## Why this shape

The table and pivot names mirror a custom-but-spatie-compatible layout so
`spatie/laravel-permission` can be adopted later without a schema rewrite. See
[ADR 0002](../decisions/0002-rbac-authorization.md). This differs slightly from the
draft [ERD](./erd.md), which sketched the same four tables.
