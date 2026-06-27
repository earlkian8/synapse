# Module: Employees

> Status: **Active** · Route prefix: `/employees` · Sidebar: Workforce → Employees

The HR hub of SYNAPSE — the `employees` record that almost every operational
module (attendance, leave, performance, …) will reference. Built to the
same ERP-grade pattern as [User Management](./user-management.md): stats cards,
server-side filtered table, bulk actions, a sectioned create/edit drawer, and a
tabbed profile drawer with managed sub-records.

This module also lays the **organisation foundation** (departments, positions,
work schedules) the employee form selects from.

---

## 1. Feature overview

| Area | Capabilities |
| --- | --- |
| **Directory** | Server-side search (name / no. / email), filters (department, status, employment type), sortable columns, pagination (10–100). |
| **Stats** | Total, active, regular, probationary, on-leave, new this month. |
| **Create / Edit** | Slide-over with grouped sections: Personal, Employment, Compensation, Government IDs, System account. Employee number auto-generates when blank. |
| **Profile drawer** | Tabbed: **Profile** (read-only sections, incl. salary & government IDs), **Performance**, **Training**, **Awards**, **Events**, **Offboarding**, **Documents**, **Certifications**, **History** (career timeline). Sub-records are lazy-loaded. |
| **201 file** | Upload/remove **documents** (contract, CV, govt ID…) and **certifications** (with expiry tracking). |
| **Career history** | A `employee_promotions` row is **auto-recorded** whenever an employee's position or salary changes. |
| **Lifecycle** | Quick status change (active / on-leave / suspended / resigned / terminated), archive (soft delete), restore, permanent delete. |
| **Bulk actions** | Archive, restore, permanent delete, and set-status across a selection. |
| **Account link** | Optionally link an employee to a `users` login (nullable, unique). |
| **Export** | CSV honouring the current filters. |

---

## 2. Data model

The Employee core and its sub-records (see the
[schema reference](../database/employees-tables.md)):

- **`employees`** — identity, placement (department / position / manager / work
  schedule), employment (type, status, hire dates), compensation, government IDs,
  optional `user_id` link. Soft-deletes.
- **`employee_documents`** — the 201 file (title, type, file, uploader).
- **`employee_certifications`** — name, issuer, issued/expiry dates, file.
- **`employee_promotions`** — career history (from/to position & salary, date).

Foundation lookups (also new):

- **`departments`** — org chart (`code`, `parent_id`, `head_id` → employee).
- **`positions`** — job titles + salary band, under a department.
- **`work_schedules`** — shift definition (times, work days, grace, hours).

> `departments.head_id` references `employees` while `employees.department_id`
> references `departments` — a deliberate cycle. The head FK is added *after* the
> employees table exists (see the employees migration).

Models: `Employee` (relations, `full_name`/`initials`/`photo_url` accessors,
`search` scope), `Department`, `Position`, `WorkSchedule`, `EmployeeDocument`,
`EmployeeCertification`, `EmployeePromotion`. `User` gains an `employee()` hasOne.

