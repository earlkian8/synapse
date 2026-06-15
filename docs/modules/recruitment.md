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

- **`/recruitment`** — the job-postings board: stats, **status tabs** (All / Draft /
  Open / Closed / Filled) and a **table ⇄ card-grid** view switch (the choice is
  remembered per browser), search/department filters, create/edit drawer, status
  lifecycle, CSV export. Selecting a posting opens a **read-only details drawer**
  (overview, public application link, description/requirements, pipeline summary)
  with **Open pipeline** and **Edit** actions; the pipeline-count chip and the row
  menu's "Open pipeline" jump straight to the board.
- **`/recruitment/{posting}`** — the **pipeline board**: columns for each stage
  (Applied · Screening · Interview · Offer · Hired · Rejected) with candidate cards.
  Add candidates, move them, schedule interviews, reject, and hire.
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

- A **posting** has a status (`draft → open → closed / filled`) and an `openings`
  count; it auto-fills when its openings are hired.
- An **applicant** is a standalone candidate (not a user or employee); the pool is
  reusable across postings.
- An **application** is one applicant on one posting (`unique(posting, applicant)`),
  carrying the pipeline `stage`, a `rating`, and — once hired — `hired_employee_id`.
- An **interview** belongs to an application; scheduling one advances an early-stage
  application to `interview`.

## Backend

- Controllers (`app/Http/Controllers/Recruitment/`): `JobPostingController`
  (index / show-pipeline / store / update / destroy), `JobPostingStatusController`,
  `ApplicantController`, `JobApplicationController` (store / show / stage / reject /
  update / destroy), `InterviewController`, **`HireController`** (the bridge), and
  `RecruitmentExportController`.
- Requests under `app/Http/Requests/Recruitment/`; resources `JobPostingResource`,
  `ApplicantResource`, `JobApplicationResource`, `InterviewResource`;
  `JobPostingsIndexQuery` + `RecruitmentStatistics`.
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

`features/recruitment/` — types, routes, constants, the postings filter hook and a
view-preference hook (`use-postings-view`, localStorage-backed), and components:
stats, postings toolbar, **controls** (status tabs + view switch), **table** and
**card grid**, row-actions, posting status badge, **posting detail sheet** (read-only
overview + public link), posting form sheet, pagination; and the pipeline pieces —
**board**, **column**, **card**, **application detail sheet** (rating, stage moves,
interviews, hire, reject), **add candidate sheet**, stage badge, rating stars. Pages: `pages/recruitment/index.tsx`
and `pages/recruitment/pipeline.tsx`. The detail drawer lazy-loads the full
application (`GET /recruitment/applications/{id}` JSON). The sidebar **Talent
Acquisition → Recruitment** link is gated on `recruitment.view`.

## Permissions

`recruitment.view`, `.create`, `.update`, `.delete`, `.manage-pipeline`,
`.schedule-interviews`, `.hire`, `.export`. Seeded to Super Admin / Administrator
(all) and HR Manager (all of recruitment).

## Tests

- `tests/Feature/Recruitment/RecruitmentTest.php` — postings CRUD + status + filters,
  pipeline render, add candidate (new & existing), duplicate guard, stage moves, the
  hired-stage guard, reject, interview scheduling/result, the **hire bridge** (linked
  employee + filled posting), the double-hire guard, export, the authorization matrix,
  and tenant isolation.
- `tests/Unit/ApplicantModelTest.php` — applicant accessors (DB-free).
