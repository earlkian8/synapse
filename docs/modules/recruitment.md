# Recruitment

An applicant tracking system (ATS): post vacancies, move candidates through a hiring
pipeline, schedule interviews, and **hire** — which creates an employee. The *why* is
in [ADR 0006](../decisions/0006-recruitment-ats-and-hire-bridge.md); this is the
*how*. Everything is tenant-scoped (ADR 0005).

## Where it sits in the life cycle

```
Applicant ──apply──▶ Application ──pipeline──▶ Offer ──hire──▶ Employee
   └────────── Recruitment (this module) ──────────┘   └─ Workforce ┘
```

Recruitment is the **front door** to the workforce: most employees should arrive
through a hire here. The manual "New employee" form remains an escape hatch (data
migration, bypass hires), not the default path.

## Surfaces

- **`/recruitment`** — the job-postings board: stats, search/status/department
  filters, a **table ⇄ card-grid** view switch in the toolbar (the choice is
  remembered per browser), create/edit drawer, status lifecycle, CSV export.
  Selecting a posting opens a **read-only details drawer** (overview, public
  application link, description/requirements, pipeline summary) with **Open pipeline**
  and **Edit** actions; the pipeline-count chip and the row menu's "Open pipeline"
  jump straight to the board.
- **`/recruitment/{posting}`** — the **pipeline**, with a **table ⇄ card-grid** view
  switch (remembered per browser; **table is the default**), mirroring the postings
  board. **Stage tabs** (All · Applied · Screening · Interview · Offer · Hired ·
  Rejected, each with a count) sit above both views and filter the candidates shown.
  Candidates are **automatically ranked by a fit score** (see below) — the strongest
  still-in-the-running candidates lead, each showing its score and rank. Both views
  expose the same per-candidate Move / Hire / Reject menu and the same fit badge. Add
  candidates, move them, schedule interviews, reject, and hire. The header shows the
  posting's **closing-date countdown** and its screening criteria.

## Automatic ranking & decision support

