# Promotion Readiness module + ML inference service

Adds the first **Predictive Workforce Analytics** module, **Promotion Readiness**: HR
runs an assessment that scores every active employee for advancement using a trained
**Logistic-Regression** model, producing a 0–100 readiness score, a Low/Medium/High tier
and the factors behind each score. The models are served by a new standalone **FastAPI
inference service** (`model/api`) that Laravel calls server-side — wiring the existing
Analytics & AI sidebar placeholder. See
[ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md),
[module doc](../modules/promotion-readiness.md) and
[promotion-readiness tables](../database/promotion-readiness-tables.md).

## Highlights

- **Model-driven readiness scores.** Every active employee is scored and ranked, bucketed
  into High / Medium / Low tiers, with headline metrics and per-employee drill-down.
- **Honest, fair explanations.** The linear model's per-employee factor contributions are
  surfaced as diverging bars (what pushes toward vs. away from readiness). **Protected /
  demographic attributes are excluded** from explanations and never sent to the model.
- **Server-side inference, mirroring the assistant.** Python stays in Python; Laravel
  calls the FastAPI service over HTTP via a thin client, exactly like the Gemini path.
- **Graceful degradation.** When the service is offline the page stays usable (existing
  assessments visible) and only new runs are blocked, with a clear start command.

## ML service (Python, `model/api`)

- **FastAPI app** serving the three joblib pipelines: `GET /health` (loaded models +
  metrics) and `POST /predict/{promotion|attrition|performance}` (batch scoring).
- Aligns **partial feature inputs** to each pipeline's expected columns (imputers fill the
  rest); returns probability/score/tier, plus **per-employee logit factor contributions**
  for the linear promotion model. Added `fastapi` / `uvicorn` / `pydantic` to
  `requirements.txt`.

## Backend (Laravel)

- **Migration** `…_create_promotion_readiness_tables`: `promotion_readiness_runs` (header:
  model version, tier counts, average) + `promotion_readiness_scores` (per employee:
  probability, score, tier, factors, feature snapshot; unique per run+employee). Tenant-scoped.
- **Models** `PromotionReadinessRun` (hashid), `PromotionReadinessScore`
  (+ `Employee::promotionReadinessScores`).
- **Support** `App\Support\Ml\MlClient` (+ `MlException`) — HTTP wrapper over the service;
  `PromotionFeatureMapper` (employee → feature vector); `PromotionReadinessAssessor` (the
  canonical gather → map → score → persist operation). `MlClient` bound from
  `config('services.ml')` in `AppServiceProvider`.
- **Controllers** `Analytics\PromotionReadinessController` (index) +
  `PromotionReadinessRunController` (store/destroy). Thin, gate-protected, activity-logged.
- **Resources** `PromotionReadinessRunResource`, `PromotionReadinessScoreResource`.
- **Routes** new `routes/analytics.php` (required in `web.php`). Permissions
  `analytics.promotion.view` / `analytics.promotion.manage` added to `PermissionRegistry`
  (new **Predictive Analytics** group); built-in HR Manager granted both. Config
  `services.ml` + `.env.example` (`ML_SERVICE_URL`, `ML_SERVICE_TIMEOUT`).

## Frontend

- **Feature** `features/promotion-readiness` (types, routes, api, constants, components:
  stats cards, tier badge, service banner, factor list, employee detail dialog).
- **Page** `analytics/promotion-readiness` — connectivity strip, metric cards, run-history
  selector, search + tier filters, the ranked roster, and the per-employee factor dialog.
- **Sidebar** Promotion Readiness gated on `analytics.promotion.view` (the Analytics & AI
  list now filters by permission).

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint, Prettier and `vite build` all green; routes
  registered; migration + permission sync run on Postgres. End-to-end validated via
  tinker against the live service — a real run scored 26 employees (12 high / 2 medium /
  12 low), resources serialized, and the offline path returns a typed `MlException`
  rather than hanging. The Pest suite was **not** run locally (no `pdo_sqlite`).
- The bundled attrition dataset has no learnable signal, so that surface is intentionally
  not built yet; the service already serves all three models for when it is.
