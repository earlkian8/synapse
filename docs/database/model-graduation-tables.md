# Database: model graduation tables

The table behind [model graduation](../modules/model-graduation.md), created by
`2026_09_27_000000_create_local_models` (`passed` and `interpretation` added by
`2026_11_17_000000_add_interpretations_and_model_choice`), plus the column that ties
each predictive surface's runs to it. See
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md)
and [ADR 0107](../decisions/0107-a-model-that-did-not-pass-can-still-be-chosen.md).
Tenant-scoped (`organization_id`).

## `local_models`

One attempt to train a surface's model on the organisation's own records — and the model
the inference service stored for it, whether or not it passed its check (ADR 0107).
Addressed by hashid in URLs (`graduation/{localModel}/activate`, `…/dismiss`,
`…/interpretation`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; `cascadeOnDelete`. |
| `model` | string(32) | `promotion \| performance \| attrition` — the surface, and the inference service's model name. |
| `status` | string(16) | The lifecycle: `failed` (no model could be stored) · `ready` (stored, waiting for a decision — at most one per surface; the newest stored supersedes it) · `active` (scoring the surface — at most one) · `retired` (switched away from, set aside by "Keep the current model", or superseded before use). |
| `passed` | boolean, nullable | The check's verdict, apart from the lifecycle. A `ready` model that did not pass can be switched to only with `despite_check` (ADR 0107). Backfilled: `status <> 'failed'` for rows from before. |
| `version` | string(64), nullable | The service's version for the stored model (`YYYYMMDDHHMMSS-xxxxxx`); null when none could be stored. The service files it under `artifacts/local/org-<organization_id>/<model>/<version>/`, its `metrics.json` carrying the `verdict`. |
| `examples` | unsigned int | Labelled examples it was trained and checked on. |
| `counts` | json, nullable | The volumes behind them, as the service counted them (e.g. `promoted`, `not_promoted`, `people`). A promotion run scored by this model reads its promotion rate from here. |
| `comparison` | json, nullable | What it was judged on: `metric` (`brier` / `mae` / `roc_auc`), `local`, `reference`, `baseline`, `wins_over_reference`, `wins_over_baseline`, `required_share`, and for a forecast `coverage` / `promised_coverage`. |
| `findings` | json, nullable | One plain-language sentence per check, shown to HR as written. |
| `interpretation` | json, nullable | The plain-language reading of the check an LLM wrote on request (ADR 0106): `{available, headline, summary, reasons: [{key, label, met, text}], context, checks, actions, generated_at}`. Which checks there are and whether each was met come from `comparison`; only the sentences were written. Aggregates only were sent. |
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
