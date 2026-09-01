# Recruitment stops assuming an office hiring process, and the pipeline gets a board

Recruitment claimed to be generic HR-ERP software usable by any department, but it
hardcoded one six-stage office hiring process for every posting, required a résumé on
every application, and only understood "years of experience + skills" as screening
criteria. Separately, the pipeline UI itself was confusing — stage tabs hid every stage
but one, the opposite of how a Kanban ATS should read. See
[ADR 0029](../decisions/0029-configurable-recruitment-pipelines.md) for the full design
rationale.

## Pipelines are now tenant-owned data, not a constant

An organisation defines its own **pipelines** — a name and an ordered list of stages,
each with a plain-English kind (in progress / this is the Hired stage / this is a
Rejected-or-lost stage). A job posting picks one. Every place that used to pattern-match
on stage *names* — the fit scorer's recommendation, pipeline insights, the interview
scheduler, the hire bridge, the statistics, the agentic assistant's tools — now reads a
stage's **kind and position** instead, so a pipeline can be called anything ("Physical
Assessment," "Trial Shift") and everything still behaves correctly.

Existing tenants are unaffected: a migration seeds every organisation a "Standard
Hiring" pipeline with the previous six stages in the previous order, and every existing
posting and application is backfilled onto it. New organisations start with no
pipelines and a clear empty-state prompt (a "start from template" button pre-fills the
classic six stages in one click) — the same "no module defaults, honest empty state"
convention the rest of the app follows.

Manage pipelines from **Company Setup → Recruitment Pipelines** (new
`recruitment.configure-pipelines` permission, separate from the existing
`recruitment.manage-pipeline` that gates day-to-day candidate moves).

## Two opt-outs and a generic screening question

- A posting can turn off **"Require a résumé"** — the public application form makes it
  optional instead of always mandatory.
- A posting can turn off **"Rank candidates automatically"** — with it off, no fit score
  or recommendation is computed; candidates sort by applied date and the board's actions
  are plain Move / Hire / Reject.
- Postings can define their own **screening questions** (free-text, yes/no), answered on
  the public application form and visible on the candidate record — the generic
  complement to the existing minimum-experience/skills criteria for anything they don't
  cover ("Valid driver's license?", "Available for night shift?").

## The pipeline is now a Kanban board

`pipeline-board.tsx` replaces the old stage tabs as the default view: one column per
pipeline stage, left to right in the order HR defined them, all visible at once. Cards
carry a one-click **Advance** button plus the existing **Move to…** menu for anything
else. The dense table stays as a secondary view for bulk scanning. A posting's real
lifecycle (draft → open → closed/filled) — an actual, meaningful sequence, unlike a
pipeline's arbitrary stages — now gets its own small progress indicator on the postings
grid and detail modal.

## Notes

- Backend: new `recruitment_pipelines`, `recruitment_pipeline_stages`,
  `job_posting_screening_questions` tables; `job_postings` gained
  `recruitment_pipeline_id` / `requires_resume` / `use_fit_scoring`;
  `job_applications.stage` (a free string) was replaced by
  `recruitment_pipeline_stage_id` and a `screening_answers` JSON column was added.
  Roughly 26 previously stage-name-coupled call sites across 10 backend files were
  generalised to kind + position.
- `InterviewScheduler`'s default target changed deliberately: scheduling an interview
  now advances the application one open stage forward, rather than always jumping to a
  stage literally named "Interview" — a fully generic pipeline has no privileged
  interview stage.
- Frontend: `features/recruitment/` types/constants/components rewritten around
  `PipelineStage` objects instead of a hardcoded `Stage` string union; new
  `features/recruitment-pipelines/` feature folder and `pages/setup/recruitment-pipelines.tsx`.
  No drag-and-drop dependency was added — stage and screening-question ordering reuse
  Onboarding's existing chevron-reorder pattern.
- 637 tests, 636 passing. The one failure, `UserManagementTest::it_stores_an_uploaded_profile_photo`,
  is a pre-existing environment gap (the GD extension isn't installed here) unrelated to
  this change. `tsc`, ESLint, Prettier and `npm run build` are all clean.
