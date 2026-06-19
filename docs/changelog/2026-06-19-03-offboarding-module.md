# Offboarding module

Adds the **Offboarding** module (ERD §9): the structured exit of a departing employee.
Mirrors **Onboarding** — a per-employee **case** seeding a **clearance** checklist, with
a deliberate lifecycle and a detail page — but the checklist is grouped by the
**responsible department** and the case-level clearance status is **derived**, not
stored. Completing an exit **separates the employee** (transitions
`employment_status`). Wires the existing sidebar placeholder. See
[ADR 0016](../decisions/0016-offboarding-and-clearance.md),
[module doc](../modules/offboarding.md) and
[offboarding tables](../database/offboarding-tables.md).

## Highlights

- **Board of exits.** `/offboarding` shows KPIs (in offboarding, flagged clearances,
  leaving in 14 days, completed this month) and a card per exit with the type, clearance
  progress, last working day and flag count — filterable by search / type / department /
  status.
- **Case + department clearance.** `/offboarding/{case}` carries the exit header,
  clearance summary (with the **derived** `pending → in_progress → cleared` status) and a
  checklist **grouped by responsible department**; HR clears / flags items, adds ad-hoc
  ones, edits details, and completes / cancels / reopens.
- **Completion separates the employee.** Completing transitions the employee's
  `employment_status` (`resignation`/`retirement`/`end_of_contract` → `resigned`,
  `termination` → `terminated`); reopening or cancelling returns them to `active`. The
  complete action confirms the change and warns of any pending items.

## Backend

- **Migration** `…_create_offboarding_tables`: `offboarding_cases` (employee unique,
  type, notice_date, last_working_day, reason, status, completed_at) and
  `clearance_items` (case, item, department_id → departments, status, remarks, cleared_by
  → users, cleared_at, sort_order). Tenant-scoped.
- **Models** `OffboardingCase` (HasHashid, `TYPES`/`STATUSES`, `isActive()`,
  `targetEmploymentStatus()`, `clearanceItems`) + `ClearanceItem` (`STATUSES`,
  `isCleared()`, `department`/`clearedBy` loaded `withTrashed`); `Employee::offboardingCase`.
- **Support** `OffboardingProvisioner` — opens a case and instantiates the standard
  clearance checklist (routing each item to IT / Finance / HR by code, or the employee's
  own department); also the single source of truth for the derived `clearanceStatus()`.
- **Controllers** `Offboarding\OffboardingCaseController` (index / show / store / update /
  status / destroy — `status` runs the employment-status bridge) and
  `ClearanceItemController` (store / update / toggle / destroy — `toggle` stamps the
  sign-off and nudges `initiated → clearance`). Thin, FormRequest-validated,
  activity-logged (`logName: 'offboarding'`).
- **Requests** `InitiateOffboardingRequest`, `StoreClearanceItemRequest`.
- **Resources** `OffboardingCaseResource` (derived `clearance` summary) +
  `ClearanceItemResource`. **Queries** `OffboardingCasesIndexQuery` (filtered, with
  clearance counts) + `OffboardingStatistics`.
- **Routes** new `routes/offboarding.php` (required in `web.php`); `clearance/…` routes
  declared before the `{case}` wildcard. Permissions `offboarding.view` /
  `offboarding.manage` added to `PermissionRegistry`; built-in HR Manager granted both.
- **Seeder** `OffboardingSeeder` (5 exits across the lifecycle + the 10-item clearance
  checklist each); wired into `DatabaseSeeder` and `MockSeeder`. Factories
  `OffboardingCaseFactory` + `ClearanceItemFactory`.
- **Employee integration** `EmployeeController` eager-loads
  `offboardingCase.clearanceItems`; `EmployeeResource` exposes `offboarding`.

## Frontend

- **Feature** `features/offboarding` (types, routes, constants with status / type /
  clearance meta + date helpers, the board filter hook, components: stats, toolbar,
  case card, status + type badges, progress bar, initiate-offboarding sheet, clearance
  checklist grouped by department + item row + item form sheet, case settings sheet,
  confirm dialog).
- **Pages** `offboarding/index` (board) + `offboarding/case` (header, clearance summary,
  grouped checklist, lifecycle actions).
- **Employee detail** read-only **Offboarding** tab (alongside Training, Awards & Events).
- **Sidebar** Offboarding placeholder gated by `offboarding.view`.

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint (project-wide), Prettier and `vite build` all
  green (the `offboarding/{index,case}` chunks emitted). Migration applied on Postgres;
  a tinker run confirmed `OffboardingSeeder` produces the lifecycle spread, the
  department routing (HR / IT / Finance + own department), the **derived** clearance
  status, the index query + statistics, and the **employment-status bridge** on
  completion. Also confirmed `offboarding.view` / `offboarding.manage` synced and granted
  to HR Manager. Pest was **not** run locally (no `pdo_sqlite`).
- Out of scope this cut: auto exit-interview survey, document generation (clearance form
  / COE PDF), a self-service resignation request, final-pay computation, and an assistant
  capability — matching the Training / Awards / Events precedent.
