# Performance Forecast and Promotion Readiness, made reliable

Both models reported excellent accuracy (R² 0.92, ROC-AUC 0.94) and were unreliable in
the product: they were trained on 40 columns of a general workforce dataset, fed about
ten, and imputed the rest. On the development database readiness contradicted itself (the
best record scored *Low*, a single appraisal scored *High*), department names decided
scores, the forecast was a constant 70–75, drafts leaked in, and an organisation with no
appraisals got forecasts anyway. Both models are rebuilt to be **servable by
construction** — trained only on what the ERP records, in its units — and to say what they
rest on, how sure they are, and who they could not assess. See
[ADR 0045](../decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md).

## Highlights

- **Honest inputs.** Promotion reads the latest completed appraisal (`overall_percent`)
  and its change on the previous one; the forecast reads the latest completed appraisal
  *before the period it forecasts*. Every other field was measured and is absent for a
  stated reason — most add nothing; department (+0.079), overtime (+0.0085) and time since
  promotion (+0.0018) are excluded on purpose.
- **Nothing is guessed.** One submodel per history pattern replaces median imputation,
  which invented an improvement — the strongest promotion signal — for anyone with one
  appraisal (calibration for those records: ECE 0.0055 vs 0.0514). An employee with no
  completed appraisal is **declined and listed with the reason**, never scored.
- **Numbers that mean something.** Readiness probabilities are calibrated (ECE 0.004),
  scored as a percentile of the reference workforce and tiered by lift over the base rate
  (High ≥ 2× average, Medium ≥ 1×; 33 % / 15 % / 3 % of each tier were promoted). Forecasts
  carry a **conformal range** that holds four in five next ratings (79.5 % held out, in
  every region of the scale) and a **confidence that is the chance the band is right**
  (stated and observed agree across the range).
- **A forecast is checked.** Once the forecast period's appraisals are completed, the page
  shows how the forecast did — average miss, how many landed in their range, band hit rate
  — against this organisation's own results.

## Model (`model/`)

- `synapse_ml/appraisal/` — the reference workforce loader (validates the file and refuses
  one it does not recognise), the input reader (absent is never zero; a value outside the
  trained range is held at the edge with a note), and `PatternRouter`.
- `synapse_ml/promotion/` — contract, `CalibratedLogistic` (logistic regression + a
  monotone quadratic Platt step; isotonic rejected for its ties and exact 0/100 %),
  `PromotionReadinessModel` (probability, percentile score, lift tiers, basis, factors in
  readiness points), and the evaluation protocol (calibration, per-pattern, mixed history,
  tiers, monotonicity, fairness across attributes the model never sees).
- `synapse_ml/performance/` — contract, a monotone gradient-boosting point forecast,
  `MondrianConformal` (20 regions), `PerformanceForecastModel`, and the evaluation protocol
  (coverage per region, confidence reliability, monotonicity).
- The service serves these objects directly (`assess`), so notebook, tests and service run
  one code path. Results gain `status` / `missing`, `basis`, `interval`, `band`,
  `confidence` and per-result `warnings`; responses report inputs the model does not read.
- Notebooks 02 and 03 are rewritten around the evaluation, including the measurement that
  justifies every left-out input. `synapse_ml.tabular` (the old notebooks' only helper) is
  removed.

| | Before (as served) | After |
|---|---|---|
| Promotion ROC-AUC | 0.94 on 40 columns; 0.70 with one appraisal | 0.843 (two appraisals) · 0.692 (one) |
| Promotion calibration | balanced weights, uncalibrated | ECE 0.004 |
| Forecast | R² 0.92 on 40 columns; **0.66** fed honestly | R² 0.845, MAE 4.64, 80 % range |
| Forecast confidence | share of inputs present | chance the band is right |

## Server

- `AppraisalHistory` — the one reader of an appraisal record: completed appraisals only,
  ordered by when their period ended (not row id), as `overall_percent`; says why a record
  cannot be assessed.
- `PromotionFeatureMapper` sends two facts; the new `PerformanceFeatureMapper` cuts the
  record at the target period's start. `PerformanceForecaster` stores the model's band,
  range and confidence as given instead of re-deriving them.
- Runs record `unassessed` (`no_appraisal` / `appraisal_in_progress` /
  `none_before_period`); readiness scores record `basis`, `history`, `warnings`; forecasts
  record `predicted_low` / `predicted_high`, `warnings`. Migration
  `2026_09_26_000000_add_reliability_to_promotion_and_forecast_scores`.
- `ForecastTrackRecord` checks a run against its period's completed appraisals.
- **Bug fixed:** `MlClient` encoded an employee with no features as a JSON list (`[]`),
  which the service rejects — failing the whole batch. Features are now always an object.
- Both pages count the service as connected only when `/health` lists their model; run
  resources no longer send `model_version` to the browser (it stays on the run, as on
  Attrition); toasts say how many employees were left out, and the activity log records it.

## Frontend

- **Promotion Readiness:** colour follows the tier; the dialog explains the probability
  ("12 % of people with this record were promoted within a year — 1.2× the average"),
  shows the basis (one appraisal or two, and what that means), factors in readiness
  points, and the appraisals behind the score; rows mark one-appraisal scores.
- **Performance Forecast:** rows and dialog show the likely range; confidence reads as the
  chance of landing in the band; the trajectory chart draws the range and, once appraised,
  the actual result; a **How this forecast did** card withholds a verdict below 20 checks.
- Both pages list **Not assessed / Not forecast** employees with the reason and what would
  include them. The Performance page's decision-support panel shows the range too.
- The model-graduation panel's field lists now mark only the inputs actually used, and
  give every other field the reason it is left out.

## Notes

- Verified: Pest on the Postgres harness **1086/1086** (7,629 assertions), including the
  new `PromotionReadinessTest` (16) and `PerformanceForecastTest` (8) — there were none
  before. `pytest` **116/116**; `ruff check` clean on all new and changed Python. Pint
  `passed`, `php -l` clean; `tsc`, ESLint, Prettier and `vite build` green.
- End to end against the development database, through the real service, with the
  migration and every write inside one rolled-back transaction: organisation 1 — 18 of 24
  assessed and forecast (4 with no appraisal and 2 with only drafts listed, not scored);
  organisation 2 has no appraisals and is correctly not scored at all. Rescoring is
  identical. Completing the H1 2026 drafts moves seven people to the two-appraisal basis
  and checks the forecast: 4 of 7 inside their range (promised about 6), mean miss 8.3 —
  this organisation's ratings fell where the reference's rise. Too few to judge, and the
  page says so.
- Recorded properties of the reference, visible rather than hidden: a consistently high
  performer with no improvement reads as *Low* readiness; forecasts drift up about two
  points a year.
- Regenerate the artifacts by executing notebooks 02 and 03; run the migration.