**Profile photo.** `employees.photo` stores either an uploaded file (on the
`public` disk, via the form's preview/change/remove block) or, for seeded demo
data, an absolute portrait URL. The **`photoUrl()`** accessor normalises both: an
absolute URL passes through unchanged, a stored path resolves on the `public`
disk. Every module that surfaces an employee renders the photo through the shared
`resources/js/components/person-avatar.tsx` (`PersonAvatar`) — directory, leave
inbox + balances, onboarding board, and the department org chart head. See the
[2026-06-12 changelog](../changelog/2026-06-12-01-employee-profile-photos.md).

---

## 3. Routes

Registered in [`routes/employees.php`](../../server/routes/employees.php) under
`['auth', 'verified']`, name prefix `employees.*`. Every route is permission-gated.

| Method | URI | Name | Permission |
| --- | --- | --- | --- |
| GET | `/employees` | `index` | `employees.view` |
| GET | `/employees/export` | `export` | `employees.export` |
| POST | `/employees/bulk` | `bulk` | `employees.view` + per-action gate |
| POST | `/employees` | `store` | `employees.create` |
| GET | `/employees/{employee}` | `show` (JSON) | `employees.view` |
| POST | `/employees/{employee}` | `update` | `employees.update` |
| DELETE | `/employees/{employee}` | `destroy` (archive) | `employees.delete` |
| PATCH | `/employees/{employee}/status` | `status` | `employees.update` |
| PATCH | `/employees/{employee}/restore` | `restore` | `employees.restore` |
| DELETE | `/employees/{employee}/force` | `force-delete` | `employees.force-delete` |
| POST/DELETE | `…/documents[/{document}]` | `documents.*` | `employees.manage-documents` |
| POST/DELETE | `…/certifications[/{certification}]` | `certifications.*` | `employees.manage-documents` |

`show` returns the full record (with sub-records) as JSON — the profile drawer
fetches it on open so the list query stays light.

---

## 4. Backend architecture

```
app/
├── Http/Controllers/Employee/
│   ├── EmployeeController.php             # index, show, store, update, destroy, restore, forceDelete
│   ├── EmployeeStatusController.php       # quick status change
│   ├── EmployeeBulkActionController.php   # archive/restore/delete/set-status (per-action gate)
│   ├── EmployeeExportController.php       # streamed CSV
│   ├── EmployeeDocumentController.php
│   └── EmployeeCertificationController.php
├── Http/Requests/Employee/                # Store/Update/Bulk requests (+ enum consts)
├── Http/Resources/Employee*Resource.php
├── Queries/EmployeesIndexQuery.php        # filter (status/type/department) + sort + paginate + counts
├── Queries/EmployeeStatistics.php
└── Models/{Employee,Department,Position,WorkSchedule,Employee*}.php
```

- **Employee number** auto-generates as `EMP-NNNNN` (next id) when left blank.
- **Promotion history** is recorded by `EmployeeController::recordPromotion()`
  whenever `position_id` or `basic_salary` changes on update.
- Every mutation records an `ActivityLogger::log(..., logName: 'employees')` entry.

---

## 5. Frontend architecture

```
resources/js/
├── pages/employees/index.tsx                 # orchestration page (gating, selection)
└── features/employees/
    ├── types.ts · routes.ts · constants.ts
    ├── hooks/use-employees-filters.ts
    └── components/
        ├── employees-stats.tsx · employees-toolbar.tsx · employees-table.tsx
        ├── employee-row-actions.tsx · employee-status-badge.tsx · employee-avatar.tsx
        ├── employee-bulk-actions-bar.tsx · employees-pagination.tsx
        ├── employee-form-sheet.tsx           # sectioned create/edit; FK selects; dept→position scoping
        ├── employee-detail-sheet.tsx         # tabbed profile + performance/documents/certifications/history
        └── confirm-dialog.tsx
```

Query params: `search`, `status`, `type`, `department`, `sort` (`first_name` |
`employee_no` | `date_hired`), `direction`, `per_page`, `page`. Defaults are
omitted from the URL.

The detail drawer fetches `/employees/{id}` (JSON) on open and renders the
sub-records; uploads post `FormData` and re-fetch on success.

---

## 6. Permissions & roles

A new **Employee Management** permission group in `PermissionRegistry`:
`employees.view / create / update / delete / restore / force-delete / export /
manage-documents`. Seeded: Super Admin & Administrator get all; **HR Manager**
gets view/create/update/delete/restore/export/manage-documents.

The sidebar's Workforce → Employees link is gated on `employees.view`.

---

## 7. Key decisions

Employee is **separate from User** (`employees.user_id` is a nullable, unique FK):
a field worker may have no login; an IT admin may not be an employee. See
[ADR 0004](../decisions/0004-employee-user-separation.md). Single-tenant; approvers
recorded as `users`.

---

## 8. Testing

`tests/Feature/Employee/EmployeeTest.php` — listing & filters (status, department,
search), create (+ auto number) & validation, promotion-on-update, self-manager
guard, archive/restore/force-delete, quick status, export, bulk
(archive/set-status), documents & certifications (store/destroy), and the
authorization matrix (view/create/force-delete/manage-documents gates) + the
unique user link.

`tests/Unit/EmployeeModelTest.php` — `full_name` / `initials` accessors (DB-free).

(The Feature suite needs `pdo_sqlite` / CI; the schema, queries, resources, stats
and gates were validated against live Postgres.)
