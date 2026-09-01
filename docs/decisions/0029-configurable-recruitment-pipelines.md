# 0029 — Configurable recruitment pipelines, optional résumé, and a Kanban rebuild

- **Status:** Accepted
- **Date:** 2026-09-01
- **Related:** [Recruitment module](../modules/recruitment.md),
  [0006 — Recruitment ATS and hire bridge](./0006-recruitment-ats-and-hire-bridge.md)
  (the original fixed-stage design this replaces),
  [0024 — Agentic recruitment & permission-scoped tools](./0024-agentic-recruitment-and-permission-scoped-tools.md)
  (the tool surface this keeps in sync),
  [0007 — Onboarding template bridge](./0007-onboarding-template-bridge.md) (the
  chevron-reorder / "replace the whole child collection on save" pattern this reuses).

## Context

Synapse is a generic HR ERP meant to serve any department, not just office/tech
hiring. Recruitment did not hold up that claim:

- **One fixed six-stage pipeline** (`applied → screening → interview → offer → hired →
  rejected`) was hardcoded into `JobApplication::STAGES` and independently copied into
  roughly 26 call sites across 10 backend files, plus a `Stage` string union and four
  `Record<Stage, …>` maps on the frontend. A warehouse packer and a software engineer
  went through identically named steps; there was no way to add "Physical Assessment"
  or "Trial Shift" for a role that actually needs one.
- **A résumé was required on every application, unconditionally** — wrong for
  driver/warehouse/security-type roles.
- **"Screening criteria" only understood years-of-experience and skill keywords** — no
  way to ask "do you have a driver's license" or "available for night shift."
- **The automatic fit score and ranking always ran**, weighted toward office-hiring
  signals, with no opt-out for a posting where it doesn't make sense.

Separately, the pipeline UI itself was confusing: stage *tabs* hid every stage but one,
over a flat table-or-card list — the opposite of the industry-standard ATS pattern
(Greenhouse, Lever, Trello), where the board's **columns are the sequence**, all visible
at once.

## Decision

**Pipelines and stages become real, organisation-owned, ordered data — not a longer
hardcoded list.** A **stage** has a name (free text), a **kind** (`open` | `won` |
`lost`), and a **position**. Every place that used to pattern-match on stage *names*
(open/terminal checks, "what's next," hiring, rejecting, stalled detection) now keys off
**kind + position** instead. That is what makes the module genuinely name-agnostic
rather than just longer: a pipeline can be called "Warehouse Hiring" with stages
"Application → Physical Assessment → Trial Shift → Offer → Hired → Rejected" and every
piece of business logic — the scorer's recommendation, the assistant's tools, the hire
bridge — behaves correctly without knowing those names exist.

A **pipeline is attached per job posting, not per department.** An organisation can make
one pipeline and use it everywhere (today's behaviour, unchanged), or make several and
assign postings to whichever fits — two postings in the same department may legitimately
want different processes. **Existing data stays intact and behaves identically by
default**: a migration seeds every existing organisation a "Standard Hiring" pipeline
with the current six stages in the current order/kind, and every existing
posting/application is backfilled onto it. New organisations start with **zero
pipelines**, consistent with this codebase's "no module defaults, honest empty state"
convention (see the Performance module changelog) — the postings page shows a clear
empty-state CTA to Company Setup, with a one-click "start from the standard template"
option.

**No drag-and-drop library added.** Onboarding's `program-form-dialog.tsx` already had
the exact pattern needed for ordering a named list without one — chevron-up/down swaps
array position client-side, order is serialised from array index on save — reused as-is
for pipeline stages and screening questions. The Kanban board's card-to-column move is a
click action (a one-click **Advance** button for the common case, plus a **Move to…**
menu for anything else), reusing the existing application-actions-menu pattern. This
keeps a real dependency, and real accessibility/mobile risk, out of scope — the
sequencing complaint was about *visibility* (columns showing the whole flow at once),
which doesn't require drag to fix.

**Fit scoring becomes an opt-out per posting** (`use_fit_scoring`, default on) rather
than a configurable formula. `ApplicantScorer` was already "config-free" — its
components silently drop out when a posting sets no criteria — so the scorer itself
needed no formula changes, just a call-site gate: off means no fit badge, no ranking, no
recommendation panel; cards sort by applied date and "Move to…" replaces the
recommendation's one-click action.

**Screening questions are a separate, generic list** (`job_posting_screening_questions`,
yes/no, ordered) rather than an extension of the existing `min_years_experience`/`skills`
columns — those stay because they're already generic enough (e.g. "2 years forklift
experience"). `requires_resume` makes the public form's résumé field conditional
(`Rule::requiredIf`) instead of always-required.

## Consequences

- Every hardcoded stage-name coupling point across the backend (scorer, pipeline
  insights, interview scheduler, hire bridge, controllers, the assistant module,
  statistics) now reads kind + position off the posting's actual pipeline. The
  assistant's `stage` parameters dropped their fixed Gemini `enum` in favour of
  fuzzy resolution against the posting's real stages — a single global enum can't be
  right once postings can run different pipelines.
- The pipeline board (`pipeline-board.tsx`) is the new default view and directly answers
  the "not properly sequential" complaint: columns, in order, all visible at once. The
  old stage tabs and the non-stage-grouped card grid are retired — both were fully
  superseded and kept neither the table's density nor the board's sequence clarity.
- `InterviewScheduler`'s default target changed from "always jump to a stage literally
  named Interview" to "advance one open stage forward" — a deliberate behaviour change:
  a fully generic pipeline has no privileged "the interview stage." Callers that need a
  specific stage still pass one explicitly.
- A new permission, `recruitment.configure-pipelines`, is separate from
  `recruitment.manage-pipeline` — a recruiter can run hiring day to day without being
  able to redesign the process, and Company Setup access doesn't imply candidate
  actions.
- Net new backend surface: `recruitment_pipelines`, `recruitment_pipeline_stages`,
  `job_posting_screening_questions` tables; `RecruitmentPipeline` / `RecruitmentPipelineStage`
  / `JobPostingScreeningQuestion` models; `RecruitmentPipelineController` under Company
  Setup. Net new frontend surface: `features/recruitment-pipelines/` and
  `pages/setup/recruitment-pipelines.tsx`.
