# 0045 — Performance Forecast and Promotion Readiness: models that can be relied on

- **Status:** Accepted
- **Date:** 2026-09-26
- **Supersedes, in part:** the model and feature-mapping sections of
  [ADR 0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md)
  and [ADR 0018 — Performance Forecasting](./0018-performance-forecasting.md). Their
  architecture (the FastAPI service, `MlClient`, assessor + mapper, header-plus-lines
  tables) stands.
- **Related:** [Promotion Readiness](../modules/promotion-readiness.md),
  [Performance Forecast](../modules/performance-forecast.md),
  [0028 — Appraisal frameworks](./0028-appraisal-frameworks-and-tenant-rating-models.md)
  (`overall_percent` as the comparable figure),
  [0043 — Attrition Risk](./0043-attrition-risk-trained-on-the-attrition-surveys.md)
  (the "servable by construction" principle applied here).

## Context

Both models reported excellent numbers — Performance R² 0.92, Promotion ROC-AUC 0.94 — and
both were unreliable in the product. The numbers measured a model reading all 40 columns of
a general workforce dataset; the ERP sends about ten of them and the pipeline imputed the
rest. Scoring the development database's 49 active employees through the real code path
showed what that meant:

- **Readiness contradicted itself.** The best record in the company (82.6 %, up from
  80.2 %) scored 19.7 *Low*; someone with a single 80.2 % appraisal scored 88.2 *High*.
  Having a second good appraisal *lowered* readiness.
- **Department names decided scores.** Identical 74.6 % ratings scored 53.8 in "Sales &
  Marketing" and 44.0 in "Human Resources"; 72.2 % in "Operations" scored 11.7. The
  dataset's department effect is large (Engineering promotes 33 %, Support 2 %) and a
  tenant's department names match it only by accident.
- **The forecast was a constant.** 48 of 49 people forecast "on track" between 70 and 75;
  a whole organisation with no appraisals at all got exactly 70.0 at "60 % confidence".
- **Drafts and the target period leaked in.** The forecast read the latest evaluation of
  any status, including a draft of the very period it was forecasting, and fed that one
  rating in three times (as score, manager rating and KPI attainment).
- **Wrong units.** Monthly pesos went into an annual-dollar salary feature; `overall_score
  × 20` was used where the tenant's canonical attainment is `overall_percent`; part-time
  employees mapped to a category the model never saw.

Measured honestly — fed only what a real forecast can know — the old performance model
fell from R² 0.92 to **0.66** (MAE 7.1).

The root cause of the readiness contradictions was found in the data. In the reference
workforce, promotion is driven by **improvement**: holding the latest rating fixed, a
*higher* previous rating means *less* chance of promotion (with no change almost nobody is
promoted at any level; with ten points or more, a quarter to a half are). Filling a missing
previous rating with the dataset median therefore **invented an improvement** for anyone
with a single appraisal.

## Decision

**1. Servable by construction — train only on what the ERP records, in its units.**
Each model reads a short contract of ERP facts, and the reference dataset is re-expressed in
those units before training (`synapse_ml/{promotion,performance}/features.py`):

| Model | Inputs | Mapped from the reference |
|---|---|---|
| Promotion | `rating_latest` (latest completed appraisal, `overall_percent`), `rating_change` (minus the previous one) | `performance_score`, `performance_score − performance_last_year` |
| Performance | `rating_latest` (latest completed appraisal **before the forecast period**) | `performance_last_year` → next: `performance_score` |

`overall_percent` is read one-for-one as the reference's percentage score. Every other
input was measured (held-out, identical folds). For the **forecast**, nothing the ERP records
— the rating before the latest, tenure, certifications, attendance, lateness, overtime,
training, department, employment type — changes R² at all (every Δ is 0.0000). For
**promotion**, the rating two cycles back, tenure, certifications, attendance, lateness,
training and employment type add a thousandth or less. Three inputs do add signal and are
excluded on purpose, each with its number:

- **Department** (+0.079 ROC-AUC) — the reference's biggest effect after change, and a fact
  about *its* departments; a tenant's department names match them only by accident, and a
  department's past promotion rate says nothing about a person.
- **Overtime** (+0.0085) — approved overtime depends on role and policy (exempt staff record
  none), and a readiness score that rises with hours worked penalises part-time staff and
  anyone with caring responsibilities.
- **Time since promotion** (+0.0018) — earned only because the reference's *recently*
  promoted are promoted again more often, backwards from any real time-in-grade practice;
  an explanation ("promoted eight months ago: +2 readiness") HR would rightly distrust.

Salary is another currency and period.

**2. Never guess a missing input — one submodel per history pattern.** Live records are
incomplete in a structured way. Instead of imputing, a submodel is fitted for each
combination of optional inputs on the complete reference restricted to those columns
(`synapse_ml/appraisal/patterns.py`; Fletcher Mercaldo & Blume, 2020), and each record is
scored by the submodel for exactly what it has. With half the reference losing its previous
appraisal, pattern submodels keep calibration where median-filling breaks it: ECE for those
records **0.0055 vs 0.0514**. A record lacking a *required* input — no completed appraisal
— is **declined**, not scored.

