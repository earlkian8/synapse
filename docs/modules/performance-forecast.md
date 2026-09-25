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

- **`/analytics/performance-forecast`** — the overview: headline metric cards
  (forecasted, exceeding, average
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

If the inference service is unreachable, the action degrades gracefully — a plain
"temporarily unavailable, try again shortly" toast, no run recorded — and the page
shows the same message as a banner; existing forecasts stay visible.

Nothing about the model itself reaches the browser. `ServiceBanner` renders **only**
when the service is down, the page's `service` prop carries liveness and nothing
else, and an `MlException`'s message is written for the person who clicked the
button — never a shell command, a status code, or the service's response body,
which are logged server-side instead. This is an HR screen, not a model dashboard.

## Where these scores come from (model graduation)

The page embeds a **`ModelProvenance`** panel directly beneath its header, stating
in one line that these forecasts come from a general workforce dataset rather than this
organisation’s own appraisal history. Expanded, it shows the
three-stage lifecycle (`provisional` → `collecting` → `graduated`) with the
retraining gate drawn closed, the requirement furthest from satisfied, and the
full requirement ledger — each row opening a drill-down with the statistical
justification for its threshold.

This surface learns from **cycle-to-cycle comparisons** rather than from people,
so its headline requirement is 200 of them. Its distinctive requirement is **30
people with three or more appraisals**: a trajectory needs three points, and with
two every forecast is really last cycle restated. Its current stage is
`collecting`.

### What each score draws on

Beneath the requirement ledger, the panel lists **every input the score uses**,
with how many employee records actually carry it — grouped by whether the value
reaches the score at all:

| State | Meaning |
|---|---|
| **Used now** | Read from your records and fed into every score. |
| **Recorded, not used** | The system already holds it; wiring it in needs no new data entry. |
| **Not recorded anywhere** | No module produces it, so it cannot be filled in. |

For this surface the appraisal-derived fields matter most, and they are the
thinnest: *previous cycle's rating* covers 21 of 42 employees and *rating two
cycles back* covers none until a third cycle closes. *KPI attainment* is flagged
as repeating the appraisal overall rather than adding to it. Deadline adherence
and peer feedback are not recorded anywhere.

The middle group is the actionable one, and it is the same finding on this surface
and its sibling: attendance rate, days late, approved overtime and training
completions are recorded daily (the awards board already computes several of them)
but are not currently among the inputs. [Attrition Risk](./attrition-risk.md) is the
surface that does feed 90-day absences, lateness and overtime into its score.

The panel is **frontend-only**: counts are fabricated in the browser and persisted
to `localStorage`, and no retraining runs behind it. Only the counts are
simulated — the thresholds and their reasoning are real. Shared implementation
lives in `resources/js/features/model-graduation/`; see
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md).

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
