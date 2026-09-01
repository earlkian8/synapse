# Onboarding: the full agentic tool surface, and chasing outstanding work

The assistant can now run onboarding, not just start and stop it. `OnboardingModule`
grows from **3 tools to 16** — covering cases, the checklist that cases are actually
made of, the programs they are seeded from, and a read-out of how it is all going —
and gains the one thing the board never had: the ability to **chase the people sitting
on outstanding work**. The *why* is in
[ADR 0025](../decisions/0025-agentic-onboarding-and-chasing-outstanding-work.md); the
*how* is in the updated [onboarding module doc](../modules/onboarding.md).

## Highlights

- **The checklist is reachable at last.** Find tasks by employee, assignee, category,
  status, `overdue`, a due window, or `mine`; add ad-hoc items; retitle, recategorise,
  reassign or re-date them; tick them off (or `skipped`); remove them. Ticking one off
  stamps who finished it and nudges a `pending` case into `in_progress`, exactly as the
  board does.
- **"Who's sitting on Maria's onboarding — poke them."** `nudge_onboarding_task`
  reminds whoever owns outstanding work: one named item, or everything overdue on a
  checklist. Reminders are **grouped per person** (four open items → one message, not
  four pings), and it refuses to chase an unassigned or already-finished task.
- **Cases, fully.** Start one from a named or best-matching program (with an optional
  start date), retarget it, add notes, complete / cancel / reopen, or delete it.
  Completing with work left is allowed — HR does close cases early — but the reply and
  the activity log say **how many tasks were left unresolved**.
- **Programs too.** List them, create or edit one with its full blueprint, delete one —
  gated on `onboarding.manage-programs`, separately from managing checklists. Editing
  touches the blueprint **only when a task list is supplied**, so changing one field
  can't silently empty a template.
- **"Which checklist would this hire get?"** `find_onboarding_programs` with
  `for_employee` answers it through `OnboardingProvisioner::programFor()` — the same
  resolver the hire bridge uses, so the preview can't disagree with reality.
- **No second model call.** `onboarding_summary` reads out the org-wide picture or one
  employee's progress (done / overdue / target countdown / next task up) straight from
  the database. We deliberately did **not** add an LLM insight class here — the
  questions onboarding gets are answered by counts and dates.

## Backend

- **`App\Services\Assistant\Modules\OnboardingModule`** — rewritten: 16 declarations in
  four groups, a `permissionMap()` (view-only 4 tools, `+manage` 13, `+manage-programs`
  16), per-tool permission checks, richer cards (progress, overdue count, target date,
  category, owner, due state) and a shared activity-log helper tagging every mutation
  "via assistant".
- **State transitions moved onto the models** — `OnboardingCase::applyLifecycle()`,
  `touchProgress()`, `progressSummary()`, `ACTIVE_STATUSES` / `LIFECYCLE_ACTIONS` and
  the `active()` scope; `OnboardingTask::markStatus()`, `RESOLVED_STATUSES` and the
  `unresolved()` / `overdue()` / `onActiveCase()` / `search()` scopes;
  `OnboardingProgram::syncBlueprint()` + `enforceSingleDefault()` and its `search()`
  scope. `OnboardingCaseController`, `OnboardingTaskController`,
  `OnboardingProgramController`, `OnboardingCaseResource`, `OnboardingStatistics` and
  `OnboardingCasesIndexQuery` were all moved onto them — the duplicated `match`
  statements and the hand-rolled progress maths are gone.
- **`App\Support\OnboardingTaskNotifier`** — the one place a checklist task talks to its
  owner: `assigned()` (only on a real assignee change), `nudge()` (one item) and
  `nudgeMany()` (grouped per person). Extracted from `OnboardingTaskController`.

## Frontend

- **`features/assistant`** — new `remind` card kind (BellRing icon) for "these people
  were chased", alongside the `insight` kind the read-outs use.

## Notes

- No migrations, no route changes, no new permissions — the agent reuses the three
  onboarding abilities already in the registry.
- `nudge_onboarding_task` is the assistant's first **outbound** action: it sends real
  notifications (database + the recipient's opted-in mail / push), so the guidance
  marks it as clear-request-only, the same treatment hiring gets.
- The module doc's programs surface was stale — it is `/setup/onboarding`
  (`setup.onboarding.*`), not `/onboarding/programs`. Corrected.
- Verified: `php -l` and **Pint** clean on every changed file; **`tsc`**, **ESLint**,
  **Prettier** and **`npm run build`** green; tinker-smoke-tested the tool list and all
  three read-outs against real seeded data.
- Pest: the new `OnboardingAssistantTest.php` adds **36 passing tests / 142 assertions**;
  Onboarding + Recruitment + Unit are 178/178 green. The full suite is 381/396 — the 15
  failures (auth redirects, employee numbering, tenancy registration, department
  restore, push subscriptions) are **pre-existing on this branch**, unchanged in count
  and identity from before this work, and none touch onboarding or the assistant.
