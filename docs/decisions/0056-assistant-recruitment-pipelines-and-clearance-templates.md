# 0056 — The assistant keeps the hiring pipelines and clearance templates, and a kept stage keeps its candidates

- **Status:** Accepted
- **Date:** 2026-09-29
- **Extends:**
  - [0029 — Configurable recruitment pipelines](./0029-configurable-recruitment-pipelines.md);
  - [0016 — Offboarding and clearance](./0016-offboarding-and-clearance.md);
  - [0053](./0053-assistant-company-profile-schedules-and-attendance-policies.md) /
    [0055](./0055-assistant-locations-leave-and-award-types-and-performance-framework.md)
    (the reach line on a held call's card).
- **Related:** [Recruitment](../modules/recruitment.md#pipelines-in-the-assistant),
  [Offboarding](../modules/offboarding.md#clearance-templates-in-the-assistant).

## Context

Two Company Setup screens were still screen-only:

- **Recruitment Pipelines**: the ordered stages a posting's board runs on;
- **Offboarding Programs**: the clearance templates an exit's checklist is seeded
  from.

Both save a whole list: every stage, or every sign-off. Reading the pipeline screen to
put it behind the assistant turned up three defects in how it saved:

- **The editor never sent a stage's id.** `syncStages()` tells a kept stage from a new
  one by its `id`, so every save dropped every stage and re-created it. A pipeline with
  a single candidate on any stage could therefore not be edited at all.
- **The refusal was a server error.** The controller caught `RuntimeException` without
  importing it. In its namespace the name resolved to a class that does not exist, so
  the catch never matched, and "stages still have candidates" reached the person as a
  500. The screen tests posted ids the real editor never sent and did not check the
  response, so both defects stayed hidden.
- **The default could be switched off.** New postings, and the assistant's
  `create_job_posting`, resolve through the default pipeline. Un-ticking it left the
  company without one.

## Decision

1. **One path per screen.**
   - `Support\Recruitment\PipelineWorkflow` (`PipelineException`) and
     `Support\Offboarding\OffboardingProgramWorkflow` hold every write.
   - The two controllers are thin callers, with the same toasts and audit lines.
   - `StoreRecruitmentPipelineRequest::rulesFor()` and `validateStages()` work without
     a route.
2. **A kept stage keeps its id, end to end.**
   - The editor carries each stage's id.
   - The request requires every id to be one of *this* pipeline's stages. An id from
     elsewhere would silently drop a stage, so it is refused.
   - The controller hands the stages on in the order they were sent. `validated()`
     rebuilds the list rule by rule and would otherwise put a new stage after every
     kept one.
   - Dropping a stage candidates sit on is refused in words, on the screen and in chat.
3. **The default pipeline is replaced, never switched off.** Making another pipeline
   the default is how it moves.
4. **Pipelines in chat** (`recruitment.configure-pipelines`, the screen's permission):
   - **Reads:** pipelines stage by stage, with the candidates on each and the postings
     on it.
   - **Creating:** a pipeline is made from its in-progress steps (with a hired stage
     and rejected stages, "Hired" and "Rejected" unless named), or by copying one.
   - **Editing:** it can be renamed or made the default.
   - **Stages:** a stage can be added (in progress or rejected), renamed or moved. **A
     stage's kind stays on the screen**: turning an in-progress stage into a rejected
     one would reclassify everybody on it.
   - **Waits for a Confirm:** removing a stage and deleting a pipeline. The card says
     how many postings run on it and whether candidates sit on the stage.
5. **Clearance templates in chat** (`offboarding.manage-programs`):
   - **Reads:** templates' sign-offs and owners, and whose exit each would seed today,
     asked of `OffboardingProvisioner::programFor()`.
   - **Creating:** a template is made from items (each owned by a department, "own", or
     nobody), by copying one, or from the built-in standard list. It is never the
     default at creation.
   - **Sign-offs:** they are added, reworded, re-owned or removed. Exits in flight keep
     their checklist.
   - **Waits for a Confirm:** changing which exits a template is for (department, exit
     type, active, default) and deleting one.
6. **Names are one per catalogue.** A pipeline or template name that differs from
   another only in case is refused, as are two stages of one name in a pipeline. Both
   are picked by name elsewhere in the assistant.

## Consequences

- **Pipelines can be edited on the screen again once candidates are on them**, and a
  stage's candidates stay on it through a rename or a reorder.
- **"Add an Assessment stage after Screening", "rename Screening to Phone screen",
  "make a Frontline pipeline: Applied, Trial shift" and "add 'Return the uniform' to
  the Retail exit checklist, owned by Operations" work in chat.**
- **Every Company Setup screen now has an assistant capability.** The shift roster's is
  the attendance capability's one-day overrides; its bulk assignments stay on the
  screen. Still screen-only: user and role management, and the analytics pages.
- **The tool budget grows again**, by the two modules' declarations. They are offered
  only to the people who configure these catalogues.

## Alternatives considered

- **Diffing the editor's list against the stages by name**, instead of sending ids. A
  rename would read as a removal plus an addition, and would be refused whenever
  candidates sat on the stage. The id is the only thing that stays the same.
- **Letting chat change a stage's kind behind a Confirm.** The card would show "kind:
  lost", not that the eleven candidates on the stage would read as rejected from then
  on. The screen shows the stage's candidates beside it.
