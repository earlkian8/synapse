# 0018 — Performance Forecast: the second analytics surface on the shared ML inference service

- **Status:** Accepted
- **Date:** 2026-06-27
- **Related:** [Performance Forecast module](../modules/performance-forecast.md),
  [performance-forecast tables](../database/performance-forecast-tables.md),
  [ERD §10](../database/erd.md),
  [0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md)
  (establishes the FastAPI service, `MlClient`, the assessor/mapper pattern and the
  header-plus-lines shape this reuses),
  Performance ([ADR 0012](./0012-performance-management.md)) — source of the
  evaluation-history features and the target periods.

## Context

[ADR 0017](./0017-predictive-analytics-and-ml-inference.md) stood up a standalone
FastAPI inference service and shipped **Promotion Readiness** as the first
Predictive Analytics surface. It explicitly left **Performance Forecast** as a fast
follow: the service *already serves the performance model in the same shape*, and
the sidebar carried an (ungated) **Performance Forecast** placeholder.

The performance model differs from the promotion one in two ways that shape the
module: it is a **regressor** (a `HistGradientBoostingRegressor` predicting
`performance_score` 0–100, test R² ≈ 0.92), and a forecast is **forward-looking** —
it predicts a *future evaluation period*, which the ERD captures as
`performance_forecasts.target_period_id`.

## Decision

**1. Reuse the ADR 0017 architecture wholesale.** New
`performance_forecast_runs` (header) + `performance_forecasts` (lines), a thin
`PerformanceForecastController` + `…RunController`, two API resources, two routes
under `/analytics`, and a canonical `App\Support\Ml\PerformanceForecaster`
(gather → map → score → persist), all mirroring Promotion Readiness. `MlClient` is
**unchanged**. The Python service needed **one latent bug fix**: its `contributions()`
explanation path hard-coded the final pipeline step name `"clf"`, which only the
classifiers use (the performance regressor's step is `"reg"`), so it `KeyError`-ed
before the `hasattr(…, "coef_")` guard could return "no factors". This branch had
never been exercised (Promotion Readiness only used the classifier). Fixed by taking
the final estimator positionally (`pipeline.steps[-1][1]`) — classifiers still
explain; the regressor correctly returns no factors.

**2. Reuse `PromotionFeatureMapper` for feature mapping.** The promotion and
performance models were trained on the **same dataset** and share a feature space,
so the existing mapper already produces valid inputs (`performance_score` is the
target, dropped from the model's features, and simply ignored if sent). No second
mapper — "reuse over reinvention".

**3. The forecast targets the next non-closed period.** A run picks the soonest
`evaluation_period` whose status is not `closed` (per the ERD's `target_period_id`)
and stores it on the header; `null` when the tenant has none. This keeps the run
forward-looking and gives the UI a real "Forecasting H2 2026 Review" label without
adding a selection step.

**4. Derive `band` and `confidence` Laravel-side, honestly.** The non-linear
regressor returns no probability, tier or factor contributions, so:
- **Band** buckets the predicted rating at **60 / 80** (`below` / `on_track` /
  `exceeds`), grounded in the dataset's quartiles.
- **Confidence (0–1)** is the **share of the model's key inputs grounded in the
  employee's own recorded HR data** (vs. pipeline-imputed). It is an auditable,
  truthful substitute for a statistical interval the point regressor cannot give —
  a new hire with no evaluation history forecasts at low confidence. It is computed
  in the forecaster, which holds the feature snapshot.

**5. The trajectory is the explanation.** Where Promotion Readiness renders signed
factor bars, the forecast stores each employee's recent **actual ratings** and the
detail view charts *past actuals → the dashed forecast point*, plus a panel of the
real signals it rests on. This is the honest, forward-looking equivalent for a
regressor with no per-feature attribution.

**6. New permissions.** `analytics.performance.view` / `analytics.performance.manage`
added to the existing **Predictive Analytics** group; HR Manager granted both; the
sidebar placeholder gated on `analytics.performance.view`.

## Consequences

- **Consistency, fast.** A second analytics surface lands with no change to the
  inference service and a shared mapper — the modules read and behave as siblings.
- **Honest uncertainty.** Confidence reflects data coverage, not a fabricated
  statistic; the stored feature snapshot + history keep every forecast auditable.
- **Coupling to note.** `PerformanceForecaster` depends on `PromotionFeatureMapper`;
  the two models sharing a feature space is the load-bearing assumption. If they
  diverge, extract a shared `EmployeeFeatureMapper` (a rename, not a redesign).
- **Out of scope this cut:** scheduled re-forecasting, writing forecasts back onto
  the employee/evaluation, an assistant capability, and per-feature attribution for
  the regressor.
