# The predictive screens say why

Attrition Risk, Promotion Readiness and Performance Forecast now explain every score.
Each person's breakdown starts from a typical employee's score and shows how many
points each reason adds or takes away, with the data behind each reason. Each page
also opens with charts of the whole workforce. See
[ADR 0098](../decisions/0098-the-predictive-screens-say-why.md).

## Highlights

- **Why, in points.** Each breakdown walks from a typical record's score to this
  person's, one reason at a time: "A typical employee 22 → 9 mos since last promotion
  +17 → tenure of 9 mos +14 → … → 71". It starts with a one-sentence summary, and each
  reason shows the person's value against the typical one.
- **The reasons add up.** They are exact Shapley values, so the typical score plus every
  reason is the score. An input that is not on record is never a reason. The old
  attrition reasons were what-if effects that did not add up.
- **What the data shows (attrition).** For each top reason, the share of people
  surveyed who left at every answer, with this person's answer highlighted: "Of the 44
  surveyed at ‘< 1 yr’, 16 left (36%), against 24% at the typical ‘3–5 yrs’". Where the
  survey alone points the other way, the page says so.
- **What the next appraisal would do (promotion).** A chart of readiness for every
  rating the next appraisal could give. It shows where Medium and High start and what
  repeating today's rating would give: "A next appraisal of 76.5% or more reaches High.
  The same 68.5% again would give 38".
- **Every band's chance (forecast).** The chance of each band, and the latest appraisal
  on the model's own curve of expected next ratings, with the range four in five next
  ratings fall in.
- **Workforce charts on every page.** How scores spread (with tier lines), what drives
  risk across the workforce (for each input, how many people it raises or lowers risk
  for), the promotion *appraisal map* (everyone by latest appraisal and change; select a
  dot to open that person), and scores by department. Every chart has a table view.
- **Where each person stands.** "Higher risk than 116 of the 116 others in this run",
  shown on a small distribution chart.

## Inference service (model/)

- `synapse_ml/explain.py`: exact baseline Shapley values in one batched predict per run.
- The attrition pipeline is explained by Shapley values instead of occlusion. Factors
  carry `value`, `typical`, `band`, `typical_band` and `evidence`; results carry
  `baseline`.
- Promotion: Shapley factors in readiness points, `baseline`, and `outlook` (readiness
  by next rating, where Medium and High start, and the score if the rating repeats).
- Performance: `chances` for every band, and a `reference` curve.
- Every `/predict` response carries a `reference`: the typical record, the tier cuts,
  the attrition evidence and the forecast curve.
- `synapse_ml/attrition/evidence.py` writes `artifacts/attrition/evidence.json`
  (aggregates only). An organisation's own attrition model stores its records' table
  when it graduates.
- 14 new tests (`tests/test_explanations.py`); 159 of 159 pass.

## Backend

- Migration `2026_11_01_000000_explain_predictions`: `explanation` (json) on the three
  score tables and `reference` (json) on the three run tables.
- `App\Support\Ml\PredictionExplanation` stores what each model explains with. The three
  assessors persist it, and the resources expose it. Runs from an older service are
  stored unexplained, never with an invented breakdown.
- The assistant's attrition read gives each reason its points and states a typical
  employee's score. Pay is still never stated.
- `PredictionExplanationTest` (6 tests).

## Frontend

- `features/prediction-insights/`: a shared chart kit (chart card with table view,
  score histogram, contribution ledger, driver chart, department breakdown, workforce
  position). It is plain HTML and SVG, with no chart library, and every mark has a
  tooltip on hover and keyboard focus.
- New per-model pieces: survey evidence (attrition); the appraisal map and
  next-appraisal chart (promotion); band chances and the rating curve (forecast).
- Breakdown dialogs are wider (`max-w-2xl`). Dialogs focus themselves on open, so no
  tooltip pops up unasked.
- Raises and lowers are drawn in rose and teal, not rose and emerald. Rose and emerald
  fail the colour-blind check (deuteranopia ΔE 5.6); rose and teal pass (ΔE 10.2).
  Every mark also carries a signed number and an arrow.

## Notes

- Checked in Chromium on a seeded scratch workspace with the real inference service:
  all three pages and breakdowns at 1440 px and 390 px, with no console errors. The
  Docker image was not built or run.
- Runs from before this change still open, with the earlier factor list and a note to
  run a new assessment.
- The seeded demo has almost no absences on record, so "no absences" shows up as the
  leading attrition driver. That is how the model reads it. The breakdown shows the
  survey (22% left with no absences, against 26% with one or two) and says the model
  counts it in combination with other answers.
