# Promotion Readiness

The first **Predictive Workforce Analytics** surface. HR runs an **assessment** that
scores every active employee for promotion readiness using a trained machine-learning
model, producing a 0–100 **readiness score**, a **Low / Medium / High** tier, and the
**factors** behind each score — to support fair, evidence-based advancement and
succession planning. Predictions come from the standalone **ML inference service**
(FastAPI, see `model/api`), called server-side; everything is tenant-scoped (ADR 0005).
See [ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md) for the design
and [promotion-readiness tables](../database/promotion-readiness-tables.md) for the schema.

> Status: **Active** · Route prefix: `/analytics/promotion-readiness`
> Sidebar: Analytics & AI → Promotion Readiness (gated by `analytics.promotion.view`)

## Surfaces

- **`/analytics/promotion-readiness`** — the overview: a connectivity strip for the
  inference service, headline metric cards (assessed, promotion-ready, developing,
  average readiness), then the **ranked roster** — every active employee by readiness
  score, with their tier and strongest positive factor. Search by employee, filter by
  tier, and pick a past run from the history selector. HR can **run a new assessment**
  or **delete** a historical one. Selecting an employee opens a **detail dialog**: the
  score, the model probability, and the contributing factors (green push toward
  readiness, red pull away) as diverging bars.

## How an assessment works

`App\Support\Ml\PromotionReadinessAssessor` is the single source of truth for "assess
promotion readiness":

1. Gather all **active** employees (with department, scored performance evaluations, and
   promotion history eager-loaded).
2. `App\Support\Ml\PromotionFeatureMapper` maps each employee to the model's feature
   space from data the HR system actually holds — **tenure** (`date_hired`, last
   promotion), **performance history** (the latest scored evaluations, 1–5 → the model's
   scales), **certifications**, **salary**, and **employment type**. Everything else the
   model expects is left to the pipeline's own imputers, and **demographic / protected
   attributes are never sent**.
3. The batch is scored by the inference service (`MlClient::predict('promotion', …)`).
4. The result is persisted as a `PromotionReadinessRun` header (model version + tier
   counts + average) with one `PromotionReadinessScore` per employee (probability,
   score, tier, factors, and a snapshot of the features sent, for audit). The run is
   activity-logged.

If the inference service is unreachable, the action degrades gracefully — a friendly
toast with the start command, no run recorded — and the page shows an offline banner;
existing assessments stay visible.

## The model

A **Logistic Regression** promotion classifier (chosen for calibrated probabilities and
transparent, auditable per-employee coefficients — see the model justification). The
service returns each prediction's **factor contributions** (the signed logit terms),
which is what the detail dialog renders. Scores bucket into tiers at 0.33 / 0.66.

## Permissions

`analytics.promotion.view` (the overview & detail), `analytics.promotion.manage` (run /
delete an assessment). Built-in **HR Manager** gets both; Super Admin bypasses all gates.

## Out of scope (this cut)

The Attrition Prediction and Performance Forecast surfaces (the inference service already
serves both models in the same shape), scheduled/automatic re-assessment, writing a
recommendation back onto the employee record, and an assistant capability.
