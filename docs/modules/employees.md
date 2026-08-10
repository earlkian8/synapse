# Module: Employees

> Status: **Active** · Route prefix: `/employees` · Sidebar: Workforce → Employees

The HR hub of SYNAPSE — the `employees` record that almost every operational
module (attendance, leave, performance, …) will reference. Built to the
same ERP-grade pattern as [User Management](./user-management.md): stats cards,
server-side filtered table, bulk actions, a sectioned create/edit modal, and a
tabbed profile modal with managed sub-records.

This module also lays the **organisation foundation** (departments, positions,
work schedules) the employee form selects from.

---

## 1. Feature overview

| Area | Capabilities |
| --- | --- |
| **Directory** | Server-side search (name / no. / email), filters (department, status, employment type), sortable columns, pagination (10–100). |
| **Stats** | Total, active, regular, probationary, on-leave, new this month. |
| **Create / Edit** | Centred modal with grouped sections: Personal, Employment, Compensation, Government IDs, System account. Employee number auto-generates when blank. |
| **Profile modal** | Tabbed (a real tablist — arrow keys, Home/End): **Profile**, **Performance**, **Training**, **Awards**, **Events**, **Offboarding**, **Documents**, **Certifications**, **History** (career timeline). Sub-records are lazy-loaded. Government IDs and the bank account are masked until revealed. |
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
| GET | `/employees/access` | `access` | `employees.invite` |
| POST | `/employees/join-requests/{joinRequest}/approve` | `join-requests.approve` | `employees.invite` |
| POST | `/employees/join-requests/{joinRequest}/decline` | `join-requests.decline` | `employees.invite` |
| PATCH | `/employees/{employee}/status` | `status` | `employees.update` |
| POST | `/employees/{employee}/invite` | `invite` | `employees.invite` |
| DELETE | `/employees/{employee}/invite` | `invite.revoke` | `employees.invite` |
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
        ├── employee-form-dialog.tsx          # sectioned create/edit; FK selects; dept→position scoping
        ├── employee-detail-dialog.tsx        # tabbed profile + performance/documents/certifications/history
        ├── link-employee-dialog.tsx          # binds an approved join request to a roster line
        └── confirm-dialog.tsx
