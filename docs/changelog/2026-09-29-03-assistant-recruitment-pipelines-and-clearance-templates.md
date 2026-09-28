# Assistant: Recruitment Pipelines and Offboarding Programs; pipelines that can be edited

The assistant gains retrieval (RAG) and function calling for the last two Company
Setup screens:
- the **recruitment pipelines**;
- the **clearance templates** (offboarding programs).

Getting there fixed the pipeline editor. It re-created every stage on every save, so a
pipeline with any candidates on it could not be edited, and the refusal arrived as a
server error. See
[ADR 0056](../decisions/0056-assistant-recruitment-pipelines-and-clearance-templates.md).

## Highlights

- **Pipelines can be edited again once candidates are on them**, and a stage's
  candidates stay on it through a rename or a reorder.
- **"Add an Assessment stage after Screening", "make a Frontline pipeline: Applied,
  Trial shift", "add 'Return the uniform' to the Retail exit checklist, owned by
  Operations"** work in chat.
- **Removing a stage, deleting a pipeline, re-targeting a clearance template and
  deleting one** wait for a Confirm that says what runs on it, or whose exit it would
  seed.
- **Every Company Setup screen now has an assistant capability.**

## Fixes on the screens

- **The pipeline editor sends each stage's id.** It re-created every stage on each save,
  and a pipeline with candidates on any stage could not be saved at all.
- **Only this pipeline's stages are accepted.** The request refuses a stage id that
  belongs to another pipeline, which would have dropped that stage silently.
- **Stages save in the order they were sent.** `validated()` would otherwise have put
  a new stage after every kept one.
- **"Stages still have candidates" is a toast, not a 500.** The controller caught an
  un-imported `RuntimeException`, which never matched.
- **The default pipeline cannot be switched off.** Make another one the default
  instead; new postings resolve through it.

## Backend

- **`RecruitmentPipelinesModule`** (`recruitment.configure-pipelines`):
  - reads: `find_pipelines`, `get_pipeline`;
  - writes: `create_pipeline`, `update_pipeline`, `set_pipeline_stage`;
  - confirmed: `remove_pipeline_stage`, `delete_pipeline`;
  - retrieval: every pipeline's flow.
- **`OffboardingProgramsModule`** (`offboarding.manage-programs`):
  - reads: `find_clearance_templates`, `get_clearance_template`;
  - writes: `create_clearance_template`, `set_clearance_template_item`,
    `remove_clearance_template_item`;
  - confirmed: `update_clearance_template`, `delete_clearance_template`;
  - retrieval: the templates and how one is chosen.
- **Shared workflows:**
  - `Support\Recruitment\PipelineWorkflow` (`PipelineException`) and
    `Support\Offboarding\OffboardingProgramWorkflow`;
  - `RecruitmentPipelineController` and `OffboardingProgramController` are thin
    callers.
- **`StoreRecruitmentPipelineRequest::rulesFor()` and `validateStages()`** work
  without a route.

## Frontend

- **The pipeline editor** carries each stage's `id` through to the save.
- **The chat button** also appears for `recruitment.configure-pipelines` and
  `offboarding.manage-programs`.
- **New suggestions:** "What stages do our hiring pipelines have?" and "What is on our
  exit clearance checklist?".

## Verification

- **Pest:** the full suite passes (1333 tests: the previous 1315 plus 18 new).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database:
  - **Light:** the seeded default pipeline, with candidates on every stage, was edited
    in its real editor. A stage was renamed, "Pipeline updated." toasted, and the
    renamed stage kept its 4 candidates. This save used to be a server error.
  - **Dark:** an `update_clearance_template` was held through the real assistant. Its
    card read "It would seed the exit of 119 people leaving by resignation today; 3 exits
    in flight came from it…". Confirming applied it, toasted, and it was audited via
    assistant.
  - No console errors.

## Tests

- `Recruitment/RecruitmentPipelinesAssistantTest` (7)
- `Offboarding/OffboardingProgramsAssistantTest` (6)
- `Offboarding/OffboardingProgramsTest` (2): the template screen, which had no write
  tests.
- `Recruitment/RecruitmentPipelinesTest` (+3, and one strengthened):
  - the editor's own payload keeps stages and their candidates;
  - a stage from another pipeline is refused;
  - the default is not switched off;
  - the stage-with-candidates refusal is now asserted as a toast.
