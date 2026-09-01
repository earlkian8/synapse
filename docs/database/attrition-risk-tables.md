# Database: attrition risk tables

The tables behind the [Attrition Risk module](../modules/attrition-risk.md), created
by `…_create_attrition_risk_tables`. A header (`attrition_risk_runs`) plus its
per-employee lines (`attrition_risk_scores`) — mirroring the `promotion_readiness_runs`
+ `promotion_readiness_scores` shape
([ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md)) and the
`performance_forecast_*` tables. See
[ADR 0021](../decisions/0021-attrition-risk.md). Both are tenant-scoped
(`organization_id`).

## `attrition_risk_runs`

One batch assessment: every active employee scored through the attrition model at a
point in time, with a summary. Addressed by hashid in URLs (`?run=…`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `generated_by` | FK → users, nullable | Who triggered it; `nullOnDelete`. |
| `status` | string | `completed \| failed`. Only completed runs are persisted today. Indexed. |
| `model_version` | string, nullable | e.g. `RandomForestClassifier@2026-06-28T01:07:48`, from the inference service. |
| `employees_scored` | unsigned int | How many employees the run scored. |
| `high_count` / `medium_count` / `low_count` | unsigned int | Tier tallies (probability ≥0.66 / 0.33–0.66 / <0.33). |
| `average_score` | decimal(5,2), nullable | Mean risk score (0–100) across the run. |
| `average_confidence` | decimal(4,3), nullable | Mean confidence (0–1) across the run. |
| `note` | text, nullable | Reserved for failure detail. |
| timestamps | | |

**Indexes:** `status`.

## `attrition_risk_scores`

One employee's result within a run.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `attrition_risk_run_id` | FK → attrition_risk_runs | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. |
| `probability` | decimal(6,5) | Raw model probability of leaving (0–1). |
| `score` | decimal(5,2) | The 0–100 presentation risk score (probability × 100). |
| `tier` | string | `low \| medium \| high` (cut at 0.33 / 0.66). Indexed. |
| `confidence` | decimal(4,3) | Share (0–1) of the model's key inputs grounded in real HR data (vs. imputed). |
| `features` | json, nullable | Snapshot of the feature vector sent to the model, for audit (protected attributes never included). |
| timestamps | | |

**Indexes:** unique `(attrition_risk_run_id, employee_id)` — one score per employee
per run; `tier`.

> The risk scores are **derived by the model**, never entered. The header's tier
> counts and averages, and each line's `confidence` and `tier`, are computed at run
> time by `App\Support\Ml\AttritionRiskAssessor`. Unlike Promotion Readiness there is
> **no `factors` column** — the Random-Forest model exposes no per-instance
> contributions, so (as in Performance Forecast) the `confidence` and the `features`
> snapshot stand in for the "why".
