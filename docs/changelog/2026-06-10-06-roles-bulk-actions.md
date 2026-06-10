# 2026-06-10 — Roles & Permissions: row selection & bulk delete

A follow-up to the [Roles & Permissions module](./2026-06-10-roles-and-permissions.md):
the roles table gains row selection and a bulk-delete action, replacing the
decorative per-row icon.

## Summary

- The roles table's leading column is now a **selection checkbox** (header
  select-all with an indeterminate state), replacing the shield icon avatar.
- A contextual **bulk actions bar** appears when rows are selected, offering
  **bulk delete** of the chosen custom roles.
- Built-in (system / super-admin) roles are **protected**: they're skipped
  server-side and the bar shows how many of the selection are protected.
- **Every bulk attempt surfaces a toast** — success (with a "N built-in roles
  were skipped" note when part of the selection is protected) and a warning when
  the whole selection is protected (shown instantly client-side, no empty confirm
  dialog).

## Frontend

- `features/roles/components/roles-table.tsx` — removed the `bg-[#0F2044]` shield
  icon from the Role cell; added a checkbox column (header + per row) wired through
  new `selected` / `onToggleAll` / `onToggleRow` props. Empty-state `colSpan`
  bumped to 7.
- `features/roles/components/role-bulk-actions-bar.tsx` — **new**. Shows the
  selected count, a "N built-in roles are protected" hint, a destructive Delete
  button (always clickable so the action always yields feedback), and Clear.
  Mirrors User Management's bar.
- `features/roles/types.ts` — added `BulkRoleAction = 'delete'`.
- `features/roles/routes.ts` — added `bulk: '/system/roles/bulk'`.
- `pages/system/roles/index.tsx` — selection state, stale-selection reset on
  filter/page change (signature pattern), `deletableCount` (selection minus system
  roles), `runBulk` / `handleBulk`. When the whole selection is protected it fires
  an immediate `sonner` warning toast (no empty confirm); when partly protected the
  confirm dialog notes how many will be skipped. The bar renders between toolbar
  and table.

## Backend

- `Http/Controllers/RolePermission/RoleBulkActionController.php` — **new**
  invokable. `Gate::authorize('roles.delete')`, splits the submitted ids into
  deletable (non-system) and protected, deletes the former, logs to Activity Logs
  (`logName: 'roles'`, with the protected count), and **always flashes a toast**:
  success — appending "N built-in roles were skipped" when part of the selection is
  protected — or a warning when the whole selection is protected.
- `Http/Requests/RolePermission/BulkRoleActionRequest.php` — **new**. Validates
  `action` ∈ `['delete']` and a non-empty integer `ids` array.
- `routes/system.php` — added `POST /system/roles/bulk`
  (`system.roles.bulk`, `can:roles.view`; the action re-authorises `roles.delete`
  inside the controller).

## Tests

- `tests/Feature/RolePermission/RolePermissionTest.php` — added: bulk delete
  removes custom roles; bulk delete skips system roles in a mixed selection; a
  selection of only system roles is left untouched; a user without `roles.delete`
  is forbidden; an unknown action is rejected.

## Verification

`tsc`, ESLint and Pint clean; `npm run build` succeeds (`roles` chunk 37.8 kB).
Route registered (`php artisan route:list`). (Feature suite still needs
`pdo_sqlite` / CI to execute locally.)
