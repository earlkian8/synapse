# 2026-06-11 — Onboarding module (template-driven hire → productive bridge)

A structured onboarding system at `/onboarding` that carries every new hire through a
checklist from day one, instantiated from reusable programs and **auto-started by the
recruitment hire bridge**. See
[ADR 0007](../decisions/0007-onboarding-template-bridge.md) and the
[module doc](../modules/onboarding.md).

## Summary

- **Programs** (templates) with optional department/employment-type targeting and a
  tenant default; blueprint tasks carry a *relative* due offset.
- **Cases** — one per employee, with a lifecycle (pending → in_progress → completed /
  cancelled), progress, start/target dates and notes.
- **Checklist** grouped by category (paperwork · equipment · access · orientation ·
  training · compliance · other): tick done, assign, set due dates, add ad-hoc tasks.
- **Overview board** of people-cards with progress bars, target dates and overdue counts.
- **The handoff**: hiring in Recruitment now seeds the new employee's onboarding from the
  best-matching active program — *apply → hire → onboard → workforce* end-to-end.

## Backend

- Migration `…_create_onboarding_tables`: `onboarding_programs`,
  `onboarding_program_tasks`, `onboarding_cases` (unique `employee_id`),
  `onboarding_tasks` — all tenant-scoped.
- Models `OnboardingProgram`, `OnboardingProgramTask`, `OnboardingCase`,
  `OnboardingTask` (`BelongsToOrganization`; cases & programs `HasHashid`); `Employee`
  gains an `onboardingCase()` hasOne.
- **`App\Support\OnboardingProvisioner`** — picks the best-matching active program
  (department + type → department → type → default) and instantiates a dated checklist;
  idempotent per employee. Called by the recruitment `HireController` (in its hire
  transaction) and the manual *Start onboarding* action.
- Controllers `OnboardingCaseController`, `OnboardingTaskController`,
  `OnboardingProgramController`; requests / resources (`OnboardingCaseResource` with a
  derived `progress`); `OnboardingCasesIndexQuery` + `OnboardingStatistics`.
- **Onboarding** permission group (`view`, `manage`, `manage-programs`) in
  `PermissionRegistry`, granted to Super Admin / Administrator / HR Manager;
  `routes/onboarding.php` wired into web.php.
- Activity logging (`logName: 'onboarding'`); assigning a task notifies the assignee.
- Factories + `OnboardingSeeder` (default program + a few in-flight cases), wired into
  `DatabaseSeeder`.

## Frontend

- `features/onboarding/` — types, routes, constants, board filter hook, and components
  (stats, toolbar, **case card**, progress bar, status badge, **start-onboarding sheet**,
  **task checklist / row / form sheet**, **case settings sheet**, **program card /
  form sheet** with an inline blueprint-task editor, confirm dialog).
- Pages `pages/onboarding/{index,case,programs}.tsx`; cases/programs addressed by hashid.
- Sidebar **Talent Acquisition → Onboarding** gated on `onboarding.view`.

## Tests

- `tests/Feature/Onboarding/OnboardingTest.php` — overview render + filter, start (+
  seeded checklist) + one-case-per-employee guard, case render, task add/edit/delete,
  complete-stamp + `in_progress` nudge, overdue surfacing, status transitions,
  notes/target update + delete, programs CRUD (single-default), the **hire bridge**,
  authorization matrix, tenant isolation.
- `tests/Unit/OnboardingTaskModelTest.php` — task/case accessors.

## Verification

`tsc`, ESLint, Pint clean; `npm run build` succeeds (onboarding chunks emitted). Unit
suite green. Migration + seeder ran against live Postgres (1 default program of 10 tasks,
5 cases); the index/case/programs controllers resolve (200), task toggle stamps + nudges
the case, and a real hire through `HireController` produced an employee **and** a 10-task
onboarding case — all verified there. (Feature suite needs `pdo_sqlite` / CI.)

## ⚠️ Migration note

Run `php artisan migrate` and re-seed roles
(`php artisan db:seed --class=RolePermissionSeeder`) so existing roles pick up the
**Onboarding** permissions. `php artisan db:seed` is idempotent and seeds a default
program + starter cases for the demo tenant.
