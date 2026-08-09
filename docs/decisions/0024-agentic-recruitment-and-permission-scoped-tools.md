# 0024 — Agentic recruitment: a full tool surface, scoped by permission

- **Status:** Accepted
- **Date:** 2026-08-10
- **Related:** [Recruitment module](../modules/recruitment.md),
  [0006 — Recruitment ATS & the hire bridge](./0006-recruitment-ats-and-hire-bridge.md)
  (the pipeline, the stages, and `ApplicantHirer`),
  [0002 — RBAC authorization](./0002-rbac-authorization.md) (the permission
  catalogue every tool is gated on),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md) (every query the agent runs is
  tenant-scoped by the global scope, not by the agent).

## Context

The assistant's module registry (orchestrator + `AssistantModule` implementations)
already covered recruitment, but only thinly: **eight** tools that could list
postings and applications, open a vacancy, add a candidate, move/reject them,
book an interview and hire. Everything else a recruiter does on the board — edit
a posting, publish or close it, curate the candidate pool, rate an application,
withdraw a card, reschedule an interview, record its outcome, read the fit
ranking — had no agentic equivalent. A user could ask for those things and the
model, having no tool, would either decline or (worse) narrate an action it had
not taken.

Widening the surface exposed a second problem. The registry gated modules
**wholesale**: `isAvailable()` asked for `recruitment.view`, and every tool in the
module was then advertised to that user. With eight tools that was tolerable —
the handler's own permission check turned a disallowed call into a polite
refusal. With twenty-five it is not: a view-only user would be offered seventeen
actions they cannot perform, spending prompt tokens on them and inviting the
model to pick one and fail. Section 3 of our implementation method already said
modules should expose *only* what the signed-in user is permitted to use; the
code had never actually done it.

## Decision

**1. The recruitment tool surface mirrors the board, not a subset of it.**
`RecruitmentModule` advertises **25** tools across five groups — vacancies
(find / create / update / set status / delete), the candidate pool (find / add /
update / delete), the pipeline (find / add / move / advance / update / reject /
withdraw / hire), interviews (find / schedule / update / cancel) and decision
support (summary / rank / profile / AI read). The rule of thumb: if a recruiter
can do it on `/recruitment`, the agent can do it, with the same validation, the
same activity log and the same notifications.

**2. Two tools are deliberately *not* symmetric with the UI.**

- **`advance_application`** takes whatever `ApplicantScorer` recommends as the
  candidate's next step — but only when that step is a forward move. When the
  recommendation is *reject* or *hire*, the agent reports the recommendation and
  stops. Negative and irreversible outcomes stay explicit human decisions; an
  agent must never arrive at them by "just following the recommendation".
- **`candidate_insights`** costs a Gemini call inside a turn that already spent
  one, so it returns the **saved** read unless the user asks to refresh. This is
  the same bargain the candidate drawer makes, and it keeps the common case at
  one request per turn (our standing cost discipline).

**3. `tools()` and `guidance()` take the `User`.** The `AssistantModule` contract
now receives the signed-in user, so a module can advertise a permission-shaped
surface and describe only the capabilities that user actually has. `Module`
carries the shared plumbing: a `permissionMap()` of tool → ability and a
`permitted()` filter. **This narrows what is *offered*; it does not replace what
is *enforced*** — every handler still re-checks its own permission, because the
model's choice of tool is never a security boundary. A view-only recruiter is now
offered 8 tools; a full recruiter 25.

**4. Shared operations get extracted rather than mirrored.** Where the board and
the agent do the same thing, they now call the same code:
`JobApplication::moveTo()` / `rejectWith()` (stage transitions, with the
decision fields cleared consistently), `JobApplication::OPEN_STAGES` /
`TERMINAL_STAGES` / `STALL_DAYS` and the `open()` / `stalled()` scopes,
`InterviewScheduler::book()` (create the interview **and** pull an early-stage
candidate into `interview`), and `ApplicantDocumentStore::purge()` (never orphan
an applicant's uploads). `ApplicantHirer`, `ApplicantScorer`, `PipelineInsights`
and `ApplicantInsights` were already canonical and are reused as-is.

**5. Read-outs are a first-class card kind.** Summaries, rankings and AI reads
are not mutations and shouldn't be narrated as "we changed this", so cards gained
an `insight` kind: the orchestrator's local synthesizer describes them with their
metrics, and the chat renders those metrics as a chip row. This keeps decision
support answerable **without** a second model round-trip.

## Consequences

- The agent can run a hiring pipeline end to end, in the user's own words, and
  every step lands in `activity_logs` tagged "via assistant".
- Prompt size scales with the user's role rather than with the module's ambition;
  a view-only user's recruitment prompt shrank by roughly two thirds.
- Four other modules' `tools()` / `guidance()` signatures changed mechanically.
  They ignore the user for now; each can adopt `permissionMap()` when its surface
  grows enough to warrant it.
- `set_posting_status` enforces the closing-date rule that only the *form* used
  to enforce, so the agent cannot publish a deadline-less vacancy the careers
  page then has to reason about. This is intentionally stricter than the existing
  status endpoint.
- Twenty-five declarations is close to the point where a flat tool list stops
  helping the model. If a sixth capability area lands, the next move is grouping
  or a router tool — not another ten declarations.
