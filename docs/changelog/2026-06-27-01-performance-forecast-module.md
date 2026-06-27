# Performance Forecast module

Adds the **Performance Forecast** module (ERD §10) — the second **Predictive
Workforce Analytics** surface after [Promotion Readiness](../modules/promotion-readiness.md).
HR runs a **forecast** that projects every active employee's **next-period
performance rating** (0–100) through the trained Gradient-Boosting model served by
the FastAPI inference service, producing a **Below / On track / Exceeds** band, a
**confidence** grounded in how much real history fed it, and a **rating
trajectory**. Reuses the ADR 0017 architecture wholesale (no change to the Python
service) and wires the existing sidebar placeholder. See
[ADR 0018](../decisions/0018-performance-forecasting.md),
[module doc](../modules/performance-forecast.md) and
[performance-forecast tables](../database/performance-forecast-tables.md).

## Highlights

- **Forecast roster.** `/analytics/performance-forecast` shows a service-connectivity
  strip, metric cards (forecasted, exceeding, average rating, average confidence),
  the **target period** being forecast, and the **ranked roster** — every active
  employee by predicted rating, with their band and movement vs. their last cycle.
  Search by employee, filter by band, switch between historical runs.
- **Honest, forward-looking detail.** Selecting an employee opens a dialog with the
  predicted rating, band and confidence, a **trajectory chart** (past actual ratings
  → the dashed forecast point) and a panel of the **real HR signals** the forecast
  rests on — the regressor's stand-in for Promotion Readiness's factor bars.
- **Graceful when offline.** The inference service is optional: existing forecasts
  stay visible and only *new* runs are blocked, with a banner + the start command.

## Backend

- **Migration** `…_create_performance_forecast_tables`: `performance_forecast_runs`
  (header — generated_by, `target_period_id` → evaluation_periods, status,
  model_version, employees_scored, band counts, average_rating, average_confidence)
  and `performance_forecasts` (line — predicted_rating, confidence, band, features,
  history; unique per employee per run). Tenant-scoped, header-plus-lines like
  promotion_readiness.
- **Models** `PerformanceForecastRun` (HasHashid, `STATUSES`, `forecasts`,
  `generator`, `targetPeriod`, `latestFirst`) + `PerformanceForecast` (`BANDS`,
  `run`, `employee`, `ranked`).
- **Support** `App\Support\Ml\PerformanceForecaster` — the single source of truth
  (gather → map → score → persist). **Reuses `PromotionFeatureMapper`** (the
  promotion and performance models share a feature space), targets the **next
  non-closed evaluation period**, and derives **band** (cut at 60 / 80) and
  **confidence** (share of key inputs grounded in real HR data) since the regressor
  returns no probability/tier/factors. Activity-logged (`logName: 'performance-forecast'`).
- **Controllers** `Analytics\PerformanceForecastController` (index) +
  `…RunController` (store / destroy). Thin; store delegates to the forecaster and
  degrades on `MlException`.
- **Resources** `PerformanceForecastRunResource` (+ derived target-period summary)
  and `PerformanceForecastScoreResource` (resolved to a plain list, like the
  promotion resource).
- **Routes** `performance-forecast.*` added to `routes/analytics.php`. Permissions
  `analytics.performance.view` / `analytics.performance.manage` added to
  `PermissionRegistry` (Predictive Analytics group); built-in HR Manager granted
  both in `OrganizationProvisioner`.
- **Inference service** (`model/api/registry.py`): one latent bug fix — the
  `contributions()` explanation path hard-coded the final pipeline step name `"clf"`
  (only the classifiers use it; the performance regressor's step is `"reg"`), so it
  `KeyError`-ed before its `hasattr(…, "coef_")` guard. Now takes the final estimator
  positionally (`steps[-1][1]`): classifiers still return factors, the regressor
  returns none. `MlClient` is unchanged.

## Frontend

- **Feature** `features/performance-forecast` (types, routes, api, constants with
  band meta + rating/confidence formatters + the model-version helper, components:
  forecast stats, service banner, band badge, **trajectory chart** (pure inline SVG,
  no chart dependency), employee detail dialog with the grounded-inputs grid).
- **Page** `pages/analytics/performance-forecast` (header, service banner, stats,
  run meta + target period + history selector, search / band filter, ranked roster,
  empty state).
- **Sidebar** the Performance Forecast placeholder gated by
  `analytics.performance.view`.

## Notes

- Verified: `php -l`, Pint (`passed`), `tsc`, ESLint and Prettier (the new files),
  and `vite build` all green (the `performance-forecast` chunk emitted). Migration
  applied on Postgres; a tinker run with a **stubbed `MlClient`** confirmed the
  forecaster scores all active employees, picks the next non-closed period, derives
  bands (counts reconcile) + confidence, snapshots features + history, and that
  deleting a run cascades its lines. The **live** service then surfaced the
  `contributions()` step-name bug above (the stub had masked it); after the fix,
  `/predict/performance` returns the predicted rating with no factors, and the
  promotion classifier still returns its factors (regression-checked). Pest was
  **not** run locally (no `pdo_sqlite`). The running uvicorn process must be
  restarted to pick up the `registry.py` fix.
- Out of scope this cut: scheduled re-forecasting, writing a forecast back onto the
  employee/evaluation, an assistant capability, and per-feature attribution for the
  regressor (the trajectory + grounded-input panel stand in) — matching the
  Promotion Readiness precedent.