**3. Promotion: calibrated logistic regression, scored and tiered on odds.** Logistic
regression remains the algorithm (the log-odds are near-linear in level and change; it
ranks as well as gradient boosting). A **monotone quadratic Platt step**, fitted out of
fold and held flat past its turning point, corrects its small calibration bend (ECE
0.011 → 0.004). Isotonic recalibration was rejected: similar ECE, but a staircase of a few
hundred distinct probabilities, ties across thousands of people, and exact 0 % / 100 %.

- `probability` — the share of reference employees with this record promoted within a year.
- `score` — the mid-rank percentile of that probability among the whole reference
  workforce, so a score means the same thing whatever history it rests on.
- `tier` — by lift over the 10 % base rate: *high* ≥ 2×, *medium* ≥ 1×. Out of fold, the
  tiers hold 17 % / 16 % / 67 % of the reference, of whom 33 % / 15 % / 3 % were promoted.
- `basis` — `two_appraisals` or `latest_appraisal`; the page says which, and what it means.
- `factors` — occlusion in readiness points: how far each recorded input moves the score
  against a typical value; effects under one point are not offered as reasons.

**4. Performance: gradient boosting with conformal intervals.** A monotone
`HistGradientBoostingRegressor` gives the point forecast (MAE 4.64, R² 0.845 held out —
equal to a straight line, and bending where the scale's floor and ceiling do). Its errors,
measured on rows it never trained on and grouped into 20 regions of the forecast
(**Mondrian split-conformal prediction**), become:

- `interval` — the range four in five next ratings land in: 79.5 % held out, every region
  within about two points of 80 %;
- `confidence` — the chance the next rating lands in the forecast's band, replacing
  "share of inputs present". Stated and observed agree across the range (0.54 → 0.55,
  0.73 → 0.71, 0.99 → 0.99). Near a band edge it is honestly about a coin flip.

**5. The ERP side reads the record correctly.** `App\Support\Ml\AppraisalHistory` is the one
reader both surfaces use: completed appraisals only (submitted / acknowledged), ordered by
when their **period ended** (not row id), as `overall_percent`. The forecaster cuts it at
the target period's start, so a forecast never reads its own period. `MlClient` encodes each
instance's features as a JSON object — an employee with nothing on record previously
encoded as `[]`, which the service rejects, failing the whole batch.

**6. Declined employees are recorded, with the reason.** Runs store
`unassessed: [{employee_id, reason}]` — `no_appraisal`, `appraisal_in_progress` or
`none_before_period` — and the page lists them with what would include them. Every persisted
score is a real prediction, so downstream readers (reports, the awards nominator, the
performance page) need no change.

**7. A forecast is checked against what happened.** `ForecastTrackRecord` compares a run
with the completed appraisals of the period it forecast: average miss, share inside their
range, band hit rate against what the confidence promised. The page withholds a verdict
below 20 checked forecasts. This is the only test that speaks for the tenant's own
workforce, and it is live from the first completed cycle.

**8. Contract drift is visible.** The service reports inputs it does not read
(`warnings` on the response), and a value outside the trained range is held at the edge
with a per-result note — both logged or shown rather than silently absorbed. The service
counts as connected only when `/health` lists the surface's model.

## Consequences

- **Scores on the development database are now coherent.** Organisation 1: 18 of 24
  assessed (4 with no appraisal, 2 with only drafts are listed, not scored); readiness
  ordered exactly by rating; every forecast carries a range (e.g. 65.2 % → 67.4, likely
  60.2–74.8, 89 % on track). Organisation 2 has no appraisals and is — correctly — not
  scored at all. Rescoring is identical. Completing the H1 2026 drafts switches seven
  people to the two-appraisal basis and checks the forecast: 4 of 7 inside their range
  (promised about 6), mean miss 8.3 — this organisation's ratings fell where the reference's
  rise, which is exactly what the track record exists to show. Seven is too few to judge,
  and the page says so.
- **Headline accuracy is lower, and true.** Promotion ROC-AUC 0.843 with two appraisals and
  0.692 with one; performance R² 0.845. These are the numbers the product actually gets.
- **Recorded properties of the reference, surfaced rather than hidden:** a consistently
  high performer with no improvement reads as *low* readiness (the reference promotes
  improvers); forecasts drift up about two points a year (the reference's trend). Both are
  visible in the factors and the trajectory.
- **Guarantees are tested, not asserted:** zero monotonicity violations (a better rating
  or larger improvement never lowers readiness; a better latest rating never forecasts a
  worse next one), determinism, batch/single parity, calibration, conformal coverage per
  region, and the contract on disk matching the served model.
- **Out of scope:** retraining on a tenant's own outcomes (the track record and stored
  scores are the groundwork), locally recalibrating intervals from the track record, and
  reading the tenant's own rating bands in place of 60 / 80.