```

Query params: `search`, `status`, `type`, `department`, `sort` (`first_name` |
`employee_no` | `date_hired`), `direction`, `per_page`, `page`. Defaults are
omitted from the URL.

All four open as **centred modals** on the shared shell in `components/modal.tsx`
(`Modal` / `ModalContent` / `ModalHeader` / `ModalBody` / `ModalFooter`) —
height-capped, with the body as the only scrolling region so Save stays in view.
Fields go through the shared `FormField` + `FormSelect`, which wire the label,
hint and error to the control. See the
[recruitment module doc](recruitment.md#the-modal-shell) for the shell itself.

The detail modal fetches `/employees/{id}` (JSON) on open and renders the
sub-records; uploads post `FormData` and re-fetch on success. Government ID
numbers and the bank account render masked with a reveal control — that is
shoulder-surfing cover for a 201 file opened on a shared screen, **not** access
control, since whoever can open the record was already sent the value.

---

## 5a. App access (ADR 0026)

Creating an employee creates *employment*, never a login. Whether a person can
actually sign in is therefore its own question, answered by a derived
`app_access` on every employee row — `active` (an identity has claimed it),
`invited` (a claim ticket is outstanding), or `none`. It is computed on read
(`Employee::appAccess()`), because the `invited` state lapses on its own when the
invitation expires and no writer would be watching; `EmployeesIndexQuery` eager-loads
`invitations` to keep it off the N+1 path.

**App Access** (`/employees/access`, `employees.invite`) is the screen that manages
it, ordered by who is waiting on whom:

1. **Waiting to join** — people who used the company join code but couldn't be
   matched to a roster line automatically. Approving opens a picker that floats
   email matches to the top; HR must nominate the record before it binds.
2. **Invitations sent** — outstanding claim tickets, with their codes visible so HR
   can read one back to somebody who never got the email. Resend supersedes.
3. **Not invited yet** — the backlog, with a per-row Invite and an Invite-all.

The **company join code** lives at the top of the same screen (rotate + an on/off
switch, both `setup.company.manage`, routed under `setup.company.join-code.*`
because the code belongs to the organisation rather than the roster).

Row actions and the bulk bar offer **Invite / Resend / Revoke** in place of the
removed password reset — HR cannot set or reset anybody's password. All of it runs
through `App\Support\EmployeeInvitations` and `App\Support\WorkspaceJoin`.

---

## 5b. The agentic assistant (ADR 0027)

`App\Services\Assistant\Modules\EmployeeModule` is how the assistant answers
questions about the workforce. **Retrieval is function calling over live,
tenant-scoped, permission-checked queries — not an embedding index**; the *why*
is in [ADR 0027](../decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md).

**Nine tools**, each filtered from the offer *and* re-checked at execution:

| Tool | Permission | Answers |
| --- | --- | --- |
| `find_employees` | `employees.view` | "look up Ana" |
| `get_employee_profile` | `employees.view` | one person's placement, reporting line, dates, tenure, work contact |
| `list_employees` | `employees.view` | "who is in Support", "who joined since March", "who is still probationary" |
| `count_employees` | `employees.view` | "how many …", "headcount by department" — numbers only, never names |
| `list_direct_reports` | `employees.view` | "who reports to Ana" |
| `get_my_employee_record` | *none* | the signed-in user's own record |
| `create_employee` | `employees.create` | add a person (including from an attached CV) |
| `update_employee` | `employees.update` | change fields |
| `archive_employee` | `employees.delete` | archive a person |

**What the assistant will never say.** `App\Support\Employees\EmployeeDisclosure::WITHHELD`
withholds `tin`, `sss_no`, `philhealth_no`, `pagibig_no`, `bank_name`,
`bank_account_no`, `basic_salary`, `address` and `birth_date` from every read,
for every user, at every permission level — because a tool result travels to
Gemini, into the transcript, and onto a possibly-shared screen. They stay
*writable* through the assistant and readable in the 201 file.

**Other guards.** Tools refuse to run when no organisation is bound (the global
scope is a no-op in that state). List reads are capped at 25 rows and carry no
contact details. Retrieved free text is stripped of control characters and
length-capped, so a record cannot pose as a prompt turn — though the real
guarantee is that the model only ever *asks*, and every action re-checks
permission. Reading a named person's profile is activity-logged as `viewed`;
searches and headcounts are not.

`POST /assistant` is throttled at **12/minute and 240/day per user** — a turn
spends Gemini quota.

---

## 6. Permissions & roles

A new **Employee Management** permission group in `PermissionRegistry`:
`employees.view / create / update / delete / restore / force-delete / export /
manage-documents / invite`. Seeded: Super Admin & Administrator get all; **HR
Manager** gets view/create/update/delete/restore/export/manage-documents/invite.
`employees.invite` gates both inviting people and reviewing join requests, and is
back-filled onto existing owner roles by the ADR 0026 migration.

The sidebar's Workforce → Employees link is gated on `employees.view`.

---

## 7. Key decisions

Employee is **separate from User** (`employees.user_id` is a nullable FK, unique per
organisation): a field worker may have no login; an IT admin may not be an employee.
See [ADR 0004](../decisions/0004-employee-user-separation.md). Approvers recorded as
`users`.

Hiring or creating an employee **never creates a login**
([ADR 0026](../decisions/0026-self-served-identity-and-workspace-join.md)). The ERP
owns employment; the person owns identity, registering themselves in the mobile app
and claiming their roster line with an invitation or the company join code. HR can
neither set nor reset a password.

---

## 8. Testing

`tests/Feature/Employee/EmployeeTest.php` — listing & filters (status, department,
search), create (+ auto number) & validation, promotion-on-update, self-manager
guard, archive/restore/force-delete, quick status, export, bulk
(archive/set-status), documents & certifications (store/destroy), and the
authorization matrix (view/create/force-delete/manage-documents gates) + the
unique user link.

`tests/Unit/EmployeeModelTest.php` — `full_name` / `initials` accessors (DB-free).

`tests/Feature/Employee/EmployeeInvitationTest.php` (20) — issuing, the hashed link
token, supersede-on-resend, expiry/revoke, redeeming from outside the issuing tenant,
one-record-per-company, mailbox-scoped discovery, the permission gate, and the mobile
accept endpoint.

`tests/Feature/Employee/WorkspaceJoinTest.php` (15) — auto-match vs queue, the
ambiguous-email and claimed-line guards, disabled/unknown codes, approve/decline,
re-asking after a decline, the App Access screen, registration with no workspace, and
join-code rotation.

(The Feature suite needs `pdo_sqlite` / CI; the schema, queries, resources, stats
and gates were validated against live Postgres.)
