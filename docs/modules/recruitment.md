# Recruitment

An applicant tracking system (ATS): post vacancies, move candidates through a hiring
pipeline, schedule interviews, and **hire** — which creates an employee. The *why* is
in [ADR 0006](../decisions/0006-recruitment-ats-and-hire-bridge.md); how pipelines
became tenant-defined data is [ADR 0029](../decisions/0029-configurable-recruitment-pipelines.md);
a posting's pay and place, and interviewing without the board, are
[ADR 0091](../decisions/0091-postings-say-what-they-pay-and-anyone-assigned-can-interview.md);
reading résumés and matching skills by meaning is
[ADR 0097](../decisions/0097-the-ats-reads-the-resume-and-matches-skills-by-meaning.md).
This is the *how*. Everything is tenant-scoped (ADR 0005).

Recruitment is deliberately **generic** — a SaaS HR ERP is used by every department, not
just office hiring. Nothing in the module hardcodes what a hiring process looks like: an
organisation defines its own **pipelines** (named, ordered stages), a posting can make
the résumé optional and turn off automatic ranking, and screening questions are free-form
yes/no fields instead of a fixed "years of experience + skills" formula.

## Where it sits in the life cycle

```
Applicant ──apply──▶ Application ──pipeline──▶ Offer ──hire──▶ Employee
   └────────── Recruitment (this module) ──────────┘   └─ Workforce ┘
```

Recruitment is the **front door** to the workforce: most employees should arrive
through a hire here. The manual "New employee" form remains an escape hatch (data
migration, bypass hires), not the default path.

## Surfaces

Both levels are laid out like the Workforce modules (header · compact stat tiles ·
toolbar · table · pagination), from the shared table kit
([ADR 0048](../decisions/0048-talent-acquisition-and-offboarding-join-the-table-kit.md)).

The module header switches between **My interviews** (with the count still to record)
and **Job postings**, each shown only to people who may open it (`RecruitmentNav`).

- **`/recruitment/interviews`** (`recruitment.interview`): **My interviews**, the
  interviews the person is assigned (ADR 0091).
  - *To record* (pending, soonest first) and *Recorded* (the latest 50).
  - An interview opens a modal: time, mode and join link; the candidate's experience,
    location, résumé, LinkedIn, portfolio, email and phone; their cover note and
    answers to the posting's questions; then the verdict (*Not yet · Passed · Not a
    fit*) and feedback.
  - It leaves out expected salary, HR's rating and notes, the fit score and the
    candidate's other documents.
  - Someone who can interview but not view the board is sent here from `/recruitment`.
- **`/recruitment`**: every job posting as **one table**.
  - Six stat tiles; search, department and status filters with Reset; *Export* (with
    the current filters) and *New posting*.
  - Columns: posting and linked position, department, type, openings (hired / seats),
    pipeline (active · total), status and closing date.
  - It sorts by title, openings, status and closing date, and is paged on the server.
  - A row opens the posting's **pipeline**. The row menu has *View details* (a read-only
    modal with the overview (including work arrangement and pay), public application
    link, description and requirements,
    pipeline summary and a **status progress** indicator, plus *Open pipeline* and
    *Edit*), *Open pipeline*, *Copy public link*, *Edit*, *Set status* and *Delete*.
- **`/recruitment/{posting}`**: the **pipeline**.
  - The header shows the posting, its status, department, type, pipeline name, hired /
    openings, its **closing-date countdown** and its screening criteria, with *Add
    candidate*.
  - **Decision support** comes next: the focused stage's counts as stat tiles, and one
    line on what to do next.
  - The toolbar has candidate search, a **stage** filter with a count per stage, a sort
    (*Best match* keeps the fit ranking), Reset, a **Table / Board** switch
    (remembered per browser; **the table is the default**) and *Export*.
  - The **table** lists candidates with fit, stage, rating, interviews and days since
    applying, paged 25 at a time. A row opens the application, and the menu has
    *Move to…*, *Hire* and *Reject*.
  - The **board** (the Kanban view) has one column per stage, in the order HR defined
    them. It always shows every column, and the stage filter only focuses the decision
    support. Cards have a one-click **Advance** and the same menu.
  - Candidates are **automatically ranked by a fit score** by default (see below), and a
    posting can turn this off. You can add candidates, move them, schedule interviews,
    reject and hire.
