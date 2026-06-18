# 0012 — Performance Management: weighted KPI evaluations with a derived overall score

- **Status:** Accepted
- **Date:** 2026-06-18
- **Related:** [Performance module](../modules/performance.md),
  [performance tables](../database/performance-tables.md), [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  Payroll ([module](../modules/payroll.md)) — for the derived-total pattern,
  Benefits ([ADR 0011](./0011-benefits-administration.md)) — for the config + operational split

## Context

ERD §8 sketches a `performance_evaluations` table (one employee's appraisal for an
`evaluation_period`, with an `overall_score` and a `draft → submitted → acknowledged`
status) and a `performance_scores` breakdown (a rating per `kpi_criterion`). The
criteria and periods are Company-Setup config (ERD §2). The sidebar already carried
ungated placeholders for **Performance Management** (`/performance`) and **KPI &
Evaluation Criteria** (`/setup/kpi`); **Training & Development** is a separate item,
so training is out of scope for this module.

The open question was how the `overall_score` is produced and where the per-criterion
weight comes from.

## Decision

Build Performance Management as a **config layer + operational module**, mirroring how
Benefits pairs `/setup/benefits` with `/benefits`:

**1. Configuration** — `kpi_criteria` + `evaluation_periods` (Company Setup →
`/setup/kpi`):
- **`kpi_criteria`** — the weighted dimensions an evaluation scores against, each with
  a relative `weight` and an active flag; archivable (soft delete), like
  `allowance_types` / `benefit_plans`.
- **`evaluation_periods`** — the review cycles (`draft → open → closed`); evaluations
  can only be opened while a period is `open`.

**2. Appraisals** — `performance_evaluations` + `performance_scores` (`/performance`):
- Opening an evaluation **seeds a score line per active criterion**, snapshotting the
  criterion's name + weight onto the line.
- The **`overall_score` is always derived**, never trusted from the client:
  `App\Support\Performance\PerformanceScorer` is the single source of truth — the
  weighted average of the scored lines on a 1–5 scale (`Σ(score × weight) / Σ(weight)`).
  This mirrors how `PayrollCalculator` owns the payslip totals.
- Lifecycle: a **draft** is scored and saved freely; **submit** locks it and finalises
  the overall (every criterion must be scored first); **acknowledge** records sign-off.

### Two refinements over the raw ERD (backward-compatible)

1. **`performance_scores` snapshots `label` + `weight`.** ERD §8 only FKs the
   criterion. But criteria are archivable config, so a submitted appraisal must keep
   what was scored and how it was weighted even if the criterion is later archived —
   exactly the resilience `payslip_earnings` gets from its `label` + nullable type FK.
   The `kpi_criterion_id` FK is kept (nullable, `nullOnDelete`) for lineage.
2. **`acknowledged_at` added.** ERD §8 carried only `submitted_at`; pairing a timestamp
   with the `acknowledged` status keeps the lifecycle auditable (cf. leave's
   `reviewed_at`).

## Consequences

- The overall score **cannot drift** from the scorecard: it is recomputed from the
  lines on every save and on submit. A draft shows a live running score (scored lines
  only); submit requires a complete card.
- Archiving a KPI criterion is safe for historical appraisals (snapshot); a criterion
  used by any evaluation, or a period with any evaluation, **cannot be permanently
  deleted** (archive instead) — the same guard `benefit_plans` uses.
- A dedicated `performance.*` / `setup.kpi.*` permission set gates the module and its
  configuration; the built-in **HR Manager** role gets both.
- **Out of scope (this cut):** self/peer/360 reviews, employee self-service
  acknowledgement, goal/competency libraries, linking scores into pay or promotions,
  Training & Development, and an assistant capability (matching the Payroll / Benefits
  precedent of shipping operational modules without an AI module first).