`App\Support\Recruitment\ApplicantScorer` is the single source of truth for the
recruitment **fit score** and the decision support it powers. It is pure, deterministic
math: given an application (and its posting's optional criteria) it returns a 0–100 score
with a transparent breakdown and the **recommended next step** for HR. Controllers reuse
it rather than re-deriving the formula.

- **Components** (max points, normalised over whatever applies): recruiter rating (30),
  experience vs the posting's minimum (25), required-skill keyword match (20), interview
  outcome (15), document completeness (10). When a posting sets no minimum experience or
  no skills, those components simply don't apply and the score normalises over the rest —
  so ranking works the moment a candidate applies and sharpens as recruiters rate,
  interview, and set criteria. Bands: `strong`/`promising`/`fair`/`weak`.
- **Position-aware criteria.** A posting carries optional `min_years_experience` and a
  `skills` keyword list; skills are matched against the candidate's headline, notes and
  cover letter.
- **Recommendation.** Derived from the stage + fit + interview verdict — *Advance to
  screening · Schedule an interview · Move to offer · Hire · Consider rejecting* — and
  surfaced in the candidate drawer's **decision panel** with a one-click action that
  performs it. The drawer is the **full candidate profile**: contact, profile links, all
  documents, the fit breakdown, interview history, and the candidate's **other
  applications across postings**.

## AI candidate insights

On top of the deterministic fit score, HR can ask an LLM to **read a specific
candidate** and return decision support grounded in their actual documents.
`App\Support\Recruitment\ApplicantInsights` (mirroring the Reports module's
`ReportInsights`) compiles a digest — the candidate's profile, the role and its
criteria, the rule-based fit breakdown, ratings and interview history — **and
attaches the real files** (résumé + supporting documents) to Gemini so the model
reads them. `gemini-2.5-flash` ingests PDFs and images natively, so no parser
dependency is needed; office files are named in the digest but not uploaded.

- **Privacy.** Government-ID documents are **never** sent to the model, and only
  model-readable types (PDF, PNG/JPG/WebP) are attached, bounded by a total size
  budget. The model returns strict JSON: a headline verdict, summary, strengths,
  concerns, what the documents reveal, sharp interview questions, and a
  recommendation.
- **On demand + persisted.** Insights are generated from the candidate drawer's
  **AI Insights** panel (`POST /recruitment/applications/{application}/insights`,
  gated `recruitment.view`) and **saved on the application** (`ai_insights` JSON
  column) so reopening the drawer shows the last read instantly without spending
  another model call; a **Regenerate** button re-runs it. Generation is
  activity-logged. Degrades gracefully (retryable) when the key is missing or the
  service is rate-limited/overloaded — exactly like the Reports insights.

## The agentic assistant

Everything a recruiter can do on the board, the **Synapse assistant** can do in
conversation. `App\Services\Assistant\Modules\RecruitmentModule` is the largest
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
candidate applies with their details, a required résumé, and optional supporting
documents. The surface is unauthenticated — there is no logged-in tenant — so:

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
application detail drawer.

## Data model

`job_postings`, `applicants`, `job_applications`, `interviews` — see the
[schema doc](../database/recruitment-tables.md). Highlights:

- A **posting** has a status (`draft → open → closed / filled`), an `openings` count, a
  `closing_date` (required once `open`), and optional screening criteria
  (`min_years_experience`, `skills`); it auto-fills when its openings are hired and
  auto-closes when its closing date passes.
- An **applicant** is a standalone candidate (not a user or employee); the pool is
  reusable across postings.
- An **application** is one applicant on one posting (`unique(posting, applicant)`),
  carrying the pipeline `stage`, a `rating`, the last LLM read (`ai_insights` JSON),
  and — once hired — `hired_employee_id`.
- An **interview** belongs to an application; scheduling one advances an early-stage
  application to `interview`.

## Backend

- Controllers (`app/Http/Controllers/Recruitment/`): `JobPostingController`
  (index / show-pipeline / store / update / destroy), `JobPostingStatusController`,
  `ApplicantController`, `JobApplicationController` (store / show / stage / reject /
  update / destroy), `InterviewController`, **`HireController`** (the bridge), and
  `RecruitmentExportController`.
- Requests under `app/Http/Requests/Recruitment/` (`StoreJobPostingRequest` requires a
  closing date once `open`); resources `JobPostingResource`, `ApplicantResource`,
  `JobApplicationResource`, `InterviewResource`; `JobPostingsIndexQuery` +
  `RecruitmentStatistics`; **`App\Support\Recruitment\ApplicantScorer`** for fit scoring +
  recommendations; **`App\Support\Recruitment\ApplicantInsights`** for the LLM candidate
  read (reuses `App\Support\Ai\GeminiClient`); **`App\Console\Commands\CloseExpiredPostings`**
  (scheduled daily).
- **Shared with the assistant** (one implementation, two callers): the stage vocabulary
  and transitions live on the model — `JobApplication::OPEN_STAGES` / `TERMINAL_STAGES` /
  `STALL_DAYS`, the `open()` / `stalled()` scopes, and `moveTo()` / `rejectWith()` (which
  clear the decision fields consistently); **`App\Support\Recruitment\InterviewScheduler`**
  is the one booking path (create the interview *and* advance an early-stage candidate);
  `ApplicantDocumentStore::purge()` / `forgetResume()` keep file cleanup in one place.
- `routes/recruitment.php` (literal-prefixed routes precede the `{jobPosting}`
  wildcard). Every route is gated by a **Recruitment** permission (8 in the registry).
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

`features/recruitment/` — types, routes, constants, the postings filter hook, and
two layout-preference hooks built on a shared `use-stored-view` (localStorage-backed):
`use-postings-view` (table/grid) and `use-pipeline-view` (board/table). Components:
stats, postings toolbar (search/status/department filters + the table/card-grid view
switch), **table** and **card grid**, row-actions, posting status badge, **posting
detail sheet** (read-only overview + public link), posting form sheet, pagination;
and the pipeline pieces — **table** and **card grid** (of candidate cards), **stage
tabs** (filter both, with counts), the shared **application actions menu** (Move /
Hire / Reject, used by the card and the table), the **fit score** badge + meter
(`fit-score.tsx`), the **posting deadline** countdown (`posting-deadline.tsx`),
**application detail sheet** (the full candidate profile + a **decision panel** with the
recommended next step, the fit breakdown, the **AI Insights** panel
(`applicant-insights.tsx`, calls the insights endpoint via `features/recruitment/api.ts`),
interviews, other applications, hire, reject),
**add candidate sheet**, stage badge, rating stars. The **posting form** has a
*Screening criteria* section (minimum experience + a skills tag input) and a
required-when-open closing date. Pages: `pages/recruitment/index.tsx`
and `pages/recruitment/pipeline.tsx`. The detail drawer lazy-loads the full
application (`GET /recruitment/applications/{id}` JSON). The sidebar **Talent
Acquisition → Recruitment** link is gated on `recruitment.view`.

## Permissions

`recruitment.view`, `.create`, `.update`, `.delete`, `.manage-pipeline`,
`.schedule-interviews`, `.hire`, `.export`. Seeded to Super Admin / Administrator
(all) and HR Manager (all of recruitment).

## Tests

- `tests/Feature/Recruitment/RecruitmentTest.php` — postings CRUD + status + filters,
  the **open-posting-needs-a-closing-date** rule, pipeline render, add candidate (new &
  existing), duplicate guard, stage moves, the hired-stage guard, reject, interview
  scheduling/result, the **hire bridge** (linked employee + filled posting), the
  double-hire guard, export, the authorization matrix, and tenant isolation.
- `tests/Unit/ApplicantModelTest.php` — applicant accessors (DB-free).
- `tests/Unit/ApplicantScorerTest.php` — the fit score + recommendation across stages
  (DB-free): strong → offer, brand-new → screen, weak screening → reject, failed
  interview → reject.
- `tests/Feature/Recruitment/ApplicantInsightsTest.php` — the AI-insights endpoint with
  Gemini faked: it generates + persists the read and **excludes the government ID** from
  the documents sent, and degrades gracefully when the key is unconfigured.
- `tests/Feature/Recruitment/RecruitmentAssistantTest.php` — the agentic surface, driving
  `RecruitmentModule` directly (no model call): the permission-scoped tool list, a
  **denial case for all 17 mutating tools**, every posting / pool / pipeline / interview
  action and its guards (publish without a deadline, unknown department, duplicate
  candidate, delete-a-hire, un-hire, empty edit), `advance_application` **refusing to
  reject or hire**, the three read-outs, the saved-vs-refreshed AI read (Gemini faked),
  and tenant isolation.
