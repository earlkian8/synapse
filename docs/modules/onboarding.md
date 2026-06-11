# Onboarding

The bridge between a **hire** and a productive employee: a structured, template-driven
checklist that carries each new hire through their first days. The *why* is in
[ADR 0007](../decisions/0007-onboarding-template-bridge.md); this is the *how*.
Everything is tenant-scoped (ADR 0005).

## Where it sits in the life cycle

```
Applicant ─▶ Application ─▶ hire ─▶ Employee ─▶ Onboarding ─▶ productive
   └──────── Recruitment (ADR 0006) ────────┘   └─ this module ─┘
```

A hire in Recruitment automatically **starts an onboarding case** for the new employee.
Onboarding can also be started manually for anyone created outside Recruitment.

## Surfaces

- **`/onboarding`** — the overview **board**: stats, search/status/department filters,
  and a card grid of in-flight (and past) cases, each with a progress bar, target date,
  and overdue count. A card opens the case. (A board of people-cards, not a table — the
  unit of work here is a *person being onboarded*, so progress-at-a-glance beats rows.)
- **`/onboarding/{case}`** — the **case**: the employee header, a progress summary, and
  the **checklist grouped by category** (Paperwork · Equipment · Access · Orientation ·
  Training · Compliance · Other). Tick tasks done, assign them, set due dates, add ad-hoc
  tasks, edit notes/target, and complete / cancel / reopen the onboarding.
- **`/onboarding/programs`** — manage **programs** (templates) and their blueprint tasks.

## Data model

`onboarding_programs`, `onboarding_program_tasks`, `onboarding_cases`,
`onboarding_tasks` — see the [schema doc](../database/onboarding-tables.md). Highlights:

- A **program** is a reusable template, optionally targeted at a department and/or
  employment type; one is the tenant **default**. Its **blueprint tasks** carry a
  *relative* `due_offset_days` (days after start), not an absolute date.
- A **case** is one employee's onboarding (`unique(employee_id)`), with a lifecycle
  (`pending → in_progress → completed / cancelled`), a start/target date, and notes.
- A **task** is a concrete checklist item on a case: category, optional assignee
  (a user), due date, and status (`pending → in_progress → done`, or `skipped`).

## Backend

- Controllers (`app/Http/Controllers/Onboarding/`): `OnboardingCaseController`
  (index / show / store / update / status / destroy), `OnboardingTaskController`
  (store / update / toggle / destroy), `OnboardingProgramController`
  (index / store / update / destroy).
- **`App\Support\OnboardingProvisioner`** — the connective tissue. `start()` picks the
  best-matching active program (department + type → department → type → default) and
  instantiates its blueprint into a dated checklist; idempotent per employee. Called by
  the recruitment `HireController` (in its hire transaction) and by the manual *Start
  onboarding* action.
- Requests under `app/Http/Requests/Onboarding/`; resources `OnboardingCaseResource`
  (with a derived `progress` summary), `OnboardingTaskResource`,
  `OnboardingProgramResource`; queries `OnboardingCasesIndexQuery` (filtered, with task
  counts — no pagination, the board is card-based) and `OnboardingStatistics`.
- `routes/onboarding.php` (literal-prefixed routes precede the `{case}` wildcard). Every
  route is permission-gated. Cases and programs are addressed by **hashid**
  (`App\Support\Hashid`, via `HasHashid`); tasks by numeric id (sub-resources).
- Mutations are activity-logged (`logName: 'onboarding'`); assigning a task notifies the
  assignee.

### Progress & lifecycle

`progress` = resolved (`done` + `skipped`) / total tasks. A `pending` case
**auto-advances to `in_progress`** on the first task activity; completion / cancellation /
reopen are deliberate actions (`PATCH …/status`). The stage toggle stamps `completed_at`
/ `completed_by` when a task is marked done.

## Frontend

`features/onboarding/` — types, routes, constants (status & category meta), the board
filter hook, and components: stats, toolbar, **case card**, progress bar, status badge,
**start-onboarding sheet**, **task checklist** (grouped) + **task row** + **task form
sheet**, **case settings sheet**, **program card** + **program form sheet** (with an
inline blueprint-task editor), and a confirm dialog. Pages: `pages/onboarding/index.tsx`,
`case.tsx`, `programs.tsx`. The sidebar **Talent Acquisition → Onboarding** link is gated
on `onboarding.view`.

## Permissions

`onboarding.view`, `onboarding.manage` (start cases, manage checklists & lifecycle),
`onboarding.manage-programs` (templates). Seeded to Super Admin / Administrator (all) and
HR Manager (all three).

## Tests

- `tests/Feature/Onboarding/OnboardingTest.php` — overview render + status filter, start
  (with seeded checklist) + the one-case-per-employee guard, case render, add/edit/delete
  task, the complete-stamp + `in_progress` nudge, overdue surfacing, complete/cancel/reopen,
  notes/target update + delete, programs CRUD (with single-default enforcement), the
  **hire → onboarding bridge**, the authorization matrix, and tenant isolation.
- `tests/Unit/OnboardingTaskModelTest.php` — task/case accessors (DB-free).

(The Feature suite needs `pdo_sqlite` / CI; the schema, queries, resources, the hire
bridge and gates were validated against live Postgres.)
