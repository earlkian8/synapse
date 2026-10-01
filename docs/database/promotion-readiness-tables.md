# Database: promotion readiness tables

The tables behind the [Promotion Readiness module](../modules/promotion-readiness.md),
created by `…_create_promotion_readiness_tables`. A header (`promotion_readiness_runs`)
plus its per-employee lines (`promotion_readiness_scores`) — mirroring the
`performance_evaluations` + `performance_scores` shape. See
[ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md). Both are
tenant-scoped (`organization_id`).

## `promotion_readiness_runs`

One batch assessment: every active employee scored through the promotion model at a point
in time, with a summary. Addressed by hashid in URLs (`?run=…`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `generated_by` | FK → users, nullable | Who triggered it; `nullOnDelete`. |
| `status` | string | `completed \| failed`. Only completed runs are persisted today. Indexed. |
| `model_version` | string, nullable | e.g. `LogisticRegression+QuadraticPlatt (pattern submodels)@2026-09-26T01:01:57`, from the inference service. Kept for audit; not sent to the browser. |
| `employees_scored` | unsigned int | How many employees the run scored. |
| `high_count` / `medium_count` / `low_count` | unsigned int | Tier tallies. |
| `average_score` | decimal(5,2), nullable | Mean readiness (0–100) across the run. |
| `unassessed` | json, nullable | `[{employee_id, reason}]` — active employees the model declined: `no_appraisal` or `appraisal_in_progress` (only drafts). [ADR 0045](../decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md). |
| `note` | text, nullable | Reserved for failure detail. |
| timestamps | | |

**Indexes:** `status`.

## `promotion_readiness_scores`

One employee's result within a run.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `promotion_readiness_run_id` | FK → promotion_readiness_runs | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. |
| `probability` | decimal(6,5) | Calibrated probability (0–1): the share of reference employees with this record promoted within a year. |
| `score` | decimal(5,2) | Readiness (0–100): where that probability sits among the reference workforce (mid-rank percentile). |
| `tier` | string | `low \| medium \| high` — by lift over the reference's 10 % promotion rate (high ≥ 2×, medium ≥ 1×). Indexed. |
| `basis` | string, nullable | `two_appraisals \| latest_appraisal` — how much history the score rests on. |
| `factors` | json, nullable | `[{feature, label, impact, direction}]` — readiness points each recorded input moves the score against a typical record. |
| `features` | json, nullable | Snapshot of the inputs sent to the model (`rating_latest`, `rating_change`), for audit. |
| `history` | json, nullable | The completed appraisals behind them `[{label, rating}]` (attainment, oldest first). |
| `warnings` | json, nullable | Notes about any input held at the model's trained range. |
| timestamps | | |

**Indexes:** unique `(promotion_readiness_run_id, employee_id)` — one score per employee
per run; `tier`.

> The scores are **derived by the model**, never entered. The header's tier counts and
> average are computed from the lines at run time by
> `App\Support\Ml\PromotionReadinessAssessor`. Only employees the model scored get a
> line — the rest are in the header's `unassessed`.
