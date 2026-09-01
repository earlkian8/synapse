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
| `model_version` | string, nullable | e.g. `HistGradientBoostingRegressor@2026-06-23T11:49:11`, from the inference service. |
| `employees_scored` | unsigned int | How many employees the run scored. |
| `exceeds_count` / `on_track_count` / `below_count` | unsigned int | Band tallies (predicted rating ≥80 / 60–79 / <60). |
| `average_rating` | decimal(5,2), nullable | Mean predicted rating (0–100) across the run. |
| `average_confidence` | decimal(4,3), nullable | Mean confidence (0–1) across the run. |
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
| `confidence` | decimal(4,3) | Share (0–1) of the model's key inputs grounded in real HR data (vs. imputed). |
| `band` | string | `below \| on_track \| exceeds` (cut at 60 / 80). Indexed. |
| `features` | json, nullable | Snapshot of the feature vector sent to the model, for audit (protected attributes never included). |
| `history` | json, nullable | The employee's recent actual ratings `[{label, rating}]` (0–100, oldest→newest) powering the trajectory chart. |
| timestamps | | |

**Indexes:** unique `(performance_forecast_run_id, employee_id)` — one forecast per
employee per run; `band`.

> The ratings are **derived by the model**, never entered. The header's band counts
> and averages, and each line's `confidence` and `band`, are computed at run time by
> `App\Support\Ml\PerformanceForecaster`. Maps the ERD's `performance_forecasts`
> (`predicted_rating`, `confidence`, `features`, `target_period_id`) onto the
> proven header-plus-lines shape; `ml_model_id` is recorded as the `model_version`
> string the service reports, as in Promotion Readiness.
