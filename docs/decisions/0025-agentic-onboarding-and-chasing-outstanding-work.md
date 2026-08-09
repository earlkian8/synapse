# 0025 — Agentic onboarding, and letting the agent chase outstanding work

- **Status:** Accepted
- **Date:** 2026-08-10
- **Related:** [Onboarding module](../modules/onboarding.md),
  [0007 — Onboarding template bridge](./0007-onboarding-template-bridge.md)
  (programs, cases, and `OnboardingProvisioner`),
  [0024 — Agentic recruitment & permission-scoped tools](./0024-agentic-recruitment-and-permission-scoped-tools.md)
  (establishes `tools(User)` / `guidance(User)`, `permissionMap()`, the `insight`
  card kind, and the extract-don't-mirror rule this follows),
  [0003 — Notification channels](./0003-notification-channels.md) (what a nudge
  actually sends).

## Context

Onboarding is where work quietly rots. A case is seeded at hire time and then
depends on a dozen small acts by several different people — issue the laptop,
countersign the contract, book the orientation — each with a due date and an
owner. The board shows all of it, but somebody has to go and look.

The assistant's onboarding capability was three tools: list cases, start one,
and complete/cancel/reopen it. It could not touch a **task** — the unit the whole
module is actually made of — so the most natural things to ask ("what's overdue?",
"tick off Maria's contract", "reassign the laptop task to Ben") had no tool
behind them. It also could not see or edit the **programs** those checklists come
from, and it had no read-out: asking "how is onboarding going?" produced a list
of cards, not an answer.

## Decision

**1. The tool surface is the whole module.** `OnboardingModule` advertises **16**
tools in four groups — cases (find / start / retarget / lifecycle / delete), the
checklist (find / add / edit / set status / remove / nudge), programs (find /
create / update / delete) and one read-out (`onboarding_summary`). Same rule as
ADR 0024: if a coordinator can do it on `/onboarding` or `/setup/onboarding`, the
agent can do it, with the same validation, activity log and notifications.

**2. Chasing people is a tool, not a side effect.** `nudge_onboarding_task` is
the one genuinely new capability — the board has no "remind" button. It exists
because "who is sitting on Maria's onboarding, and can you poke them?" is the
question this module is asked most, and because the infrastructure was already
there (`Notifier`, and the assignment notification the checklist already sends).
Three guards make it safe to hand to a model:

- **Grouped per person.** `OnboardingTaskNotifier::nudgeMany()` sends one message
  listing someone's open items rather than one per item, so chasing a whole case
  cannot turn into a burst of pings.
- **Nothing pointless.** An unassigned task has nobody to chase and a resolved
  one has nothing to chase about; both are refused with an explanation rather
  than silently no-op'd.
- **Explicit intent.** The guidance states that it really sends notifications and
  is only for a clear request — the same treatment hiring and archiving get.

**3. Completing a case with work left is allowed, and said out loud.** HR
legitimately closes onboarding early. Rather than refuse (wrong) or say nothing
(worse), `set_onboarding_status` reports the outstanding count in its reply *and*
writes it into the activity log. The agent's job here is to be candid, not to
police.

**4. The read-out stays deterministic — no second model call.** Recruitment has
`candidate_insights` because `ApplicantInsights` already existed to read
documents. Onboarding has no equivalent artefact to read: its questions are
answered by counts, dates and an ordering ("1 of 3 done, 1 overdue, target in
10d, next: Issue laptop (Ben Cruz)"). Spending a Gemini call to phrase numbers we
already hold is exactly what the cost discipline forbids — the assistant is
itself the language model, and it narrates the `insight` card. **We deliberately
did not add an `OnboardingInsights` class.**

**5. State transitions moved onto the models.** The controller and the assistant
had begun to keep two copies of the same `match` statement. Now:
`OnboardingCase::applyLifecycle()` (the only place `completed_at` is stamped or
cleared), `touchProgress()` (the pending → in_progress nudge), `progressSummary()`
(one definition of "how far along", which `OnboardingCaseResource` renders too),
`OnboardingTask::markStatus()` (completion stamping), plus the
`ACTIVE_STATUSES` / `RESOLVED_STATUSES` / `LIFECYCLE_ACTIONS` vocabulary and the
`active()` / `unresolved()` / `overdue()` / `onActiveCase()` / `search()` scopes.
`OnboardingProgram` gained `syncBlueprint()` and `enforceSingleDefault()`; both
program writers now go through them. `OnboardingTaskNotifier` owns every ping.

**6. Two behaviours that only make sense for an agent.** A checklist can hold two
items with the same title, so `task` resolution prefers the **unresolved** one —
"mark the orientation done" means the open one. And `update_onboarding_program`
touches the blueprint **only when `tasks` is supplied**; the setup form always
sends the full list, but a model editing one field must not silently empty the
template.

## Consequences

- The agent can run onboarding end to end and, crucially, tell you what has
  slipped and get the right people moving — the module's actual job.
- The permission split is visible in the tool list: view-only 4 tools, plus
  `onboarding.manage` 13, plus `onboarding.manage-programs` all 16.
- `nudge_onboarding_task` is the assistant's first *outbound* action (it reaches
  people outside the chat). If more land, they should share this shape: grouped,
  guarded, and explicit in guidance.
- Two capability modules now carry a `permissionMap()`. The remaining three
  (employees, leave, attendance) still expose their whole surface at module
  granularity; that is fine while they are 2–4 tools each.
