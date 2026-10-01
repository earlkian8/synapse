# 2026-06-17 — Benefits Administration (with Company Setup)

A new **Benefits Administration** module: the benefit plans the organisation offers
(HMO, insurance, retirement, wellness) and who's enrolled in each, with a Company
Setup configuration surface. Replaces the ERD's deferred `benefit_contributions`
snapshot with a richer **plan + enrollment** model
([ADR 0011](../decisions/0011-benefits-administration.md)) — statutory contributions
stay as payslip deductions, so there's no second source of truth.

## Highlights

- **Overview (`/benefits`)** — a KPI bar (active plans, employees covered, employer
  & employee monthly cost) and **plan cards grouped by category**, each showing the
  provider, per-period employee / employer share, and live enrollee count.
- **Plan roster (`/benefits/{plan}`)** — a plan's header (category, provider, cost,
  active enrollees) and the list of enrolled employees. HR can **enroll**, **edit**
  (status / member reference / dates / notes), or **remove** an enrollment.
- **Benefits Configuration (`/setup/benefits`)** — manage the plan catalogue:
  category, provider, cost, frequency, active flag; full create / edit / archive /
  restore / permanent-delete lifecycle, each row showing its enrollee count.
- **Employee detail → Benefits tab** — a read-only summary of an employee's
  enrollments.

## Backend

- **Schema:** `benefit_plans` (tenant-scoped catalogue, soft-deletes, hashid) +
  `benefit_enrollments` (employee ↔ plan, unique per pair).
- **Models:** `BenefitPlan` (category/frequency consts, `toMonthly()` cost
  normaliser, `catalogueOrder` scope), `BenefitEnrollment` (`active` scope);
  `Employee::benefitEnrollments()`.
- **Controllers:** `Benefits\BenefitController` (overview + roster with cost
  rollups), `Benefits\BenefitEnrollmentController` (store / update / destroy),
  `Setup\BenefitPlanController` (index + CRUD lifecycle). FormRequests
  `BenefitPlanRequest` / `BenefitEnrollmentRequest`; resources `BenefitPlanResource`
  / `BenefitEnrollmentResource`. Routes in `routes/benefits.php` + `setup.php`.
- **Permissions:** `benefits.view` / `benefits.manage` and `setup.benefits.view` /
  `setup.benefits.manage`, granted to **HR Manager**. Activity logged under
  `benefits` / `company-setup`.
- **Seeder:** `BenefitSeeder` — 6 plans (Maxicare, Intellicare, Sun Life, AXA,
  retirement, wellness) and a believable spread of enrollments.

## Frontend

- New `features/benefits` (types, constants with category icons/colours + peso
  helpers, routes, api, stats / plan-card / status-badge / enroll-dialog
  components) and `features/benefits-config` (plan form sheet). Pages
  `benefits/index`, `benefits/show`, `setup/benefits`. The card-and-roster layout is
  chosen over a table — the better fit for a plan catalogue and its member lists.
- Sidebar: the reserved **Benefits Administration** entry is now gated by
  `benefits.view`; a **Benefits Configuration** entry is added to Company Setup.

## Notes

- Verified: `php -l` + Pint clean; a tinker check confirmed the overview/roster and
  employee resources resolve (6 plans, 65 enrollments, 21 covered employees, employer
  ₱81,850/mo; active-vs-pending split correct). `tsc`, ESLint, Prettier and
  `npm run build` all green. Routes register; `benefits.*` gates resolve.
- After pulling: `php artisan migrate` (additive) then re-run `RolePermissionSeeder`
  (or `migrate:fresh --seed`) to grant the new permissions and seed the demo plans.
