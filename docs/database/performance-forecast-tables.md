# Database: performance forecast tables

The tables behind the [Performance Forecast module](../modules/performance-forecast.md),
created by `…_create_performance_forecast_tables`. A header
(`performance_forecast_runs`) plus its per-employee lines (`performance_forecasts`) —
mirroring the `promotion_readiness_runs` + `promotion_readiness_scores` shape
([ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md)) and, beneath
that, `performance_evaluations` + `performance_scores`. See
[ADR 0018](../decisions/0018-performance-forecasting.md). Both are tenant-scoped
(`organization_id`).

## `performance_forecast_runs`

One batch forecast: every active employee projected through the performance model at
a point in time, with a summary and the evaluation period it targets. Addressed by
hashid in URLs (`?run=…`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `generated_by` | FK → users, nullable | Who triggered it; `nullOnDelete`. |
| `target_period_id` | FK → evaluation_periods, nullable | The period being forecast (next non-closed cycle); `nullOnDelete`. |
| `status` | string | `completed \| failed`. Only completed runs are persisted today. Indexed. |
| `model_version` | string, nullable | e.g. `HistGradientBoostingRegressor (monotone) + Mondrian conformal@2026-09-26T00:35:32`, from the inference service. Kept for audit; not sent to the browser. |
| `employees_scored` | unsigned int | How many employees the run scored. |
| `exceeds_count` / `on_track_count` / `below_count` | unsigned int | Band tallies (predicted rating ≥80 / 60–79 / <60). |
| `average_rating` | decimal(5,2), nullable | Mean predicted rating (0–100) across the run. |
| `average_confidence` | decimal(4,3), nullable | Mean confidence (0–1) across the run — the average chance each forecast's band is right. |
| `unassessed` | json, nullable | `[{employee_id, reason}]` — active employees the model declined: `no_appraisal`, `appraisal_in_progress` (only drafts) or `none_before_period` (only appraised in the period being forecast). [ADR 0045](../decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md). |
| `note` | text, nullable | Reserved for failure detail. |
| timestamps | | |

**Indexes:** `status`.

## `performance_forecasts`

One employee's result within a run.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `performance_forecast_run_id` | FK → performance_forecast_runs | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. |
| `predicted_rating` | decimal(5,2) | Model-predicted next-period rating (0–100). |
| `predicted_low` / `predicted_high` | decimal(5,2), nullable | The range four in five next ratings land in (conformal); null on runs from before ADR 0045. |
| `confidence` | decimal(4,3) | The chance (0–1) the next rating lands in `band`. (Before ADR 0045: the share of inputs present.) |
| `band` | string | `below \| on_track \| exceeds` (cut at 60 / 80), as the model names it. Indexed. |
| `features` | json, nullable | Snapshot of the feature vector sent to the model, for audit (protected attributes never included). |
| `history` | json, nullable | The completed appraisals the forecast could read `[{label, rating}]` (attainment 0–100, oldest→newest), powering the trajectory chart. |
| `warnings` | json, nullable | Notes about any input held at the model's trained range. |
| timestamps | | |

**Indexes:** unique `(performance_forecast_run_id, employee_id)` — one forecast per
employee per run; `band`.

> The ratings are **derived by the model**, never entered. Each line's rating, range,
> band and confidence come from the inference service as given; the header's counts and
> averages are computed at run time by `App\Support\Ml\PerformanceForecaster`. Only
> employees the model forecast get a line — the rest are in the header's `unassessed`. Maps the ERD's `performance_forecasts`
> (`predicted_rating`, `confidence`, `features`, `target_period_id`) onto the
> proven header-plus-lines shape; `ml_model_id` is recorded as the `model_version`
> string the service reports, as in Promotion Readiness.
