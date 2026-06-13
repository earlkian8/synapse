# Module: Trash Bin

> Status: **Active** · Route prefix: `/system/trash` · Sidebar: System → Trash Bin

A single place to see and manage **archived (soft-deleted) records** from across
the system — restore them or delete them permanently. It does not introduce its
own data; it is a unified view over every model that uses `SoftDeletes`.

---

## 1. What it covers

The trashable entities are declared in
[`app/Support/Trash/TrashRegistry.php`](../../server/app/Support/Trash/TrashRegistry.php).
Adding a new soft-deletable model to the bin is a one-entry change there.

| Type key | Model | Viewed when | Restore / delete require |
| --- | --- | --- | --- |
| `user` | `User` | `users.view` | `users.restore` / `users.force-delete` |
| `employee` | `Employee` | `employees.view` | `employees.restore` / `employees.force-delete` |
| `department` | `Department` | `setup.departments.view` | `setup.departments.manage` |
| `leave_type` | `LeaveType` | `setup.leave-types.view` | `setup.leave-types.manage` |

`Organization` (the tenant itself) uses soft-deletes but is intentionally **not**
listed in the bin.

---

## 2. Permissions (RBAC, no bypass)

The bin never invents permissions — it composes the **owning module's** existing
ones, so it can't be used to escalate:

- The page is reachable only if the actor can **view at least one** trashable type
  (`abort 403` otherwise). The sidebar item uses the same any-of check.
- The listing only includes types the actor can **view** — an HR user without
  `users.view` never sees archived users.
- **Restore** requires the type's restore/manage permission; **permanent delete**
  requires its force-delete/manage permission. Each is re-checked per item, so a
  tampered bulk payload can't act on something it shouldn't.

There is **no `trash.*` permission** and nothing to seed — capability is derived
from what the user can already do in each module.

---

## 3. Routes

Defined in [`server/routes/system.php`](../../server/routes/system.php) under
`['auth', 'verified']`, name prefix `system.trash.*`. Authorisation is per-item
(by type), so these carry no static `can:` middleware.

| Method | URI | Name | Purpose |
| --- | --- | --- | --- |
| GET | `/system/trash` | `index` | Listing (Inertia). |
| POST | `/system/trash/restore` | `restore` | Restore one `{type, id}`. |
| POST | `/system/trash/force-delete` | `force-delete` | Permanently delete one `{type, id}`. |
| POST | `/system/trash/bulk` | `bulk` | `{action: restore\|delete, items: [{type, id}]}`. |
| POST | `/system/trash/empty` | `empty` | Permanently delete everything the actor may force-delete. |

---

## 4. Backend architecture

```
server/app/
├── Http/Controllers/System/TrashController.php   # index, restore, forceDelete, bulk, empty
├── Http/Resources/TrashItemResource.php          # pass-through → the shared Paginated<T> envelope
├── Queries/TrashIndexQuery.php                    # aggregate + filter + sort + paginate; summary()
└── Support/Trash/
    ├── TrashRegistry.php                          # the trashable-type catalogue + per-type permissions
    └── TrashPresenter.php                         # one trashed model → the uniform row shape
```

### Listing pipeline

`TrashIndexQuery` materialises each viewable type's `onlyTrashed()` query
(reusing that model's `search` scope and organisation scope), merges the results,
sorts by **most recently archived**, and hand-builds a `LengthAwarePaginator`.
Trash sets are small (archived, usually pruned), so this in-PHP merge stays
portable across Postgres/SQLite with no cross-table `UNION` dialect concerns.
`summary()` returns per-type counts for the type tabs.

Permanent deletion best-effort cleans up files a record owns on disk (a user's or
employee's photo) so purging never orphans uploads. Restores/deletes are recorded
in `activity_logs` under the `trash` log.

---

## 5. Frontend

Feature folder `resources/js/features/trash/` (types, routes, constants,
`use-trash-filters`) with components `trash-toolbar` (debounced search + type tabs
+ Empty trash), `trash-table` (selection, per-type avatar/icon + badge),
`trash-row-actions`, and `bulk-actions-bar`. The thin page is
`pages/system/trash/index.tsx`. The generic `ConfirmDialog` and pagination are
reused from the users feature.

Each row carries `can_restore` / `can_force_delete`, so the UI only ever offers
actions the server will allow; bulk actions act on the permitted subset of the
selection.

---

## 6. Testing

[`server/tests/Feature/Trash/TrashTest.php`](../../server/tests/Feature/Trash/TrashTest.php)
covers rendering, cross-type listing, the type filter, restore / permanent-delete,
bulk restore, empty, the guest redirect, and the RBAC guards (a user only sees
viewable types; restore is denied without the owning permission; the page 403s
when no type is viewable).

> ⚠️ The Feature suite runs on SQLite `:memory:`; the local PHP build lacks
> `pdo_sqlite`, so these run in CI.
