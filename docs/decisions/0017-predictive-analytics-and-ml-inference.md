# 0017 — Predictive Workforce Analytics: an external ML inference service, starting with Promotion Readiness

- **Status:** Accepted
- **Date:** 2026-06-23
- **Related:** [Promotion Readiness module](../modules/promotion-readiness.md),
  [promotion-readiness tables](../database/promotion-readiness-tables.md),
  [ERD](../database/erd.md), [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  Performance ([ADR 0012](./0012-performance-management.md)) — source of the performance
  history features,
  the agentic assistant ([implementation method §3]) — for the server-side-only external-call pattern

## Context

The sidebar's **Analytics & AI** section carried ungated placeholders for **Attrition
Predictions**, **Performance Forecast** and **Promotion Readiness**. Three scikit-learn
models were trained offline under `model/` (a Logistic-Regression promotion classifier,
a Random-Forest attrition classifier, and a Gradient-Boosting performance regressor) and
persisted as joblib pipelines under `model/artifacts/`.

The question was how a **PHP/Laravel** app consumes **Python** models, and how to surface
the first one — Promotion Readiness — as a real module.

## Decision

**1. A standalone inference service, not in-process.** The models are served by a small
**FastAPI** app (`model/api`) running on the same Python 3.14 venv that trained them.
Laravel never shells out to Python or reimplements scoring; it calls the service over
HTTP, server-side only — exactly how the assistant calls Gemini.

- `GET /health` reports which models are loaded and their headline metrics; `POST
  /predict/{model}` scores a batch of instances against `promotion | attrition |
  performance`.
- Callers send **partial features**; the service builds a full-width frame in the
  pipeline's expected column order and lets the fitted imputers fill the rest, so the HR
  app only has to supply what it knows.
- For the linear promotion model the service also returns **per-employee factor
  contributions** (the signed logit terms), giving an honest, auditable "why".
- **Protected/demographic attributes (gender, age, marital status, education, city tier)
  are excluded from explanations** — a fairness guard, and they are never sent from the
  HR side anyway.

**2. A thin Laravel client + config.** `App\Support\Ml\MlClient` (singleton bound from
`config('services.ml')`, env `ML_SERVICE_URL` / `ML_SERVICE_TIMEOUT`) wraps the calls,
mirroring `GeminiClient`. A failure raises a typed `MlException` so callers **degrade
gracefully** (offline banner + toast) instead of 500-ing.

**3. Promotion Readiness as a header-plus-lines module.** `promotion_readiness_runs`
(one batch assessment: model version + tier counts + average) and
`promotion_readiness_scores` (one per employee: probability, score, tier, factors, and a
feature snapshot), mirroring `performance_evaluations` + `performance_scores`. Scores are
**derived by the model, never entered** — like the derived `overall_score` in
Performance and the payslip totals in Payroll.

**4. A canonical assessor.** `App\Support\Ml\PromotionReadinessAssessor` owns the
gather → map → score → persist flow (the single source of truth, callable by the
controller and any future scheduled job/assistant tool), and
`App\Support\Ml\PromotionFeatureMapper` owns the employee → feature-vector mapping from
real HR data (tenure, performance history, certifications, salary, employment type).

**5. New permissions.** `analytics.promotion.view` / `analytics.promotion.manage` added
to `PermissionRegistry` under a new **Predictive Analytics** group; HR Manager granted
both; the sidebar placeholder gated on `analytics.promotion.view`.

## Consequences

- **Separation of concerns / scalability.** Python stays in Python; the service can be
  deployed, scaled or swapped (e.g. retrained models) independently of the PHP app. The
  same service already serves the other two models for the remaining Analytics surfaces.
- **Operational dependency.** The app now has an optional external process. Mitigated by
  graceful degradation: the module is fully read-only-usable when the service is down,
  and only *new* assessments are blocked.
- **Feature gap is explicit.** The promotion model was trained on a richer feature set
  than the HR system holds; the mapper supplies the meaningful signals and the pipeline
  imputes the rest. The feature snapshot stored per score keeps this auditable.
- **Out of scope this cut:** the Attrition/Performance-Forecast surfaces, scheduled
  re-assessment, writing recommendations back to the employee record, and an assistant
  capability.
