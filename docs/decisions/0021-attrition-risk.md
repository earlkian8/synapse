# 0021 — Attrition Risk: the third analytics surface, and an ERP-servable model

- **Status:** Superseded — the trained model, its FastAPI slot, and the
  `attrition_risk_runs` / `attrition_risk_scores` tables were **removed**; Attrition
  Risk is now a frontend-only demo surface. See
  [ADR 0030](./0030-attrition-risk-frontend-only.md).
- **Date:** 2026-06-28 (superseded 2026-09-02)
- **Related:** [Attrition Risk module](../modules/attrition-risk.md),
  [ERD §10](../database/erd.md),
  [0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md)
  (establishes the FastAPI service, `MlClient`, the assessor/mapper pattern and the
  header-plus-lines shape this reused),
  [0018 — Performance Forecasting](./0018-performance-forecasting.md) (the
  non-linear-model precedent: derived confidence in lieu of factor attribution),
  Attendance ([ADR 0010](./0010-attendance-and-mobile-api.md)) — source of the
  overtime signal.

> This ADR is retained as a tombstone so historical cross-references still resolve.
> The architecture it recorded (trained Random Forest, persisted runs/scores, a
> Laravel assessor calling the FastAPI service) no longer exists — see
> [ADR 0030](./0030-attrition-risk-frontend-only.md) for why and what replaced it.

## Context

[ADR 0017](./0017-predictive-analytics-and-ml-inference.md) stood up the inference
service and declared `attrition` as one of the three model slots; the sidebar carried
an (ungated) **Attrition Predictions** placeholder. Two things had blocked shipping
it:

1. **The data had no signal.** The bundled `employee_attrition_dataset_10000.csv` was
   synthetic with a near-random target (ROC-AUC ≈ 0.5). A correct pipeline on
   meaningless labels is a meaningless model.
2. **The obvious replacement wasn't ERP-servable.** The canonical IBM HR Attrition
   dataset (`attrition-v2.csv`) carries real signal (ROC-AUC ≈ 0.80 on all 30
   columns) but trains on fields the ERP cannot produce at inference time — pay rates
   (`DailyRate`/`MonthlyRate`/`HourlyRate`), equity (`StockOptionLevel`),
   `BusinessTravel`, and survey-only satisfaction scores. A model is only deployable
   if the live system can feed it the columns it was trained on.

## Decision

**1. Train on an ERP-servable feature set ("variant C").** The attrition model is
trained on `attrition-v2.csv` but restricted to the **17 columns the Synapse ERP can
actually supply**: `Age, Department, JobRole, JobLevel, MonthlyIncome, OverTime,
PerformanceRating, YearsAtCompany, YearsInCurrentRole, YearsSinceLastPromotion,
YearsWithCurrManager, TotalWorkingYears, TrainingTimesLastYear, Education,
EducationField, NumCompaniesWorked, DistanceFromHome`. We deliberately exclude the
pay-rate/equity/travel columns (not tracked), the survey satisfaction scores (Synapse
runs no engagement survey), the **protected attributes** `Gender`/`MaritalStatus`
(fairness — they must not drive a retention flag), and the constant book-keeping
columns. This costs ≈ 0.06 ROC-AUC (0.80 → **0.74**) in exchange for an input
contract that matches live ERP data — the right trade for production. The model
writes a `feature_contract.json` (columns, categorical levels, tuned threshold, tier
cut-points) as the serving source of truth.

**2. Reuse the ADR 0017 architecture wholesale.** New `attrition_risk_runs` (header)
+ `attrition_risk_scores` (lines), a thin `AttritionRiskController` + `…RunController`,
two API resources, three routes under `/analytics/attrition`, and a canonical
`App\Support\Ml\AttritionRiskAssessor` (gather → map → score → persist), all mirroring
Promotion Readiness / Performance Forecast. The FastAPI service needed **no change** —
its registry already declared the `attrition` classifier slot and auto-loads the
artifact.

**3. A dedicated feature mapper grounded in real modules.**
`App\Support\Ml\AttritionFeatureMapper` (not a reuse of `PromotionFeatureMapper` — the
attrition model has a different feature space and column vocabulary) maps an employee
to the model's columns from data the HR system holds: `MonthlyIncome` (basic salary),
an `OverTime` flag derived from the **last 90 days of attendance** (`overtime_minutes`
summed via `withSum`, thresholded), tenure and promotion cadence, the latest
performance rating, a last-year **training count** (`withCount`), and department.
Unknown department/role values are neutralised by the model's
`handle_unknown="ignore"` encoder; everything ungrounded is imputed. Protected and
non-servable attributes are never sent.

**4. Derive `confidence` Laravel-side; no factor attribution.** The Random Forest
returns a probability and tier but no per-instance contributions (the linear
promotion model's `coef_` decomposition does not apply, exactly as for the
performance regressor). So, following ADR 0018, **confidence (0–1)** is the share of
the model's **key inputs** grounded in the employee's own recorded HR data; the detail
view pairs it with the grounded feature snapshot as the honest, auditable "why". There
is **no `factors` column**.

**5. Inverted risk semantics in the UI.** High score = high flight risk = a *bad*
outcome, so the tier palette is inverted versus Promotion Readiness (high → rose,
medium → amber, low → emerald) and the tiers read **High risk / At watch / Stable**.
The overview adds a cohort **risk-distribution bar**.

**6. New permissions.** `analytics.attrition.view` / `analytics.attrition.manage`
added to the **Predictive Analytics** group; HR Manager granted both; the sidebar
placeholder renamed to **Attrition Risk** and gated on `analytics.attrition.view`.

## Consequences

- **A deployable model, not just an accurate one.** Restricting training to servable
  columns is the load-bearing decision: the same 17-column contract is what the mapper
  produces and the service expects, so scores are honest about their inputs.
- **Consistency, fast.** The third analytics surface lands with zero inference-service
  change and reads as a sibling of the other two.
- **Honest uncertainty.** Confidence reflects data coverage, not a fabricated
  statistic; the stored feature snapshot keeps every score auditable.
- **A known ceiling, and the real next step.** 0.74 ROC-AUC on a public dataset is the
  floor, not the goal. The genuine win is **retraining on the organisation's own
  leavers** — the offboarding/separation history (ADR 0016) is real, in-domain ground
  truth — once enough exits accrue. Until then the public model is a reasonable,
  clearly-labelled starting point.
- **Out of scope this cut:** scheduled re-assessment, writing a risk flag back onto
  the employee record, an assistant capability, per-feature attribution for the tree
  model, and the offboarding-data retraining above.
