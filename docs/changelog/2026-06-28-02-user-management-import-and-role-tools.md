# User Management — CSV import, role filter & bulk role assignment

Extends the [User Management module](../modules/user-management.md) with three
genuinely-missing, at-scale capabilities — without disturbing its existing CRUD,
soft-delete lifecycle, bulk status actions, export, search/sort/pagination or
self-protection guards.

## Highlights

- **CSV bulk import.** A new **Import** button opens a dialog to bulk-create accounts
  from a CSV: download a ready-to-fill **template**, drop/choose a file, and get an
  **inline result** — created vs. skipped counts, a per-row error list, and a
  **downloadable error report** to fix and re-import. Validation mirrors the
  single-create form; one bad row never aborts the rest.
- **Filter by role.** A role dropdown in the toolbar narrows the list to holders of a
  given role (server-side, URL-driven like the other filters).
- **Bulk assign role.** With rows selected, an **Assign role** menu grants a role to
  the whole selection at once (additive — existing roles are untouched).

## Backend

- **`App\Support\UserImporter`** — the canonical "CSV → users" operation. BOM/case-
  tolerant headers; per-row validation reusing the `StoreUserRequest` rules; **in-file**
  duplicate-email detection (on top of the DB unique rule); optional `role` column
  resolved by machine **name or label**, honoured only when the actor holds
  `roles.assign`; best-effort verification email + welcome notification per created
  user. Passwords are never imported; the file is capped at `MAX_ROWS` (200) to bound the
  synchronous request; a single summary row is written to the activity log.
- **`UserImportController`** — `template` streams the example CSV; `store`
  (`ImportUsersRequest`, `.csv` ≤ 2 MB) runs the import and returns the result as **JSON**
  so the dialog can render an inline report without a navigation. Both gated by
  `users.create`. Routes `users.import.template` / `users.import.store` registered before
  the `{user}` wildcard.
- **`UsersIndexQuery::role`** — a validated role-id filter via
  `whereHas('roles', …)`; an unknown id is ignored. `UserController@index` now passes the
  full `roles` list (for the filter, any viewer) alongside the existing `assignableRoles`
  (gated by `roles.assign`), and the `role` filter value.
- **`UserBulkActionController`** — new **`assign-role`** action (gated by `roles.assign`,
  validated `role_id`) attaches the role to each selected user with
  `syncWithoutDetaching` and forgets cached permissions so access reflects the change
  immediately; users who already hold it are not double-counted. `BulkUserActionRequest`
  extended accordingly.

## Frontend

- **`features/users/components/import-users-dialog.tsx`** — template link, file picker
  with drag-and-drop, an inline summary (created/skipped chips, verification-email note,
  scrollable per-row errors) and a client-generated **error-report CSV**. Posts via a new
  **`features/users/api.ts`** CSRF `fetch` helper; on success it `router.reload`s only
  `users` + `stats`.
- **`users-toolbar.tsx`** — a **Role** `Select` and an **Import** button.
- **`bulk-actions-bar.tsx`** — an **Assign role** dropdown (shown when the actor can
  assign roles and the scope isn't archived).
- **Plumbing** — `role` added to `UsersFilters` + `use-users-filters` (`setRole`),
  `ImportResult` / `assign-role` added to types, `ALL_ROLES` sentinel + `role` default in
  constants, and the page wires it all (role filter resets selection like the others).

## Notes

- Verified: `php -l`, Pint (`passed`), `tsc`, ESLint and Prettier (the new/changed files),
  and `vite build` all green. A **tinker** run of `UserImporter` against a mixed CSV
  confirmed: valid rows create (role assigned by label, `is_active` parsed), an invalid
  email, an in-file **and** already-persisted duplicate, and an unknown role are each
  rejected with a clear per-row message — then the test users were cleaned up. The Pest
  Feature suite (`UserManagementTest`) was **not** run locally (no `pdo_sqlite`); new
  cases for import / role filter / bulk-assign-role are a worthwhile CI follow-up.
- Scope: only checklist items that are *applicable to user accounts* were added.
  Deliberately **excluded** as out-of-domain or already present: Kanban/Calendar/Timeline
  views, QR/Barcode, résumé/contract attachments, approval workflows, birthday/probation
  reminders, record duplication — plus everything the module already had (soft-delete,
  bulk status, export, RBAC, audit logging, dark mode, responsive).
