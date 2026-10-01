# Database: model graduation tables

The table behind [model graduation](../modules/model-graduation.md), created by
`2026_09_27_000000_create_local_models`, plus the column that ties each predictive
surface's runs to it. See
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md).
Tenant-scoped (`organization_id`).

## `local_models`

One attempt to train a surface's model on the organisation's own records — and, when it
passed its check, the model the inference service stored for it. Addressed by hashid in
URLs (`graduation/{localModel}/activate`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; `cascadeOnDelete`. |
| `model` | string(32) | `promotion \| performance \| attrition` — the surface, and the inference service's model name. |
| `status` | string(16) | `failed` (nothing stored) · `ready` (passed; can be switched to — at most one per surface) · `active` (scoring the surface — at most one) · `retired` (switched away from, or superseded before use). |
| `version` | string(64), nullable | The service's version for the stored model (`YYYYMMDDHHMMSS-xxxxxx`); null when it failed. The service files it under `artifacts/local/org-<organization_id>/<model>/<version>/`. |
| `examples` | unsigned int | Labelled examples it was trained and checked on. |
| `counts` | json, nullable | The volumes behind them, as the service counted them (e.g. `promoted`, `not_promoted`, `people`). A promotion run scored by this model reads its promotion rate from here. |
| `comparison` | json, nullable | What it was judged on: `metric` (`brier` / `mae` / `roc_auc`), `local`, `reference`, `baseline`, `wins_over_reference`, `wins_over_baseline`, `required_share`, and for a forecast `coverage` / `promised_coverage`. |
| `findings` | json, nullable | One plain-language sentence per check, shown to HR as written. |
| `trained_by` | FK → users, nullable | `nullOnDelete`. |
| `activated_by` | FK → users, nullable | Who switched the surface to it; `nullOnDelete`. |
| `activated_at` / `retired_at` | timestamp, nullable | |
| timestamps | | `created_at` is when it was trained. |

**Indexes:** `(organization_id, model, status)`.

## `local_model_id` on each surface's runs

`promotion_readiness_runs`, `performance_forecast_runs` and `attrition_risk_runs` each
gain a nullable `local_model_id` (FK → `local_models`, `nullOnDelete`): the
organisation's own model that scored the run, or null for the general model. The run
resources expose it as `scored_by: 'own' | 'general'`.
