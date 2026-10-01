# Recruitment: the full agentic tool surface

The assistant can now run recruitment the way a recruiter does. `RecruitmentModule`
grows from **8 tools to 25** — covering every action on the board plus the decision
support behind it — and the assistant registry learns to advertise tools **per user
permission** rather than per module. The *why* is in
[ADR 0024](../decisions/0024-agentic-recruitment-and-permission-scoped-tools.md); the
*how* is in the updated [recruitment module doc](../modules/recruitment.md).

## Highlights

- **Everything the board can do.** Vacancies (create, edit, publish/close/fill, delete),
  the candidate pool (find, add, update, remove), the pipeline (add, move, advance, rate,
  reject, withdraw, hire) and the interview calendar (find, schedule, reschedule, record
  the outcome, cancel) are all reachable in conversation, with the same validation,
  activity logging and notifications as the UI.
- **Decision support, spoken.** `recruitment_summary` reads out how hiring is going —
  org-wide, or one pipeline's average fit, strong candidates, ready-to-advance, stalled
  cards and standout. `rank_candidates` returns the fit shortlist; `candidate_profile`
  gives one candidate's fit breakdown, rank, rating, interview verdict and next step.
  All three are pure database reads — **no model call**.
- **It knows when to stop.** `advance_application` takes the scorer's recommended next
  step, but when that recommendation is *reject* or *hire* it reports it and stops:
  negative and irreversible outcomes stay explicit human decisions. `move_application`
  can reinstate a rejected candidate; it will never un-hire one.
- **Tools are permission-shaped.** A view-only recruiter is offered **8** tools, a full
  recruiter **25**. The model is no longer handed actions it can only fail at, and the
  prompt shrinks accordingly. Handlers still re-check their own permission.
- **One model call stays the norm.** `candidate_insights` — the only tool that spends a
  Gemini call — returns the saved `ai_insights` read unless a refresh is asked for.

## Backend

- **`App\Services\Assistant\Modules\RecruitmentModule`** — rewritten: 25 declarations in
  five groups, a `permissionMap()`, per-tool permission checks, richer cards (closing
  countdown, rating, fit band, interview time) and a shared activity-log helper that tags
  every mutation "via assistant".
- **`AssistantModule::tools(User)` / `guidance(User)`** — the contract now receives the
  signed-in user; `Assistant` passes it through when collecting tools and building the
  system instruction. The other four modules take the parameter and ignore it for now.
- **`Module`** gains `permissionMap()`, `permitted()` and `allows()` — the shared
  permission-scoping plumbing for any module whose surface grows.
- **`Assistant::synthesize()`** learns the `insight` card kind, so read-outs are narrated
  with their metrics instead of as "we changed this" — decision support answers without a
  second round-trip.
- **Shared logic extracted, not mirrored:** `JobApplication::OPEN_STAGES` /
  `TERMINAL_STAGES` / `STALL_DAYS`, the `open()` / `stalled()` scopes and `moveTo()` /
  `rejectWith()`; **`App\Support\Recruitment\InterviewScheduler::book()`** as the one
  booking path; `ApplicantDocumentStore::purge()` / `forgetResume()` for file cleanup.
  `JobApplicationController`, `InterviewController`, `ApplicantController`,
  `JobPostingController`, `JobPostingsIndexQuery`, `RecruitmentStatistics` and
  `PipelineInsights` were all moved onto them.

## Frontend

- **`features/assistant`** — new `insight` card kind (Sparkles icon); a read-out's extra
  metrics render as a chip row under the card instead of being collapsed into the
  subtitle line.

## Notes

- No migrations, no route changes, no new permissions — the agent reuses the eight
  recruitment abilities already in the registry.
- `set_posting_status` refuses to publish a posting with no closing date, enforcing in the
  agent the rule the create/edit form already enforced (the bare status endpoint does not).
  Intentionally stricter; noted in the ADR.
- Verified: `php -l` and **Pint** clean on every changed file; **`tsc`**, **ESLint**,
  **Prettier** and **`npm run build`** green; tinker smoke-tested the tool list, the
  guidance string and the three read-outs against real seeded data.
- Pest: the new `RecruitmentAssistantTest.php` adds **45 passing tests / 159 assertions**;
  the whole Recruitment + Unit suite is 122/122 green. The full suite is 345/360 — the 15
  failures (auth redirects, employee numbering, tenancy registration, department restore,
  push subscriptions) are **pre-existing on this branch** and reproduce identically in
  isolation; none touch recruitment or the assistant.
