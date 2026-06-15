# 2026-06-14 — Public careers pages & richer candidate applications

Job postings can now be shared as public URLs where candidates apply directly with
their CV and supporting files, and the candidate record captures much more of what
an HR ERP needs. The *why* is in the [ADR 0006 amendment](../decisions/0006-recruitment-ats-and-hire-bridge.md).

## Highlights

- **Every open posting has a public, shareable page.** Each organisation gets a
  careers board at `/careers/{slug}` and per-posting pages at
  `/careers/{slug}/jobs/{hashid}` — unauthenticated, so anyone with the link can
  read the role and apply. Recruiters copy the link from the postings board
  ("Copy public link" in a posting's row actions).
- **Candidates apply with files.** The public form takes a required résumé plus any
  number of supporting documents (cover letter, certificate, transcript, portfolio,
  government ID, …) and a fuller profile (location, LinkedIn/portfolio, years of
  experience, expected salary, cover note).
- **Richer candidate record everywhere.** The new profile fields and document
  uploads are also available on the recruiter's add-candidate / applicant forms,
  and the pipeline detail drawer now shows them with download links.

## Backend

- Migration `…_extend_applicants_and_add_documents`: adds `current_location`,
  `linkedin_url`, `portfolio_url`, `years_experience` to `applicants`, and a new
  tenant-scoped `applicant_documents` table (mirrors `employee_documents`).
- `App\Models\ApplicantDocument` + `Applicant::documents()`; the primary
  `applicants.resume` column is unchanged (the hire bridge still copies it).
- `App\Http\Controllers\Public\CareersController` (board / show / apply / landing)
  + `routes/careers.php` (no auth/tenant middleware; apply is `throttle:5,1`).
  A public submission resolves the org from the URL and creates the applicant,
  application, documents, activity log, and `hr-manager` notification inside
  `Tenancy::runFor($organization, …)`. Only `open` postings are viewable/applyable;
  a wrong-org slug 404s; a hidden honeypot field silently drops bots.
- `App\Support\ApplicantDocumentStore` — one place that persists supporting uploads,
  reused by the public form and the internal add-candidate / applicant-edit flows.
- Requests: new `SubmitApplicationRequest` (public, résumé required); the existing
  `StoreApplicantRequest` / `StoreJobApplicationRequest` gained the new fields and
  document validation. Resources expose the new fields, a documents collection, and
  a posting `apply_url` / `is_open`.

## Frontend

- Public pages `pages/careers/index.tsx` (board) and `pages/careers/show.tsx`
  (role + application form with a success state), plus the `features/careers/`
  feature (shell, application form with dynamic document rows + honeypot). These
  render with no app layout (like `welcome`).
- Recruitment: add-candidate form gained the new profile fields; the application
  detail drawer shows location, links, experience, and downloadable documents; the
  postings row actions can copy a posting's public link. Welcome page links to
  `/careers`.

## Docs

- ADR 0006 amended; [recruitment module](../modules/recruitment.md) and
  [recruitment schema](../database/recruitment-tables.md) updated.

## Tests

- `tests/Feature/Careers/PublicCareersTest.php` — board lists only open postings,
  open/non-open/cross-org visibility, the single-org landing redirect, a full apply
  (résumé + document, tenant-stamped), résumé-required validation, honeypot drop,
  and the duplicate-email guard.

## Notes

- Verified locally on Postgres: migration applied; a tinker smoke test exercised the
  apply path (tenant stamping, document store, public URL) inside a rolled-back
  transaction; the public GET routes return 200/404/redirect as designed. The Pest
  suite can't run on this machine (no `pdo_sqlite`) — the tests were written but not
  executed here.
- `/careers` (no slug) only redirects when a single organisation exists; with several
  tenants it 404s by design (it must not leak the tenant list). The welcome "Careers"
  link therefore lands only on single-company installs.
- The existing seeded demo org is still `nexo-demo-co` (the NEXO→SYNAPSE rename
  updated seeder source, not already-seeded rows); its careers board is at
  `/careers/nexo-demo-co` until re-seeded.
