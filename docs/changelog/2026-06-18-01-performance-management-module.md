# Performance Management module + KPI/Evaluation Setup

Adds the **Performance Management** module (ERD §8): conduct employee appraisals
against weighted **KPI criteria** within a **review period**, with a derived overall
score and a `draft → submitted → acknowledged` lifecycle. Ships with its Company-Setup
configuration (**KPI & Evaluation Criteria**), wiring the two existing sidebar
placeholders. Mirrors the Benefits split of a config layer + operational module; see
[ADR 0012](../decisions/0012-performance-management.md),
[module doc](../modules/performance.md) and
[performance tables](../database/performance-tables.md).

## Highlights

- **Weighted KPI scorecards.** Opening an evaluation seeds a line per active criterion
  (snapshotting its label + weight); HR rates each on a 1–5 scale and the **overall
  score is derived** — never trusted from the client.
- **Single source of truth for the score.** `App\Support\Performance\PerformanceScorer`
  computes the weighted average (`Σ(score × weight) / Σ(weight)`), recomputed on every
  save and on submit (mirrors `PayrollCalculator`).
- **Lifecycle.** Draft (freely scored, live running total) → Submit (locks; every
  criterion must be scored) → Acknowledge (final, read-only). Drafts can be deleted.
- **Config in Company Setup.** `/setup/kpi` manages KPI criteria (with a total-active-
  weight nudge toward 100%) and evaluation periods, both with the standard archive /
  restore / permanent-delete lifecycle.

## Backend

- **Migration** `…_create_performance_tables`: `kpi_criteria`, `evaluation_periods`
  (config, soft-deletes), `performance_evaluations` (unique per employee+period),
  `performance_scores` (label + weight snapshot). All tenant-scoped.
- **Models** `KpiCriterion`, `EvaluationPeriod`, `PerformanceEvaluation`,
  `PerformanceScore` (+ `Employee::performanceEvaluations`).
- **Support** `App\Support\Performance\PerformanceScorer` (pure weighted-average math).
- **Controllers** `Setup\KpiSetupController` / `KpiCriterionController` /
  `EvaluationPeriodController`; `Performance\PerformanceController` (index/show) +
  `PerformanceEvaluationController` (store/update/submit/acknowledge/destroy). Thin,
  FormRequest-validated, activity-logged.
- **Resources** `KpiCriterionResource`, `EvaluationPeriodResource`,
  `PerformanceScoreResource`, `PerformanceEvaluationResource`.
- **Routes** new `routes/performance.php` (required in `web.php`) + a `kpi` section in
  `routes/setup.php`. Permissions `performance.view` / `performance.manage` and
  `setup.kpi.view` / `setup.kpi.manage` added to `PermissionRegistry`; built-in HR
  Manager granted all four.
- **Seeder** `PerformanceSeeder` (6 weighted criteria, a closed annual + open mid-year
  period, and a spread of scored evaluations); wired into `DatabaseSeeder`.

## Frontend

- **Features** `features/performance` (types, routes, api, constants, components:
  stats, status badges, rating input, scorecard row, new-evaluation dialog) and
  `features/kpi-config` (criterion / period form sheets).
- **Pages** `performance/index` (overview + filters), `performance/show` (the
  scorecard with live overall + actions), `setup/kpi` (two-section config mirroring
  `setup/payroll`).
- **Sidebar** Performance Management (gated `performance.view`) and KPI & Evaluation
  Criteria (gated `setup.kpi.view`) wired from their placeholders.

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint, Prettier and `vite build` all green; routes
  registered; migration + seeders run on Postgres; the full create → score → submit →
  acknowledge lifecycle and hashid route binding validated via a rolled-back tinker
  transaction. The Pest suite was **not** run locally (no `pdo_sqlite` on this machine).
- Out of scope this cut: self/peer/360 reviews, employee self-service, goal/competency
  libraries, feeding scores into pay/promotions, Training & Development, and an
  assistant capability.
