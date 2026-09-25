# 0043 — Attrition Risk is real again, trained on the attrition surveys

- **Status:** Accepted
- **Date:** 2026-09-24
- **Supersedes:** [ADR 0030 — Attrition Risk becomes a frontend-only demo](./0030-attrition-risk-frontend-only.md)
- **Related:** [Attrition Risk module](../modules/attrition-risk.md),
  [attrition-risk tables](../database/attrition-risk-tables.md),
  [0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md)
  (the service, `MlClient` and the assessor/mapper pattern reused here),
  [0018 — Performance Forecasting](./0018-performance-forecasting.md) (confidence as
  data coverage), [0021 — Attrition Risk](./0021-attrition-risk.md) (the first real
  attempt, and why its dataset was not servable),
  [0031 — Model graduation](./0031-model-graduation-frontend-only.md).

## Context

ADR 0030 reduced Attrition Risk to a browser-side demo because neither available
dataset justified a backend: the bundled synthetic set had no signal, and the IBM HR
set had signal only in columns the ERP cannot produce (pay rates, equity, travel,
engagement scores).

Two exports of a purpose-built survey now exist — `attrition-survey-1.csv` (99
responses) and `attrition-survey-2.csv` (69) — asking people about an employer they
worked for. Its nine questions were written so that **every predictor has an ERP
answer**: employment type, tenure, monthly salary, time since last promotion, and
overtime / absences / lateness over the last three months (the employee record,
promotions, and 90 days of attendance), plus how the employment ended.

## Decision

**1. One cleaning definition, shared everywhere.** `model/synapse_ml/attrition/survey.py`
merges the two exports (kept, with every dataset, under the git-ignored `model/data/`) and
is the single source of truth used by the notebook, the tests and (via the pipeline) the
service:

- keep only consenting respondents, and only the two outcomes that answer *did this
  person choose to leave?* — resigned voluntarily (`left = 1`) vs still employed
  (`left = 0`). Retirement, contract end and dismissal are a different event and are
  **excluded**, not counted as attrition (or as staying);
- drop resubmissions (identical answers within two minutes); keep identical answers
  further apart — coarse multiple-choice bands legitimately coincide for co-workers —
  but give them a shared `pattern` id so evaluation never splits them;
- read the CSVs with `keep_default_na=False` — pandas otherwise turns the answer
  "None" (no overtime) into a missing value.

Result: 168 raw rows → **155 usable** (50 left, 105 stayed); every dropped row is
counted by reason. The merged dataset is written to
`model/data/processed/attrition-survey-merged.csv`.

**2. Encode answers in ERP units, band them inside the pipeline.** Each answer becomes
a representative value in the unit the ERP records (years, ₱/month, hours, days,
times). The pipeline's first numeric step re-bands every value with the survey's own
edges (`np.digitize`), so a live employee's exact figures — 4.2 years, ₱18,500, 7.25 h
of overtime — land in precisely the band they would have ticked. The ERP never needs
to know the survey's bands, 400 hours of overtime cannot extrapolate beyond "more than
100", and the fitted pipeline is built from stock scikit-learn/NumPy callables only, so
it unpickles anywhere. The notebook asserts this parity before saving.

**3. Random Forest, chosen by comparison, evaluated honestly.** 155 rows is small, so
there is no single held-out split: every estimate is **10× repeated, grouped,
stratified 5-fold CV**. On identical folds:

| Model | CV ROC-AUC | CV PR-AUC |
|---|---|---|
| Prior-only baseline | 0.500 | 0.323 |
| Logistic Regression | 0.543 ± 0.032 | 0.408 |
| **Random Forest** | **0.628 ± 0.038** | **0.449** |
| Extra Trees | 0.626 ± 0.045 | 0.446 |
| Gradient Boosting | 0.597 ± 0.031 | 0.389 |

The trees win because the signal is in interactions and non-monotone shapes (new hires
*and* 6–10-year staff leave most), not straight lines. A **permutation test** (100
shuffles of the outcome) puts the forest at **p = 0.03** — the signal is real. Leading
drivers by held-out permutation importance: tenure, salary, time since promotion, then
absences and lateness; overtime contributes nothing measurable but is kept so the
contract matches the survey one-to-one (dropping it moved AUC within noise).

**4. Name the limit.** Trained on one survey and tested on the other, the forest scores
0.36–0.39: the two surveys reached different populations (survey 2 is mostly
long-tenured, better-paid public-sector staff who rarely resigned), and much of what
separates leavers from stayers in one does not carry to the other. The model is
therefore a **provisional, population-level prior**, which is exactly what the
model-graduation panel already says — and the retraining target remains the
organisation's own departures. The score is a **relative** risk, not a calibrated
probability (balanced class weights; a survey over-samples leavers).

**5. Reuse the ADR 0017 architecture wholesale.** `attrition_risk_runs` /
`attrition_risk_scores` (header-plus-lines, tenant-scoped), a thin
`AttritionRiskController` + `AttritionRiskRunController`, two API resources, three
routes under `/analytics/attrition`, and the canonical
`App\Support\Ml\AttritionRiskAssessor` (gather → map → score → persist, activity-logged).
Permissions `analytics.attrition.view` / `.manage` return; the migration grants them
to existing HR Managers (both) and Department Heads (view), as the provisioner now does
for new tenants. Reports regain the attrition signal chip.

**6. A mapper that only sends facts.** `AttritionFeatureMapper` counts absences
(`status = absent` — leave, rest days and holidays are not absences, matching the
survey's wording), late arrivals (`late_minutes > 0`, so a late start that became a half
day still counts) and worked overtime over the last 90 days via `withCount` /
`withSum` (tenant-scoped, driver-agnostic). An employee with **no attendance tracked**
in the window gets no attendance features at all — zeros would assert a perfect record
nobody observed — and the pipeline imputes them. Never promoted means the wait is the
whole tenure, the same substitution the training data makes.

**7. Explanations for a forest.** The service now explains non-linear classifiers by
**occlusion**: for each input, the change in probability if that input were typical
(the median / mode the pipeline's own imputers learned). Because an unsent input is
imputed to exactly that value, only recorded facts can ever be offered as a reason.
Effects under two risk points are suppressed — below that the forest's noise reads as
contradiction. Confidence (0–1) is, as in ADR 0018, the share of the eight inputs
grounded in the employee's record.

**8. "Connected" means "can score".** The page treats the service as available only
when `/health` lists the `attrition` model, so an untrained host shows the
unavailability banner instead of a Run button that fails.

## Consequences

- **A servable model with an honest ceiling.** Every input the model was trained on is
  produced by the live system; the price is a modest, population-specific signal
  (ROC-AUC ≈ 0.63) that the page presents as a prompt for a conversation, never a
  verdict.
- **Retraining is now possible, not just aspirational.** Scores are persisted per
  employee, so they can be joined to subsequent offboarding records — the model
  graduation panel's `outcome_linkage` requirement for attrition is met from day one
  and the surface moves from `provisional` to `collecting`.
- **The stale IBM artifact is gone from the serving path.** `model/artifacts/attrition/`
  still held the June model trained on `attrition-v2.csv`; restoring the registry slot
  would have served it against the wrong contract. It was removed before the new model
  was trained.
- **Out of scope this cut:** per-organisation retraining, scheduled re-assessment, an
  assistant capability, and department as an input (the survey's free-text departments
  could not be matched to an ERP department list, and added no signal under CV).
