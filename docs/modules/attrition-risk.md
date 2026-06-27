# Attrition Risk

The third **Predictive Workforce Analytics** surface (alongside
[Promotion Readiness](./promotion-readiness.md) and
[Performance Forecast](./performance-forecast.md)). HR runs an **assessment** that
scores every active employee's **flight risk** (0–100) using a trained
machine-learning model, producing a **Stable / At watch / High risk** tier, a
**confidence** grounded in how much real HR data fed the score, and the **signals**
behind it — so retention can happen *before* a resignation, not after. Predictions
come from the standalone **ML inference service** (FastAPI, see `model/api`), called
server-side; everything is tenant-scoped (ADR 0005). See
[ADR 0021](../decisions/0021-attrition-risk.md) for the design and
[attrition-risk tables](../database/attrition-risk-tables.md) for the schema.

> Status: **Active** · Route prefix: `/analytics/attrition`
> Sidebar: Analytics & AI → Attrition Risk (gated by `analytics.attrition.view`)

## Surfaces

- **`/analytics/attrition`** — the overview: a connectivity strip for the inference
  service, headline metric cards (assessed, high risk, at watch, average risk) plus
  a **cohort risk-distribution bar**, then the **ranked roster** — every active
  employee by flight-risk score, with their tier and a key signal (works overtime /
  confidence). Search by employee, filter by tier, and pick a past run from the
  history selector. HR can **run a new assessment** or **delete** a historical one.
  Selecting an employee opens a **detail dialog**: the risk score, the tier, the
  model probability of leaving, the **confidence**, and the **real HR signals** the
  score rests on.

## How an assessment works

`App\Support\Ml\AttritionRiskAssessor` is the single source of truth for "assess
attrition risk":

1. Gather all **active** employees (department, scored performance evaluations and
   promotion history eager-loaded; recent **overtime minutes** summed and last-year
   **training count** counted as aggregates, so no raw rows are loaded).
2. Each employee is mapped to the model's feature space by
   `App\Support\Ml\AttritionFeatureMapper` from data the HR system actually holds:
   **monthly income** (basic salary), an **overtime** flag (derived from the last 90
   days of attendance), **tenure**, **promotion cadence** (years since last
   promotion / in current role), **latest performance rating**, **recent training**
   count, and **department**. Everything else the model expects is left to the
   pipeline's own imputers, and **pay rates, equity, engagement surveys and
   demographic / protected attributes are never sent**.
3. The batch is scored by the inference service (`MlClient::predict('attrition', …)`).
4. The result is persisted as an `AttritionRiskRun` header (model version + tier
   counts + averages) with one `AttritionRiskScore` per employee (probability, 0–100
   score, tier, confidence, and a feature snapshot for audit). The run is
   activity-logged.

If the inference service is unreachable, the action degrades gracefully — a friendly
toast with the start command, no run recorded — and the page shows an offline
banner; existing assessments stay visible.

## The model

A **Random-Forest classifier** (`RandomForestClassifier`) that predicts the
probability of attrition, presented as a 0–100 risk score (test ROC-AUC ≈ 0.74). It
is deliberately trained on the **ERP-servable feature set** — only the 17 columns the
HR system can actually supply at inference time (see `model/build_notebooks.py` and
`model/README.md`). That trades a little accuracy (≈ 0.80 → ≈ 0.74 vs the full
30-column model) for an input contract that matches live ERP data; the model's
`feature_contract.json` records the exact columns, levels, tuned threshold and tier
cut-points.

Like the performance regressor — and unlike the linear promotion classifier — the
Random Forest returns a probability and tier but **no per-instance factor
contributions**. Two things stand in for the per-employee "why":

- **Tier** — the probability bucketed at **0.33** and **0.66** (`low` / `medium` /
  `high`), mirroring the other analytics surfaces. The palette is **inverted** here:
  high risk is a bad outcome, so high → rose, low → emerald.
- **Confidence (0–1)** — the share of the model's **key inputs** grounded in the
  employee's own recorded HR data (vs. imputed). A thinner record (e.g. a brand-new
  hire) yields a less certain score. The detail view pairs it with the **grounded
  feature snapshot** — an honest, auditable substitute for factor attribution the
  tree ensemble does not expose.

## Permissions

`analytics.attrition.view` (the overview & detail), `analytics.attrition.manage`
(run / delete an assessment). Built-in **HR Manager** gets both; Super Admin bypasses
all gates.

## Out of scope (this cut)

Scheduled/automatic re-assessment, writing a risk flag back onto the employee record,
an assistant capability, per-feature attribution for the tree model (the confidence +
grounded-input panel stand in for it), and retraining the model on the organisation's
own historical leavers (the offboarding/separation data) — the natural next step once
enough exit history accrues.
