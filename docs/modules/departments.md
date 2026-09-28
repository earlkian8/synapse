# Departments (Company Setup)

The org-structure configuration surface: manage **departments** as a hierarchy and the
**positions** under each. These are the lookups the Employee and Recruitment modules
already select from — this module lets an organisation shape them. The *why* is in
[ADR 0008](../decisions/0008-company-setup-org-structure.md); this is the *how*.
Everything is tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/setup` · Sidebar: Company Setup → Departments

## Surface

- **`/setup/departments`** — the org-structure board: stats, search, and an expandable
  **department tree**. Each node shows its code, head, and employee/position counts.
  Selecting a department opens a **detail drawer** that manages its **positions** inline.
  Archived departments live in a collapsible section with restore / permanent-delete.

The interface is a **tree** (not a directory table) because departments are inherently
hierarchical; positions are edited in the drawer because they only exist under a
department.

## Data model

`departments` and `positions` (created with the employee foundation; see the
[schema reference](../database/employees-tables.md)). Highlights:

- A **department** has a `code` (**unique per tenant**, ignoring archived rows), an
  optional `parent_id` (self-referential hierarchy), an optional `head_id` (an
  **employee**), and soft-deletes.
- A **position** belongs to a department and carries an optional salary band
  (`salary_grade_min` / `salary_grade_max`).

`Department` uses `HasHashid` (URLs never expose the integer id), a `search` scope, a
`children` relation, and a `subtreeIds()` helper used for cycle-safe re-parenting.

## Backend

- Controllers (`app/Http/Controllers/Setup/`): `DepartmentController`
  (index / show / store / update / destroy=archive / restore / forceDelete) and
  `PositionController` (store / update / destroy). The index eager-loads each
  department's positions (small metadata) so the drawer needs no extra fetch; `show`
  remains as a JSON endpoint.
- Requests `DepartmentRequest` (per-tenant unique `code` + cycle-safe `parent_id`) and
  `PositionRequest` (`salary_grade_max ≥ min`); resources `DepartmentResource`,
  `PositionResource`; `DepartmentStatistics`. The department rules are
  `DepartmentRequest::rulesFor(?Department)`, so a caller with no route — the assistant
  — gets the same unique-code and cycle guards.
- **`App\Support\Setup\DepartmentWorkflow`** (with `DepartmentException`) is the one
  path for every write: create, update, archive, restore (refused when the code was
  taken meanwhile), permanently delete, and add / update / delete a position. Both
  controllers are thin callers of it, and so is the assistant.
- `routes/setup.php` (prefix `setup`, name `setup.*`). Departments are addressed by
  **hashid**; positions by numeric id. Restore / force-delete take the hashid as a plain
  string so they can resolve archived rows. Every route is permission-gated; mutations
  are activity-logged (`logName: 'company-setup'`).
- **Per-tenant code uniqueness** is enforced by a partial unique index
  `(organization_id, code) WHERE deleted_at IS NULL` (migration
  `…_make_department_code_unique_per_tenant`). It is *partial* on purpose:
  archiving a department frees its code, and `restore()` guards against the code
  having been taken in the meantime. A second, **total** index on the same pair
  survived from `…_add_multi_tenancy` and silently overrode that — archiving
  never freed anything and the restore guard was unreachable — until
  `…_drop_total_department_code_unique` removed it.

## Frontend

`features/departments/` — types, routes, and components: stats, toolbar (search +
archived toggle), **tree** + recursive **node**, **department form sheet** (parent
picker hides the department's own subtree), **detail sheet** (positions list with
add/edit/delete), **position form sheet**, and a confirm dialog. Page:
`pages/setup/departments.tsx`. The sidebar **Company Setup → Departments** link is gated
on `setup.departments.view`.

## Permissions

`setup.departments.view`, `setup.departments.manage`. Seeded to Super Admin /
Administrator (both) and HR Manager (both).

## The assistant

`App\Services\Assistant\Modules\DepartmentsModule` puts the org structure in the chat
assistant ([ADR 0052](../decisions/0052-assistant-departments-without-pay.md)).

- **Reads** (`setup.departments.view`):
  - `find_departments` (archived ones on request);
  - `get_department` — head, where it sits in the tree, sub-departments, positions with
    how many hold each, headcount, default schedule and attendance policy;
  - `list_positions`;
  - `org_structure_summary` — counts, the tree two levels deep, heads, and the gaps
    (no head, nobody assigned).
- **Writes** (`setup.departments.manage`), through `DepartmentWorkflow` and the
  screen's own rules:
  - `create_department` and `update_department` — name, code (upper-cased and unique),
    parent (or top-level), head (or none) and description. A department cannot be
    moved into its own subtree, and the reply uses the screen's words;
  - `restore_department`;
  - `add_position` (a title a department already has is refused) and
    `update_position`;
  - **`archive_department` and `delete_position` always wait for Confirm.** Their
    replies say how many people stay assigned or lose their position.
- **Deliberately screen-only:**
  - **salary bands**, which are never read or set in chat: a band beside a person's
    title estimates their pay (ADR 0027);
  - a department's **default schedule and attendance policy**, since changing them
    re-judges everyone's attendance;
  - **permanent deletion**, which is irreversible and detaches everyone.
- **Retrieval:** "how is our org structure set up?" carries the structure brief before
  the model is called.

## Tests

- `tests/Feature/Setup/DepartmentTest.php` — index + show render, create (code
  upper-cased) + validation, per-tenant duplicate-code rejection + cross-org reuse,
  update, the **cycle guard**, head assignment, archive/restore/force-delete + the
  restore code-clash guard, positions CRUD + salary-band validation, the authorization
  matrix, and tenant isolation.
- `tests/Feature/Setup/DepartmentsAssistantTest.php` — the assistant: permissions and
  confirmations, no salary band anywhere in its reads or tools, the read-out and the
  structure brief, code rules, the cycle guard, heads, archive/restore, positions, and
  tenant isolation.

(The Feature suite needs `pdo_sqlite` / CI; the migration, queries, resources, the tree
render, and every mutation path were validated against live Postgres.)
