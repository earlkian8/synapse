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
- **`/setup/onboarding`** — manage **programs** (templates) and their blueprint tasks.
  They live under **Company Setup** (routes `setup.onboarding.*`), with the other
  configuration surfaces, because they decide what *every* new hire's checklist is
  seeded from.

## The agentic assistant

Everything a coordinator does on the board, the **Synapse assistant** can do in
conversation. `App\Services\Assistant\Modules\OnboardingModule` exposes **16 Gemini
function declarations**; the *why* is in
[ADR 0025](../decisions/0025-agentic-onboarding-and-chasing-outstanding-work.md).
The model only *decides*; the module *enforces* (permission, validation, tenancy,
activity log, notifications), reusing the same code the controllers do.

| Group | Tools |
| --- | --- |
| Cases | `find_onboarding_cases` (employee / status / department / **overdue** / due window), `start_onboarding`, `update_onboarding_case` (target date, notes), `set_onboarding_status`, `delete_onboarding_case` |
| Checklist | `find_onboarding_tasks` (employee / assignee / **mine** / status / category / **overdue** / due window), `add_onboarding_task`, `update_onboarding_task` (also how you reassign), `set_task_status`, `remove_onboarding_task`, `nudge_onboarding_task` |
| Programs | `find_onboarding_programs` (incl. **`for_employee`** — which template a hire would get), `create_onboarding_program`, `update_onboarding_program`, `delete_onboarding_program` |
| Decision support | `onboarding_summary` |

- **Permission-scoped tool surface.** `tools($user)` / `guidance($user)` take the
  signed-in user, so the model is offered only what their role allows: view-only sees
  **4** tools, `onboarding.manage` sees **13**, and the three program tools appear only
  with `onboarding.manage-programs`. Each handler re-checks anyway — the filter narrows
  what is *offered*, not what is *enforced*.
- **Chasing people is a first-class action.** `nudge_onboarding_task` reminds whoever
  owns outstanding work: name a task to chase one item, or pass only the employee to
  chase everything overdue on their checklist. Reminders are **grouped per person**, so
  someone with four open items gets one message listing them, not four pings. It
  refuses to chase an unassigned or already-resolved task, and it really sends
  notifications — the guidance tells the model to do it only on a clear request.
- **Completion is honest.** Completing a case with unresolved tasks is allowed (HR
  legitimately closes cases early) but the reply *and the activity log* say how many
  were left, so nobody discovers it later.
- **The template preview cannot lie.** `find_onboarding_programs` with `for_employee`
  answers "which checklist would this hire get?" through
  `OnboardingProvisioner::programFor()` — the same resolver the hire bridge uses.
- **No second model call.** `onboarding_summary` (org-wide, or one employee's progress,
  overdue count, target countdown and next task up) is a pure database read returned as
  an `insight` card, which the orchestrator narrates from its own metrics.

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
  the recruitment `HireController` (in its hire transaction), the manual *Start
  onboarding* action, and the assistant.
- **Shared with the assistant** (one implementation, two callers): the state
  transitions live on the models — `OnboardingCase::applyLifecycle()` (the only place
  `completed_at` is stamped or cleared), `touchProgress()` (the pending → in_progress
  nudge), `progressSummary()` (the one definition of "how far along", which
  `OnboardingCaseResource` now renders), the `ACTIVE_STATUSES` / `LIFECYCLE_ACTIONS`
  vocabulary and the `active()` scope; `OnboardingTask::markStatus()` (completion
  stamping), `RESOLVED_STATUSES` and the `unresolved()` / `overdue()` / `onActiveCase()`
  / `search()` scopes; `OnboardingProgram::syncBlueprint()` + `enforceSingleDefault()`
  (the template writers) and its `search()` scope. **`App\Support\OnboardingTaskNotifier`**
  owns every ping to a task's owner — `assigned()` on a real assignee change, `nudge()`
  for one item, `nudgeMany()` grouped per person.
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
inline blueprint-task editor), and a confirm dialog. Pages: `pages/onboarding/index.tsx`
and `case.tsx`; the programs screen is `pages/setup/onboarding.tsx`. The sidebar
**Talent Acquisition → Onboarding** link is gated on `onboarding.view`.

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
- `tests/Feature/Onboarding/OnboardingAssistantTest.php` — the agentic surface, driving
  `OnboardingModule` directly (no model call): the permission-scoped tool list (view /
  manage / manage-programs), a **denial case for all 12 mutating tools**, every case,
  checklist and program action with its guards (second case, unknown program, unknown
  assignee, empty edit, no case at all), the completion-with-unresolved-work report, the
  **unresolved-task-wins** title match, the per-owner grouped nudge (and its refusals),
  the `for_employee` template preview, blueprint-only-when-supplied editing, both
  read-outs, and tenant isolation.

(The Feature suite runs on the throwaway Postgres harness — see the implementation
method — since local PHP has no `pdo_sqlite`.)
