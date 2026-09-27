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

Onboarding reads top-down in **three levels**, each a table, laid out like the
Employees module (header · compact stat tiles · toolbar · table · pagination):

1. **`/onboarding`** — the **programs**: one row per program with who it applies to, its
   task count, how many people it is onboarding and has completed, the progress of its
   in-flight checklists and their overdue tasks. Cases on no program (started without
   one, or whose program was deleted) get an **Unassigned** row, only when there are
   any. Search by name; *Start onboarding* (optionally *here*, from a row, which
   pre-selects that program); *Manage programs* for those who may. A row opens level 2.
2. **`/onboarding/programs/{program}`** (and **`/onboarding/programs/unassigned`**) — the
   **people** that program is onboarding: employee, department and position, status,
   checklist progress (resolved / total), overdue tasks, start and target dates (a
   target an in-flight case has run past shows in red). Stats are the program's own.
   Search, department and status filters (every status by default), sort by employee,
   start or target date, paging. Each row's menu opens the checklist, marks it
   complete / reopens it, cancels it (confirmed) or deletes it (confirmed — and you stay
   on the program). *Start onboarding* here starts on this program. A row opens level 3.
3. **`/onboarding/{case}`** — the **case**: the employee header, a progress summary, and
   the **checklist grouped by category** (Paperwork · Equipment · Access · Orientation ·
   Training · Compliance · Other), two groups abreast on wide screens. Tick tasks done,
   assign them, set due dates, add ad-hoc tasks, edit notes/target, and complete /
   cancel / reopen the onboarding. Breadcrumbs read *Onboarding › program › person*,
   and the back arrow (and the program name in the summary) return to level 2.
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

A turn *about* a new hire is answered from a **retrieved brief** this module contributes
to before the model is called — which programme they are on, how much of the checklist is
behind them, and what is overdue by name
([ADR 0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md)).

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
  (index — the programs overview — / show / store / update / status / destroy; deleting
  returns to the case's program), `OnboardingProgramCasesController` (show / unassigned
  — one program's people), `OnboardingTaskController`
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
  `OnboardingProgramResource`; queries `OnboardingProgramsOverviewQuery` (every program
  with its case and in-flight task figures, from two grouped queries — cases per
  program, and the tasks of active cases per program — plus the Unassigned row),
  `OnboardingCasesIndexQuery` (filtered, with task counts; `paginate()` for one
  program's table, scoped by a closure, sortable by employee / start / target) and
  `OnboardingStatistics` (org-wide, or narrowed by the same scope).
- *Start onboarding* offers only people **on the roster** (`employment_status =
  active`) who have no case yet.
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

`features/onboarding/` — types, routes, constants (status & category meta), hooks
(`use-case-filters` for a program's table, `use-program-search` for the overview), and
components: stat tiles, **programs overview table**, **cases table** + **case row
actions**, progress bar, status badge,
**start-onboarding modal** (optionally pre-set to a program), **task checklist**
(grouped) + **task row** + **task form modal**, **case settings modal**, **program
card** + **program form modal** (with an inline blueprint-task editor), and a confirm
dialog. Pages: `pages/onboarding/index.tsx` (programs), `program.tsx` (a program's
people) and `case.tsx`; the programs setup screen is `pages/setup/onboarding.tsx`. The sidebar
**Talent Acquisition → Onboarding** link is gated on `onboarding.view`. The header,
tiles, toolbar, search, tables and pagination come from the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)).

All four open as **centred modals** built from the shared shell in
`components/modal.tsx` (`Modal` / `ModalContent` / `ModalHeader` / `ModalBody` /
`ModalFooter`) — height-capped, with the body as the only scrolling region so Save is
always in view. Fields go through the shared `FormField` + `FormSelect`, which wire the
label, hint and error to the control (`htmlFor` / `aria-describedby` / `aria-invalid`).
See the [recruitment module doc](recruitment.md#the-modal-shell) for the shell itself.

**The program form's blueprint editor** is the one with real work in it: each row is a
task's title, category and due offset, and the row's **position is its `sort_order`** —
so the row carries working move-up / move-down controls rather than a drag handle that
never dragged. The program `description` is editable here (it was previously sent by the
form but had no input).

## Permissions

`onboarding.view`, `onboarding.manage` (start cases, manage checklists & lifecycle),
`onboarding.manage-programs` (templates). Seeded to Super Admin / Administrator (all) and
HR Manager (all three).

## Tests

- `tests/Feature/Onboarding/OnboardingTest.php` — the programs overview (figures per
  program, the Unassigned row, search, who can be started), a program's people (only
  theirs, every status by default, status / search / department filters, sort, paging,
  scoped stats), the unassigned page, another tenant's program 404ing, deleting a case
  returning to its program, the checklist knowing its program, start
  (with seeded checklist) + the one-case-per-employee guard, case render, add/edit/delete
  task, the complete-stamp + `in_progress` nudge, overdue surfacing, complete/cancel/reopen,
  notes/target update + delete (and the lifecycle toasts), programs CRUD (with single-default enforcement), the
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
