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
| `model_version` | string, nullable | e.g. `LogisticRegression@2026-06-23T03:19:36`, from the inference service. |
| `employees_scored` | unsigned int | How many employees the run scored. |
| `high_count` / `medium_count` / `low_count` | unsigned int | Tier tallies. |
| `average_score` | decimal(5,2), nullable | Mean readiness (0–100) across the run. |
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
| `probability` | decimal(6,5) | Raw model probability (0–1). |
| `score` | decimal(5,2) | Presentation readiness score (0–100 = probability × 100). |
| `tier` | string | `low \| medium \| high` (cut at 0.33 / 0.66). Indexed. |
| `factors` | json, nullable | Top contributing factors `[{feature, label, impact, direction}]` (protected attributes excluded). |
| `features` | json, nullable | Snapshot of the feature vector sent to the model, for audit. |
| timestamps | | |

**Indexes:** unique `(promotion_readiness_run_id, employee_id)` — one score per employee
per run; `tier`.

> The scores are **derived by the model**, never entered. The header's tier counts and
> average are computed from the lines at run time by
> `App\Support\Ml\PromotionReadinessAssessor`.
