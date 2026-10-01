# 2026-06-11 — Recruitment module (applicant tracking + hire bridge)

An applicant tracking system at `/recruitment`: job postings, a candidate pool, a
hiring pipeline, interviews, and a **hire → employee** bridge that makes recruitment
the real origin of employees. See [ADR 0006](../decisions/0006-recruitment-ats-and-hire-bridge.md)
and the [module doc](../modules/recruitment.md).

## Summary

- **Job postings** with a status lifecycle (draft → open → closed / filled) and an
  openings count, on a filtered/sortable board with CSV export.
- **Pipeline board** per posting: stage columns, candidate cards, drawer with rating,
  stage moves, interviews, reject and hire.
- **Candidate pool** (standalone applicants), reusable across postings.
- **Interviews** scheduled against applications, with mode and result.
- **Hire bridge**: hiring creates an employee, copies the résumé into the 201 file,
  links the application, and fills the posting.

## Backend

- Migration `…_create_recruitment_tables`: `job_postings`, `applicants`,
  `job_applications` (unique `(posting, applicant)`), `interviews` — all tenant-scoped.
- Models `JobPosting`, `Applicant`, `JobApplication`, `Interview`
  (`BelongsToOrganization`, relations, scopes, accessors).
- Controllers: `JobPostingController`, `JobPostingStatusController`,
  `ApplicantController`, `JobApplicationController`, `InterviewController`,
  **`HireController`**, `RecruitmentExportController`.
- Requests / resources / `JobPostingsIndexQuery` + `RecruitmentStatistics`.
- **Recruitment** permission group (8 permissions) in `PermissionRegistry`, granted to
  Super Admin / Administrator / HR Manager; `routes/recruitment.php` wired into web.php.
- Activity logging (`logName: 'recruitment'`); new-application and hire notify the
  `hr-manager` role.
- Factories + `RecruitmentSeeder` (wired into `DatabaseSeeder`).

## Frontend

- `features/recruitment/` — types, routes, constants, postings filter hook, and
  components (stats, postings toolbar/table/row-actions, status & stage badges, posting
  form sheet, pagination, **pipeline board / column / card**, **application detail
  sheet**, **add-candidate sheet**, rating stars, confirm dialog).
- Pages `pages/recruitment/index.tsx` (postings) and `pages/recruitment/pipeline.tsx`
  (board); detail drawer lazy-loads the full application JSON.
- Sidebar **Talent Acquisition → Recruitment** gated on `recruitment.view`.

## Tests

- `tests/Feature/Recruitment/RecruitmentTest.php` — postings CRUD/status/filters,
  pipeline render, add candidate (new & existing) + duplicate guard, stage moves +
  hired-stage guard, reject, interview scheduling/result, the hire bridge + double-hire
  guard, export, authorization matrix, tenant isolation.
- `tests/Unit/ApplicantModelTest.php` — accessors.

## Verification

`tsc`, ESLint, Pint clean; `npm run build` succeeds (`recruitment` chunk 29 kB). Unit
suite green (14). Migration + seeder ran against live Postgres (4 postings, 18
applicants, 23 applications, interviews); stats, index counts, pipeline load and the
hire bridge verified there; HTTP smoke (`/recruitment` → 302). (Feature suite needs
`pdo_sqlite` / CI.)

## ⚠️ Migration note

Run `php artisan migrate` and re-seed roles
(`php artisan db:seed --class=RolePermissionSeeder`) so existing roles pick up the
**Recruitment** permissions. `php artisan db:seed` is idempotent and seeds a starter
pipeline for the demo tenant.
