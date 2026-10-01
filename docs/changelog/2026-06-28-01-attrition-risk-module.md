# Attrition Risk module

Adds the **Attrition Risk** module (ERD §10) — the third **Predictive Workforce
Analytics** surface after [Promotion Readiness](../modules/promotion-readiness.md) and
[Performance Forecast](../modules/performance-forecast.md). HR runs an **assessment**
that scores every active employee's **flight risk** (0–100) through a trained
Random-Forest model served by the FastAPI inference service, producing a **Stable /
At watch / High risk** tier, a **confidence** grounded in how much real HR data fed
it, and the **signals** behind it. The model is deliberately trained on the
**ERP-servable feature set** so its input contract matches live ERP data. Reuses the
ADR 0017 architecture wholesale (no change to the Python service) and wires the
existing sidebar placeholder. See [ADR 0021](../decisions/0021-attrition-risk.md),
[module doc](../modules/attrition-risk.md) and
[attrition-risk tables](../database/attrition-risk-tables.md).

## Highlights

- **Risk roster.** `/analytics/attrition` shows a service-connectivity strip, metric
  cards (assessed, high risk, at watch, average risk) plus a **cohort
  risk-distribution bar**, and the **ranked roster** — every active employee by
  flight-risk score, with their tier and a key signal. Search by employee, filter by
  tier, switch between historical runs.
- **Honest, auditable detail.** Selecting an employee opens a dialog with the risk
  score, tier and model probability of leaving, a **confidence** meter, and the
  **real HR signals** the score rests on — the tree model's stand-in for Promotion
  Readiness's factor bars. Inverted palette (high risk → rose, stable → emerald).
- **Graceful when offline.** The inference service is optional: existing assessments
  stay visible and only *new* runs are blocked, with a banner + the start command.

## Model

- The bundled synthetic attrition dataset (ROC-AUC ≈ 0.5, no signal) is replaced by
  `model/attrition-v2.csv` (IBM HR Analytics, 1,470 rows, ~16% leave). To stay
  deployable, the model (`model/build_notebooks.py`) trains on **only the 17 columns
  the ERP can supply** — excluding pay rates, equity, business travel, survey-only
  satisfaction scores, the protected attributes `Gender`/`MaritalStatus` (fairness),
  and constant columns. Test **ROC-AUC ≈ 0.74** (vs ≈ 0.80 for the full 30-column
  set — the accuracy traded for servability). A `feature_contract.json` (columns,
  levels, tuned threshold, tier cut-points) is written as the serving source of
  truth. `README.md` + `MODEL-JUSTIFICATION.md` updated.

## Backend

- **Migration** `…_create_attrition_risk_tables`: `attrition_risk_runs` (header —
  generated_by, status, model_version, employees_scored, tier counts, average_score,
  average_confidence) and `attrition_risk_scores` (line — probability, score, tier,
  confidence, features; unique per employee per run). Tenant-scoped,
  header-plus-lines like promotion_readiness. **No `factors` column** (the RF model
  exposes none).
- **Models** `AttritionRiskRun` (HasHashid, `STATUSES`, `scores`, `generator`,
  `latestFirst`) + `AttritionRiskScore` (`TIERS`, `run`, `employee`, `ranked`).
  Employee gains an `attritionRiskScores` relation.
- **Support** `App\Support\Ml\AttritionFeatureMapper` (employee → the 17-column
  feature space: basic salary, an **OverTime** flag from the last 90 days of
  attendance, tenure, promotion cadence, latest rating, last-year training count,
  department; protected/non-servable attributes never sent) and
  `App\Support\Ml\AttritionRiskAssessor` — the single source of truth (gather → map →
  score → persist), deriving **confidence** (share of key inputs grounded in real
  data) since the RF returns no probability-factor breakdown. Uses `withSum` /
  `withCount` for the overtime + training aggregates. Activity-logged
  (`logName: 'attrition-risk'`).
- **Controllers** `Analytics\AttritionRiskController` (index) + `…RunController`
  (store / destroy). Thin; store delegates to the assessor and degrades on
  `MlException`.
- **Resources** `AttritionRiskRunResource` and `AttritionRiskScoreResource` (resolved
  to a plain list, like the promotion resource).
- **Routes** `attrition.*` added to `routes/analytics.php` (`/analytics/attrition`).
  Permissions `analytics.attrition.view` / `analytics.attrition.manage` added to
  `PermissionRegistry` (Predictive Analytics group); built-in HR Manager granted both
  in `OrganizationProvisioner`.
- **Inference service** — **unchanged**: `registry.py` already declared the
  `attrition` classifier slot and auto-loads the new artifact at start-up.

## Frontend

- **Feature** `features/attrition-risk` (types, routes, api, constants with the
  **inverted** risk palette + score/confidence formatters + the input-field map,
  components: risk stats + distribution bar, service banner, risk badge, employee
  detail dialog with the grounded-inputs grid).
- **Page** `pages/analytics/attrition` (header, service banner, stats, run meta +
  history selector, search / tier filter, ranked roster, empty state).
- **Sidebar** the placeholder renamed **Attrition Risk** and gated by
  `analytics.attrition.view`.

## Notes

- Verified: `php -l`, Pint (`passed`), `tsc`, ESLint and Prettier (the new files), and
  `vite build` all green (the `attrition` chunk emitted). Migration applied on
  Postgres; permissions re-synced. A **live** end-to-end run (uvicorn on :8001 + a
  tinker call to the assessor) scored all 24 active employees, derived the OverTime
  flag from attendance, computed confidence (counts + averages reconcile), snapshotted
  features, and the controller rendered the Inertia props matching the TS types.
  `/predict/attrition` discriminates sensibly (a new low-paid overtime profile scores
  far higher than a tenured, well-paid, trained one). Pest was **not** run locally (no
  `pdo_sqlite`). Model artifacts are git-ignored — the inference service must have the
  trained `artifacts/attrition/` present (re-run the notebook) to serve.
- Out of scope this cut: scheduled re-assessment, writing a risk flag back onto the
  employee record, an assistant capability, per-feature attribution for the tree
  model, and retraining on the organisation's own offboarding/leaver history (the
  natural next step — real in-domain ground truth — once enough exits accrue).
