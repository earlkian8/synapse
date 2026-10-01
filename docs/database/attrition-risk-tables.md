# Database: attrition risk tables

The tables behind the [Attrition Risk module](../modules/attrition-risk.md), created by
`2026_09_24_000000_create_attrition_risk_tables`. A header (`attrition_risk_runs`) plus
its per-employee lines (`attrition_risk_scores`) — the same shape as
[promotion readiness](./promotion-readiness-tables.md) and
[performance forecast](./performance-forecast-tables.md). See
[ADR 0043](../decisions/0043-attrition-risk-trained-on-the-attrition-surveys.md). Both
are tenant-scoped (`organization_id`).

The same migration publishes the `analytics.attrition.view` / `analytics.attrition.manage`
permissions and grants them to existing roles the way `OrganizationProvisioner` does for
a new tenant: both to every HR Manager, view to every Department Head.

## `attrition_risk_runs`

One batch assessment: every active employee scored through the attrition model at a
point in time, with a summary. Addressed by hashid in URLs (`?run=…`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `generated_by` | FK → users, nullable | Who triggered it; `nullOnDelete`. |
| `status` | string | `completed \| failed`. Only completed runs are persisted today. Indexed. |
| `model_version` | string, nullable | e.g. `RandomForestClassifier@2026-09-24T17:04:10`, from the inference service. Server-side only — never sent to the page. |
| `employees_scored` | unsigned int | How many employees the run scored. |
| `high_count` / `medium_count` / `low_count` | unsigned int | Tier tallies (High risk / At watch / Stable). |
| `average_score` | decimal(5,2), nullable | Mean risk (0–100) across the run. |
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
| `probability` | decimal(6,5) | Raw model output (0–1). A relative score, not a calibrated probability — see the module doc. |
| `score` | decimal(5,2) | Presentation risk score (0–100 = probability × 100). |
| `tier` | string | `low \| medium \| high` (cut at 0.33 / 0.66). Indexed. |
| `confidence` | decimal(4,3) | Share of the model's 8 inputs grounded in the employee's own record (0–1). |
| `factors` | json, nullable | Up to six `[{feature, label, impact, direction}]` — each input's what-if-typical effect on the probability. |
| `features` | json, nullable | Snapshot of the feature vector sent to the model (exact ERP values), for audit. |
| timestamps | | |

**Indexes:** unique `(attrition_risk_run_id, employee_id)` — one score per employee per
run; `tier`.

> The scores are **derived by the model**, never entered. The header's tallies and
> averages are computed from the lines at run time by
> `App\Support\Ml\AttritionRiskAssessor`.