- **`/setup/recruitment-pipelines`** (Company Setup, gated
  `recruitment.configure-pipelines`) — define the hiring processes postings can run on:
  a name, a default flag, and an ordered stage list (chevron up/down reorder, same
  click-based pattern as Onboarding's checklist — no drag-and-drop dependency). Each
  stage picks its **kind** via a plain-English choice ("In progress" / "This is the
  Hired stage" / "This is a Rejected/lost stage") — the open/won/lost vocabulary never
  reaches the UI. A "Start from template" button pre-fills the classic 6-stage process.
  Deleting a stage that still has applications, or a pipeline still assigned to a
  posting, is refused.
  - **A kept stage keeps its id**, and so its candidates: the editor sends each stage's
    `id`, and the request refuses an id that is not one of this pipeline's stages.
  - **The default is replaced, not switched off.** Making another pipeline the default
    is how it moves; new postings resolve through it.
  - Every write goes through **`App\Support\Recruitment\PipelineWorkflow`**
    (`PipelineException`), which the assistant uses too
    ([ADR 0056](../decisions/0056-assistant-recruitment-pipelines-and-clearance-templates.md)).

## Automatic ranking & decision support

`App\Support\Recruitment\ApplicantScorer` is the single source of truth for the
recruitment **fit score** and the decision support it powers. It is pure, deterministic
math: given an application (and its posting's optional criteria) it returns a 0–100 score
with a transparent breakdown and the **recommended next step** for HR. Controllers reuse
it rather than re-deriving the formula.

- **Components** (max points, normalised over whatever applies): recruiter rating (30),
  experience vs the posting's minimum (25), skill match (20), interview
  outcome (15), document completeness (10). When a posting sets no minimum experience or
  no skills, those components simply don't apply and the score normalises over the rest —
  so ranking works the moment a candidate applies and sharpens as recruiters rate,
  interview, and set criteria. Bands: `strong`/`promising`/`fair`/`weak`.
- **Position-aware criteria.** A posting carries optional `min_years_experience`,
  **must-have** `skills` and **nice-to-have** `preferred_skills` (matched by meaning
  against the résumé and profile; see below), and free-form **screening questions**
  answered yes/no on the application — the generic complement for anything
  experience/skills don't cover ("Valid driver's license?", "Available for night
  shift?").
  A posting can turn off automatic ranking entirely (`use_fit_scoring`); when off, no
  fit badge or recommendation is shown and candidates sort by applied date.
- **Recommendation.** Derived from the candidate's stage **kind + position in the
  posting's own pipeline** plus fit and interview verdict — never a hardcoded stage
  name, so it reads correctly for any custom pipeline: *advance* (to the specific next
  open stage, named), *hire* (last open stage + strong fit), *reject* (weak fit / failed
  interview), or nothing yet. Surfaced in the candidate modal's **decision panel** with
  a one-click action that performs it. The modal is the **full candidate profile**:
  contact, profile links, all documents, the fit breakdown, a **stage stepper** built
  from the posting's real pipeline, interview history, and the candidate's **other
  applications across postings**.

## Reading résumés & matching skills

The [ADR 0097](../decisions/0097-the-ats-reads-the-resume-and-matches-skills-by-meaning.md)
fixes the usual ATS failures: a candidate who wrote "TypeScript" is no longer missed for
"JavaScript", "Java" is no longer found in "JavaScript", and the résumé itself is read.

- **Reading files.** `App\Support\Files\DocumentText` reads a PDF (`pdftotext`), a
  scanned PDF or a photo (`pdftoppm` + `tesseract` OCR), a .docx (its XML) and a .doc
  (`antiword`/`catdoc`, or the text runs it stores). The text goes on the candidate:
  `resume_text` with `resume_read_status` (`pending` · `read` · `unreadable`), each
  document's `extracted_text`, and all the documents together as `documents_text`.
  Government IDs are never read (`skipped`). The columns are hidden from JSON.
- **When.** The `Applicant` and `ApplicantDocument` models mark a new or changed file
  `pending` and dispatch `App\Jobs\ReadApplicantFiles` **after the response**, so every
  way in is covered: careers page, recruiter forms, assistant. The hourly
  **`recruitment:read-files`** reads what is still waiting, in batches of 100
  (`--again` re-reads everything).
- **Matching** (`App\Support\Recruitment\Skills`):
  - `catalogue.php` holds about 400 skills with their aliases, the skills each one
    proves (`implies`: TypeScript → JavaScript, Laravel → PHP), the parts some are made
    of (Full-stack, MERN, Microsoft Office), spellings that only count when written a
    certain way (`cased` "Excel", `listed` "R", "Go"), and families of related skills
    with partial credit.
  - `Words` folds every text the same way.
  - `SkillText` matches whole words only.
  - `CandidateEvidence` searches résumé → headline → cover note → documents → notes.
  - `SkillMatcher` gives each skill a status, best first: `found`, `implied` (full
    credit), `parts`, `spelling`, `related` (partial: JavaScript for TypeScript 0.4,
    Vue for React 0.5), then `missing`. A skill the catalogue doesn't know is matched by
    its words in any order and form. HR may write "React or Vue".
- **Weighting.** A must-have counts twice a nice-to-have. Nothing knocks a candidate out.
- **Experience.** With `years_experience` blank, `App\Support\Recruitment\ResumeExperience`
  adds up the résumé's job date ranges (overlaps once, education left out), or takes the
  candidate's own "7+ years of experience". It is shown as an estimate ("≈6 yrs from
  résumé").
- **Explained.** The fit breakdown's `skills` item lists each skill's tier, status,
  `via`, `source` and credit, plus `resume` (read / pending / unreadable / none). The
  candidate modal shows it ("JavaScript — shown by TypeScript · résumé"). The pipeline
  export has *Skills shown* / *Skills missing*. The assistant's `candidate_profile`
  names the missing must-haves.
- **The posting form** suggests catalogue skills while HR types
  (`GET /recruitment/skills`, `recruitment.view`) and marks the ones it recognises, with
  what else counts for each in the tooltip.

## AI candidate insights

On top of the deterministic fit score, HR can ask an LLM to **read a specific
candidate** and return decision support grounded in their actual documents.
`App\Support\Recruitment\ApplicantInsights` (mirroring the Reports module's
`ReportInsights`) compiles a digest — the candidate's profile, the role and its
criteria, the rule-based fit breakdown, ratings and interview history — **and
attaches the real files** (résumé + supporting documents) to Gemini so the model
reads them. `gemini-2.5-flash` ingests PDFs and images natively. A Word résumé can't be
attached, so its text, already read for the fit score, goes in the digest. Other office
files are named but not uploaded. The digest also lists each skill's reading for the
model to check.

- **Privacy.** Government-ID documents are **never** sent to the model, and only
  model-readable types (PDF, PNG/JPG/WebP) are attached, bounded by a total size
  budget. The model returns strict JSON: a headline verdict, summary, strengths,
  concerns, what the documents reveal, sharp interview questions, and a
  recommendation.
- **On demand + persisted.** Insights are generated from the candidate modal's
  **AI Insights** panel (`POST /recruitment/applications/{application}/insights`,
  gated `recruitment.view`) and **saved on the application** (`ai_insights` JSON
  column) so reopening the modal shows the last read instantly without spending
  another model call; a **Regenerate** button re-runs it. Generation is
  activity-logged. Degrades gracefully (retryable) when the key is missing or the
  service is rate-limited/overloaded — exactly like the Reports insights.

## The agentic assistant

Everything a recruiter can do on the board, the **Synapse assistant** can do in
conversation, and a chat turn *about* a candidate is answered from a **retrieved brief**
the module contributes to before the model is called — what they applied for, how far
each application got, and what the interviews said
([ADR 0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md)). For somebody
who already works here it contributes one line instead: the vacancy they were hired
into. `App\Services\Assistant\Modules\RecruitmentModule` is the largest
capability in the assistant registry — **25 Gemini function declarations** — and the
*why* is in [ADR 0024](../decisions/0024-agentic-recruitment-and-permission-scoped-tools.md).
The model only *decides*; the module *enforces* (permission, validation, tenancy,
activity log, notifications), reusing the same support classes the controllers do.

| Group | Tools |
| --- | --- |
| Vacancies | `find_job_postings` (text / status / department / closing window), `create_job_posting`, `update_job_posting`, `set_posting_status`, `delete_job_posting` |
| Candidate pool | `find_applicants`, `add_applicant`, `update_applicant`, `delete_applicant` |
| Pipeline | `find_applications` (candidate / posting / stage / **stalled**), `add_application`, `move_application`, `advance_application`, `update_application` (rating, ask, note), `reject_application`, `withdraw_application`, `hire_applicant` |
| Interviews | `find_interviews` (upcoming / past / today / all), `schedule_interview`, `update_interview` (reschedule **or** record the outcome), `cancel_interview` |
| Decision support | `recruitment_summary`, `rank_candidates`, `candidate_profile`, `candidate_insights` |

- **Permission-scoped tool surface.** `tools($user)` and `guidance($user)` take the
  signed-in user, so the model is only offered actions that user's role allows — a
  view-only recruiter sees 8 tools, a full recruiter 25. Each handler still re-checks
  its own permission: the filter narrows what is *offered*, not what is *enforced*.
  `Module::permissionMap()` + `Module::permitted()` are the shared plumbing.
- **Pipelines are no longer a fixed vocabulary.** `guidance()` describes the
  organisation's actual default pipeline stages (queried, not a constant), and the
  `stage` parameter on `find_applications` / `move_application` / `rank_candidates` is a
  plain string resolved fuzzily against the posting's real pipeline stages — the same
  idiom the module already uses for `locatePosting` / `locateApplicant` — with a clear
  error listing the valid names when nothing matches. A single global Gemini `enum`
  can't be right once postings can run different pipelines.
- **Judgement, not just execution.** `advance_application` takes whatever
  `ApplicantScorer` recommends as the next step — but when the recommendation is
  *reject* or *hire* it reports it and stops. Negative and irreversible outcomes stay
  explicit human decisions. `move_application` can **reinstate** a rejected candidate;
  it will never un-hire one.
- **Decision support without a second model call.** `recruitment_summary` (org-wide,
  or one pipeline's average fit / strong / ready / stalled / standout via
  `PipelineInsights`), `rank_candidates` (the fit shortlist, terminal cards excluded)
  and `candidate_profile` (fit breakdown, rank, rating, interview verdict, next step)
  are pure database reads. They return `insight` cards, which the orchestrator
  narrates with their metrics and the chat renders as a chip row.
- **`candidate_insights` is the only tool that spends a model call.** It returns the
  **saved** `ai_insights` read unless `refresh` is asked for, and degrades gracefully
  (with the reason) when the key is missing or the service is busy.
- **Résumés in chat.** The assistant is multimodal, so a CV attached to the message is
  read by the model and its fields (headline, years of experience, contact) can be
  passed straight into `add_applicant` / `add_application`.

### Pipelines in the assistant

`App\Services\Assistant\Modules\RecruitmentPipelinesModule`, gated by
`recruitment.configure-pipelines` like the screen
([ADR 0056](../decisions/0056-assistant-recruitment-pipelines-and-clearance-templates.md)):

- **Reads:** `find_pipelines`; `get_pipeline`, stage by stage with the candidates on
  each and the postings running on it.
- **Writes:**
  - `create_pipeline`, from its in-progress steps (plus a hired stage and rejected
    stages) or by copying one;
  - `update_pipeline`, to rename it or make it the default;
  - `set_pipeline_stage`, to add a stage (in progress or rejected), or rename or move
    one. A stage's kind is changed on the screen only.
- **Confirmed:** `remove_pipeline_stage` and `delete_pipeline`. The card says how many
  postings run on the pipeline and whether candidates sit on the stage (then it is
  refused).
- A pipeline name another has in any case, or two stages of one name, is refused.
- **Retrieval:** "what hiring stages do we use?" carries every pipeline's flow.

## Due dates

A published (`open`) posting must carry a **closing date** — the create/edit form
requires it once status is `open`, and creating one cannot back-date it. The board,
pipeline header and careers board show a **countdown** (and an *Expired* flag once past
due). The careers surface refuses applications to an expired posting on the fly, and the
daily **`recruitment:close-expired`** command (scheduled in `routes/console.php`) flips
past-due open postings to `closed` system-wide.
- **`/careers/{org-slug}`** and **`/careers/{org-slug}/jobs/{posting}`** — the
  **public, unauthenticated careers surface** (see below). Recruiters copy a
  posting's public link from the board row actions ("Copy public link").

## Public careers & applications

Each organisation has a public careers board at `/careers/{slug}`; every **open**
posting has its own shareable page at `/careers/{slug}/jobs/{hashid}` where a
candidate applies with their details, a résumé (**required unless the posting turned it
off**), optional supporting documents, and answers to the posting's own **screening
questions** (rendered as Yes/No fields, saved to `screening_answers`).

The board and the job page show where the role is ("Hybrid · Makati HQ", with the
site's address on the job page), the employment type, the minimum experience and, when
the posting shows it, the pay ("₱25,000–₱35,000 a month"). The form asks for expected pay
in the posting's period ("Expected monthly pay") only when the posting asks for it, and
years of experience take one decimal place.

The surface is unauthenticated — there is no logged-in tenant — so:

- Routes live in [`routes/careers.php`](../../server/routes/careers.php) (outside
  the auth group) and are served by `App\Http\Controllers\Public\CareersController`.
- The posting is addressed by its obfuscated **hashid**; only `status = open`
  postings are viewable or accept applications (others 404), and a posting reached
  through the wrong organisation slug 404s.
- A submission is stamped with the posting's organisation via
  `Tenancy::runFor($organization, …)` — applicant, application, documents,
  activity log, and the `hr-manager` notification all created under that tenant.
- The applicant pool is reused by **email**: a repeat applicant updates their
  profile and latest résumé; a second application to the same posting is a no-op.
- **Anti-abuse:** the apply route is rate-limited (`throttle:5,1`), files are
  validated strictly (pdf/doc/docx/jpg/png, ≤10MB), and a hidden **honeypot**
  field silently drops bots. `source` is recorded as `website`.
- The bare `/careers` redirects to the board only when a single organisation
  exists (single-company installs); it 404s when several do, to avoid leaking the
  tenant list.

### Richer candidate profile + documents

The candidate record gained `current_location`, `linkedin_url`, `portfolio_url`,
and `years_experience`, plus an **`applicant_documents`** table for supporting
uploads (cover letter, certificate, transcript, portfolio, government ID, other).
The primary `resume` column stays (it is what the hire bridge copies into the 201
file). Documents are captured on the public form **and** the recruiter's
add-candidate / applicant-edit forms (`App\Support\ApplicantDocumentStore` is the
one place that persists them), and shown — with download links — in the pipeline
application detail modal.

## Data model

`recruitment_pipelines`, `recruitment_pipeline_stages`, `job_postings`,
`job_posting_screening_questions`, `applicants`, `job_applications`, `interviews` — see
the [schema doc](../database/recruitment-tables.md). Highlights:

- A **pipeline** is a tenant-owned, named, ordered list of **stages**; a stage's `kind`
  (`open` / `won` / `lost`) — never its name — is what business logic keys off. A
  pipeline needs exactly one `won` stage and at least one `lost` stage.
- A **posting** points at one pipeline, has a status (`draft → open → closed / filled`),
  an `openings` count, a `closing_date` (required once `open`), optional screening
  criteria (`min_years_experience`, one decimal place; `skills`; and its own
  `screening_questions`), and two opt-outs: `requires_resume` and `use_fit_scoring`
  (both default on). It auto-fills when its openings are hired and auto-closes when its
  closing date passes.
- A posting's **pay** is `salary_min` / `salary_max` (either may be empty) in a
  `salary_period` (`monthly` · `annual` · `daily` · `hourly`), shown publicly when
  `show_salary`. `asks_expected_salary` decides whether applicants are asked theirs.
  Its **place** is a `work_arrangement` (`onsite` · `hybrid` · `remote`) and an optional
  `work_location_id`. `JobPosting::money()` is the one way pay is written out.
- An **applicant** is a standalone candidate (not a user or employee); the pool is
  reusable across postings. It keeps the text read from its résumé and documents
  (`resume_text`, `resume_read_status`, `documents_text`), for matching only.
- An **application** is one applicant on one posting (`unique(posting, applicant)`),
  carrying a `recruitment_pipeline_stage_id` (its stage in the posting's own pipeline), a
  `rating`, `screening_answers`, an `expected_salary` with its `expected_salary_period`
  (filled from the posting when not given), the last LLM read (`ai_insights` JSON), and
  — once hired — `hired_employee_id`.
- An **interview** belongs to an application; scheduling one advances the application to
  the pipeline's next open stage by default (or an explicit target stage).

## Backend

- Controllers (`app/Http/Controllers/Recruitment/`): `JobPostingController`
  (index / show-pipeline / store / update / destroy), `JobPostingStatusController`,
  `ApplicantController`, `JobApplicationController` (store / show / stage / reject /
  update / destroy), `InterviewController`, **`HireController`** (the bridge),
  `RecruitmentExportController`, **`MyInterviewsController`** (an interviewer's own
  interviews, built by `Queries\MyInterviews`), and **`RecruitmentPipelineController`**
  (`app/Http/Controllers/Recruitment/`, mounted under Company Setup — index / store /
  update / destroy for pipelines and their stages, mirroring
  `OnboardingProgramController`'s "replace the whole child collection on save" pattern).
- Requests under `app/Http/Requests/Recruitment/` (`StoreJobPostingRequest` requires a
  closing date once `open` and a `recruitment_pipeline_id`; `StoreRecruitmentPipelineRequest`
  / `UpdateRecruitmentPipelineRequest` enforce the one-`won`/at-least-one-`lost` rule);
  resources `JobPostingResource`, `ApplicantResource`, `JobApplicationResource`,
  `InterviewResource`, `RecruitmentPipelineResource`; `JobPostingsIndexQuery` +
  `RecruitmentStatistics`; **`App\Support\Recruitment\ApplicantScorer`** for fit scoring +
  recommendations; **`App\Support\Recruitment\Skills\*`** and
  **`App\Support\Recruitment\ResumeExperience`** for the skill and experience readings it
  uses; **`App\Support\Files\DocumentText`** + **`App\Jobs\ReadApplicantFiles`** for
  reading files; **`App\Support\Recruitment\ApplicantInsights`** for the LLM candidate
  read (reuses `App\Support\Ai\GeminiClient`); **`App\Console\Commands\CloseExpiredPostings`**
  (scheduled daily) and **`ReadApplicantFilesCommand`** (`recruitment:read-files`, hourly);
  **`SkillCatalogueController`** (`GET /recruitment/skills`).
- **Shared with the assistant** (one implementation, two callers): stage transitions
  live on the models — `RecruitmentPipeline::entryStage()` / `wonStage()` /
  `defaultLostStage()` / `nextOpenStageAfter()` / `isLastOpenStage()` /
  `syncStages()`, `RecruitmentPipelineStage::isOpen()` / `isTerminal()`, the
  `JobApplication::open()` / `stalled()` scopes (`STALL_DAYS` stays a plain duration),
  and `moveTo(RecruitmentPipelineStage)` / `rejectWith(?RecruitmentPipelineStage, ?reason)`
  (which clear the decision fields consistently); **`App\Support\Recruitment\InterviewScheduler`**
  is the one booking path (create the interview *and*, by default, advance the
  application one open stage forward — or an explicit target); `ApplicantDocumentStore::purge()`
  / `forgetResume()` keep file cleanup in one place.
- `routes/recruitment.php` (literal-prefixed routes precede the `{jobPosting}`
  wildcard); pipeline management routes live in `routes/setup.php` alongside the other
  Company Setup screens. Every route is gated by a **Recruitment** permission (10 in the
  registry, including `recruitment.configure-pipelines` and `recruitment.interview`).
  `/recruitment` and recording an interview check in the controller, because two
  permissions can reach each.
- **`App\Support\Recruitment\Interviewers`** decides who is an interviewer: active
  members of this workspace whose role holds `recruitment.interview` or
  `.schedule-interviews` (or HR Manager). The picker, the booking request and the
  assistant all ask it. `mayRecord()` lets HR record any verdict and an interviewer
  their own; `notify()` tells the interviewer when they are booked.
- Mutations are activity-logged (`logName: 'recruitment'`); a new application and a
  hire notify the `hr-manager` role.

### The hire bridge (`HireController`)

`POST /recruitment/applications/{application}/hire` (gate `recruitment.hire`), in one
transaction:

1. Create an `Employee` from the applicant (name, contact) + posting (department,
   position, employment type); status `active`, today's hire date, auto employee no.
2. Copy the applicant's résumé into the employee's 201 file (`type: cv`).
3. Mark the application `hired` and link `hired_employee_id`.
4. If the posting's openings are now all hired, set it `filled`.

Stage moves go through a separate endpoint that **refuses** `hired`, so an employee
is only ever produced by this action.

## Frontend

`features/recruitment/` holds the types, routes, constants, the postings filter hook and
`use-pipeline-view`. That hook is a localStorage-backed layout preference built on
`use-stored-view`: **table** (the default) or board.

The page header, stat tiles, toolbar, search, filters, tables and pagination come from
the shared table kit (`components/data-table/`). The components are:

- **Postings:**
  - stats (including "In final stage", the generic equivalent of the old "Offers out");
  - the **postings table** and its row actions;
  - the posting status badge **and a status-progress indicator** (draft → open →
    closed/filled, shown in the detail modal);
  - the **posting detail modal** (a read-only overview with the public link) and the
    posting form modal.
- **Pipeline:**
  - **`pipeline-table.tsx`**, the default view;
  - **`pipeline-board.tsx`**, the Kanban board: one column per pipeline stage, in
    position order;
  - **`pipeline-insights.tsx`**, the decision support as stat tiles and a
    recommendation line;
  - the shared **application actions menu** (Move to… / Hire / Reject, used by the card
    and the table), and the card's own one-click **Advance** button.
- **Shared pieces:**
  - the **fit score** badge and meter (`fit-score.tsx`, hidden when a posting turns
    ranking off), with each skill's reading under the skill match;
  - the **posting deadline** countdown (`posting-deadline.tsx`);
  - a `kind`-driven stage badge (open/won/lost colour, with the stage's own name as the
    label), and rating stars.
- **Application detail modal:** the full candidate profile and a **decision panel**
  with the recommended next step. It also has a pipeline-driven **stage stepper**, the
  fit breakdown, the **AI Insights** panel (`applicant-insights.tsx`, which calls the
  insights endpoint via `features/recruitment/api.ts`), interviews, other
  applications, hire and reject. It lazy-loads the full application
  (`GET /recruitment/applications/{id}` JSON).
- **Add candidate modal.**
- **Posting form:**
  - a **pipeline picker**;
  - **"Require a résumé"** and **"Rank candidates automatically"** toggles;
  - a *Screening criteria* section: minimum experience, **must-have** and
    **nice-to-have** skills (`skills-input.tsx`, with suggestions and recognised-skill
    marks from `use-skill-catalogue.ts`), and a chevron-reorderable
    **screening-questions** builder (the same click-based pattern as Onboarding's
    checklist);
  - a closing date, required when the posting is open.

Pages are `pages/recruitment/index.tsx` and `pages/recruitment/pipeline.tsx`. The
sidebar **Talent Acquisition → Recruitment** link is gated on `recruitment.view`.

`features/recruitment-pipelines/` — the Company Setup surface's own feature folder
(types, routes, constants, components): `pipeline-card.tsx` (shows a pipeline's stages
as an ordered chip trail — a real sequence, so the numbering is earned) and
`pipeline-form-dialog.tsx` (the stage builder: chevron reorder, a plain-English kind
radio per stage, "Start from template"). Page: `pages/setup/recruitment-pipelines.tsx`.

### The modal shell

Every recruitment surface that used to slide in from the right is now a **centred
modal** built from one shell, `resources/js/components/modal.tsx` — shared with
[Onboarding](onboarding.md#frontend), which uses the same parts:

```text
Modal ─ ModalContent ─┬─ ModalHeader   icon/avatar, title, description, status meta
                      ├─ ModalBody     the only scrolling region
                      └─ ModalFooter   the action bar, always in view
```

`ModalContent` is height-capped (`100dvh` minus the gutter) and lays its parts out as
a flex column, so the body scrolls *inside* the modal and a decision — Hire, Reject,
Save — is never pushed below the fold on a short viewport. Sizes run `sm`…`2xl`; the
candidate modal takes `2xl` and splits its body into a decision column and a reference
rail from `lg` up, collapsing to one decision-first column on small screens.

Two form helpers sit alongside it, also shared (`components/form-field.tsx`,
`components/form-select.tsx`). **`FormField`** wires a label, its hint and its
validation error to the control itself (`htmlFor` / `aria-describedby` /
`aria-invalid`), so the error state is announced *and* styled without a second class
list; **`FormSelect`** forwards those props onto the select's trigger — the element the
label points at. Destructive candidate actions (reject, remove) confirm **inside the
footer** rather than stacking a second dialog on top of the first.

## Permissions

`recruitment.view`, `.create`, `.update`, `.delete`, `.manage-pipeline`,
`.schedule-interviews`, `.hire`, `.export`, `.configure-pipelines`, `.interview`.
HR Manager holds all of them. The built-in **Department Head** and **Staff** roles hold
`.interview` (ADR 0091), which reaches only the interviews a person is assigned: My
interviews, those candidates' résumés, and their own verdicts.
`.manage-pipeline` gates moving/rejecting candidates on a posting's board;
`.configure-pipelines` is separate and gates the Company Setup screen that defines
pipelines and their stages — a recruiter can run hiring day-to-day without being able
to redesign the process.

## Seeding

`RecruitmentSeeder` (in `DatabaseSeeder`) stands the module up for the demo tenant.
It seeds **two pipelines**, deliberately: `Standard Hiring` (the default — the
classic Applied → Screening → Interview → Offer → Hired → Rejected flow) and
`Operations Hiring`, a shorter process whose stages (`Trial Shift`,
`Supervisor Sign-off`, `Onboarded`, `Not Selected`, `Withdrew`) share no names with
it. One pipeline would hide the point of ADR 0029; two prove the board, the fit
scorer and the assistant read a stage's **kind and position**, never its name — and
the seeder itself follows that rule, placing candidates by offset into a pipeline's
own `open` stages rather than by looking a stage up by name.

On top of that it seeds five postings covering the shapes the module has to
survive: the ranked office role, one that asks its own screening question, an
`Operations Associate` that opted out of **both** the résumé requirement and
automatic ranking (the generic case), a `filled` posting whose hire is linked to a
roster line the way the hire bridge leaves it, and a `draft` that was never
published — so the postings grid shows the whole lifecycle. 24 applicants are
spread across them, with interviews for anyone past the entry stage and answers
recorded against each posting's screening questions.

Pipelines are configuration rather than demo traffic, so they are seeded even into
a tenant that already has postings; everything else only seeds when the tenant has
no postings yet. A **second** organisation (`SYNAPSE Labs`) is seeded without any
recruitment data at all, which is what makes the module's empty state — the
"start from template" prompt a brand-new organisation sees — demoable.

## Tests

- `tests/Feature/Recruitment/RecruitmentTest.php` — postings CRUD + status + filters,
  the **open-posting-needs-a-closing-date** rule, pipeline render, add candidate (new &
  existing), duplicate guard, stage moves, the hired-stage guard, reject, interview
  scheduling/result, the **hire bridge** (linked employee + filled posting), the
  double-hire guard, export, the authorization matrix, and tenant isolation.
- `tests/Feature/Recruitment/RecruitmentPipelinesTest.php` — pipeline CRUD (ordered
  stages, single-default enforcement, the one-`won`/at-least-one-`lost` rule), the
  in-use guards (a stage with applications, a pipeline still assigned to a posting), a
  **custom-named pipeline** end to end (create → post a job on it → apply → move through
  its own stages → hire), fit-scoring on/off, `configure-pipelines` authorization, and
  tenant isolation.
- `tests/Unit/ApplicantModelTest.php` — applicant accessors (DB-free).
- `tests/Unit/ApplicantScorerTest.php` — the fit score + recommendation, driven by stage
  **kind + position** against an in-memory standard pipeline (DB-free): strong fit in a
  mid-pipeline stage → advance, brand-new → nothing yet, weak fit in the last open stage
  → reject, failed interview → reject; skills by meaning, must-have weighting, and
  experience estimated from the résumé.
- `tests/Unit/Recruitment/` — `SkillMatcherTest` (TypeScript → JavaScript, whole words,
  cased and listed names, families, parts, slips, other words, "or", Philippine terms),
  `SkillCatalogueTest` (no shared alias, no loop, resolution) and
  `ResumeExperienceTest`.
- `tests/Feature/Recruitment/ResumeReadingTest.php` — a résumé sent on the careers page
  is read and counted, the text never reaches the browser, a new résumé resets the
  reading, the job skips government IDs, `recruitment:read-files`, the catalogue
  endpoint, nice-to-haves on postings and in the assistant, the export columns.
- `tests/Feature/Recruitment/DocumentTextTest.php` — Word, PDF, OCR, old .doc, bad files,
  cleaning (the PDF and OCR cases are skipped where poppler or tesseract is missing).
- `tests/Feature/Recruitment/ApplicantInsightsTest.php` — the AI-insights endpoint with
  Gemini faked: it generates + persists the read and **excludes the government ID** from
  the documents sent, and degrades gracefully when the key is unconfigured.
- `tests/Feature/Recruitment/RecruitmentAssistantTest.php` — the agentic surface, driving
  `RecruitmentModule` directly (no model call): the permission-scoped tool list, a
  denial case for the mutating tools, every posting / pool / pipeline / interview
  action and its guards (publish without a deadline, unknown department, duplicate
  candidate, delete-a-hire, un-hire, empty edit, an unresolvable stage name), `advance_application`
  **refusing to reject or hire**, the three read-outs, the saved-vs-refreshed AI read
  (Gemini faked), and tenant isolation.
- `tests/Feature/Recruitment/PostingPayAndPlaceTest.php` — pay range, period and
  visibility, work arrangement and site, decimal experience, the expected-pay period
  (asked, defaulted, skipped when the posting doesn't ask) and the careers page output.
- `tests/Feature/Recruitment/InterviewersTest.php` — `recruitment.interview`: the picker
  lists only this workspace's interviewers, booking someone who can't interview is
  refused, My interviews shows only one's own (without the expected salary), an
  interviewer records their own verdict but not another's, the résumé is readable only
  for one's own candidates, the redirect from `/recruitment`, and the notification.
- `tests/Feature/Careers/PublicCareersTest.php` — the public board and apply flow,
  including an **optional-résumé** posting accepting a submission without one, and
  screening questions round-tripping into `screening_answers`.
