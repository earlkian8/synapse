# 0030 — Attrition Risk becomes a frontend-only demo surface

- **Status:** Accepted
- **Date:** 2026-09-02
- **Supersedes:** [ADR 0021 — Attrition Risk](./0021-attrition-risk.md)
- **Related:** [Attrition Risk module](../modules/attrition-risk.md), ERD §10
  (../database/erd.md), [0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md),
  [0018 — Performance Forecasting](./0018-performance-forecasting.md),
  [0019 — Remove Payroll and Benefits](./0019-remove-payroll-and-benefits.md) (the
  precedent for a clean module removal in this codebase).

## Context

ADR 0021 stood up Attrition Risk as the third Predictive Workforce Analytics surface:
a trained Random Forest served by the shared FastAPI inference service, persisted
`attrition_risk_runs` / `attrition_risk_scores`, a Laravel assessor/mapper pair, and
permission-gated routes — the same architecture as Promotion Readiness and
Performance Forecast.

On review, keeping a full backend (trained model artifact, dataset, FastAPI slot,
persisted tables, permissions) for what is, in this product, a **demonstration**
surface was more machinery than the module warranted. Promotion Readiness and
Performance Forecast remain genuine predictive-analytics surfaces backed by real
models and real HR data; Attrition Risk did not need the same weight to make its
point — a flight-risk view that shows what the *feature* would look like.

## Decision

**Remove the entire backend for Attrition Risk and make it a frontend-only demo.**

- **Removed:** `App\Http\Controllers\Analytics\AttritionRiskController` /
  `AttritionRiskRunController`, `App\Models\AttritionRiskRun` / `AttritionRiskScore`,
  their API resources, `App\Support\Ml\AttritionRiskAssessor` /
  `AttritionFeatureMapper`, the `attrition_risk_runs` / `attrition_risk_scores`
  migration, the `analytics.attrition.view` / `analytics.attrition.manage`
  permissions (and their role grants), the attrition entry from `MlSignals` (Reports
  no longer surfaces an attrition chip), the `attrition` slot in the FastAPI
  registry (`model/api/registry.py`), the trained model artifact
  (`model/artifacts/attrition/`), both datasets
  (`attrition-v2.csv`, `employee_attrition_dataset_10000.csv` — already
  git-ignored), the training notebook (`model/notebooks/01_attrition_model.ipynb`)
  and its `build_notebooks.py` builder, and the attrition logs.
- **Kept, rebuilt:** the `/analytics/attrition` page and the whole
  `features/attrition-risk` component set (stats cards, risk badge, employee detail
  dialog, filters, history selector). The route is now a bare
  `Route::inertia('attrition', 'analytics/attrition')` — no controller, no props,
  **ungated** (any authenticated user, like Dashboard and Reports).
- **New `features/attrition-risk/mock-engine.ts`** fabricates a stable ~46-person
  roster (seeded PRNG, so the same roster persists across reloads) and scores it
  with a small, self-consistent synthetic logistic formula — overtime, stagnant
  promotion cadence, low pay and thin training push risk up; tenure and recent
  training pull it down. It is explicitly **not** a fitted model and makes no claim
  to predictive validity.
- **Runs persist to `localStorage`**, not a database — "Run assessment" generates a
  new run (with a short simulated delay for the existing spinner UX), the history
  selector switches between stored runs, and "Delete" removes one. This preserves
  the full original UX with no backend.
- **Honesty over illusion.** The old `ServiceBanner` (FastAPI connectivity status)
  is replaced by a `DemoBanner` that says plainly this data is simulated and
  browser-generated. Copy that implied a live model ("model probability of
  leaving", "grounded in real HR data") was reworded to say "simulated".

## Consequences

- **Promotion Readiness and Performance Forecast are unaffected.** They keep their
  full backend, the FastAPI service, and their permissions; `MlClient`'s model union
  narrows to `'promotion'|'performance'`.
- **Reports loses its attrition signal chip** (`MlSignals::attrition()` removed) —
  the other two ML signals (promotion, forecast) still ride alongside the relevant
  reports.
- **No migration/rollback path.** Following the ADR 0019 precedent, the removal is
  clean: the migration file is deleted outright (not reversed), and
  `attrition_risk_*` rows are simply gone on the next `migrate:fresh`.
- **Smaller `model/` surface.** Two notebooks remain (Performance, Promotion)
  instead of three; `MODEL-JUSTIFICATION.md` and `model/README.md` updated to match.
- **A known trade-off.** The page can no longer claim a real ROC-AUC or a defensible
  per-employee "why" — it is a UI demo, not a decision-support tool. If genuine
  attrition prediction is wanted again, ADR 0021's architecture (and its note about
  retraining on the organisation's own offboarding history) is the place to restart
  from, not this demo engine.
