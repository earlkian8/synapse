# Performance Forecast

The second **Predictive Workforce Analytics** surface (after
[Promotion Readiness](./promotion-readiness.md)). HR runs a **forecast** that
projects every active employee's **next-period performance rating** (0–100) using a
trained machine-learning model, producing a **Below / On track / Exceeds** band, a
**confidence** grounded in how much real history fed the forecast, and the
employee's **rating trajectory** behind it — to plan reviews, coaching and
development ahead of the cycle. Predictions come from the standalone **ML inference
service** (FastAPI, see `model/api`), called server-side; everything is
tenant-scoped (ADR 0005). See
[ADR 0018](../decisions/0018-performance-forecasting.md) for the design and
[performance-forecast tables](../database/performance-forecast-tables.md) for the
schema.

> Status: **Active** · Route prefix: `/analytics/performance-forecast`
> Sidebar: Analytics & AI → Performance Forecast (gated by `analytics.performance.view`)

## Surfaces

- **`/analytics/performance-forecast`** — the overview: a connectivity strip for
  the inference service, headline metric cards (forecasted, exceeding, average
  rating, average confidence), the **target period** being forecast, then the
  **ranked roster** — every active employee by predicted rating, with their band
  and movement vs. their last cycle. Search by employee, filter by band, and pick a
  past run from the history selector. HR can **run a new forecast** or **delete** a
  historical one. Selecting an employee opens a **detail dialog**: the predicted
  rating, the band, the confidence, a **trajectory chart** (past actual ratings →
  the dashed forecast point), and the **real HR signals** the forecast rests on.

## How a forecast works

`App\Support\Ml\PerformanceForecaster` is the single source of truth for "forecast
performance":

1. Gather all **active** employees (with department, scored performance evaluations
   — and their periods — and promotion history eager-loaded).
2. The forecast targets the **next non-closed evaluation period** (the soonest
   cycle that is not yet `closed`), stored on the run; `null` when none exists.
3. Each employee is mapped to the model's feature space, **reusing
   `App\Support\Ml\PromotionFeatureMapper`** — the promotion and performance models
   share a feature space (same source dataset) — from data the HR system actually
   holds: **tenure**, **performance history** (latest scored evaluations, 1–5 → the
   model's scales), **certifications**, **salary** and **employment type**.
   Everything else the model expects is left to the pipeline's own imputers, and
   **demographic / protected attributes are never sent**.
4. The batch is scored by the inference service (`MlClient::predict('performance', …)`).
5. The result is persisted as a `PerformanceForecastRun` header (model version +
   target period + band counts + averages) with one `PerformanceForecast` per
   employee (predicted rating, confidence, band, a feature snapshot for audit, and
   the rating history for the trajectory chart). The run is activity-logged.

If the inference service is unreachable, the action degrades gracefully — a
friendly toast with the start command, no run recorded — and the page shows an
offline banner; existing forecasts stay visible.

## The model

A **Gradient-Boosting regressor** (`HistGradientBoostingRegressor`) that predicts
`performance_score` on a 0–100 scale (test R² ≈ 0.92, MAE ≈ 3.3). Unlike the
promotion classifier it is **not linear**, so the service returns a predicted value
but **no probability, tier or per-feature factor contributions**. Two derived
fields fill that gap, computed in the forecaster:

- **Band** — the predicted rating bucketed at **60** and **80** (`below` /
  `on_track` / `exceeds`), thresholds grounded in the dataset's quartiles.
- **Confidence (0–1)** — the share of the model's **key inputs** grounded in the
  employee's own recorded HR data (vs. imputed by the pipeline). A thinner record
  (e.g. a new hire with no evaluations) yields a less certain forecast. This is an
  honest, auditable substitute for a statistical interval the point regressor does
  not provide.

The detail view's **trajectory** (the employee's past actual ratings ending in the
dashed forecast point) is the forward-looking equivalent of Promotion Readiness's
factor bars.

## Permissions

`analytics.performance.view` (the overview & detail), `analytics.performance.manage`
(run / delete a forecast). Built-in **HR Manager** gets both; Super Admin bypasses
all gates.

## Out of scope (this cut)

Scheduled/automatic re-forecasting, writing a forecast back onto the employee
record or the evaluation, an assistant capability, and per-feature attribution for
the non-linear regressor (the trajectory + grounded-input panel stand in for it).
