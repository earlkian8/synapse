# 0098 — The predictive screens say why

- **Status:** Accepted
- **Date:** 2026-11-01
- **Related:**
  - [0043 — Attrition risk trained on the attrition surveys](./0043-attrition-risk-trained-on-the-attrition-surveys.md);
  - [0045 — Performance and promotion models that can be relied on](./0045-performance-and-promotion-models-that-can-be-relied-on.md);
  - [0046 — Model graduation](./0046-model-graduation-trains-on-the-organisations-own-records.md)
    (an organisation's own models explain themselves the same way);
  - [0058 — The assistant on attrition, promotion and forecasts](./0058-assistant-attrition-promotion-and-forecast.md);
  - module docs: [Attrition Risk](../modules/attrition-risk.md),
    [Promotion Readiness](../modules/promotion-readiness.md),
    [Performance Forecast](../modules/performance-forecast.md); schema:
    [attrition risk](../database/attrition-risk-tables.md),
    [promotion readiness](../database/promotion-readiness-tables.md),
    [performance forecast](../database/performance-forecast-tables.md) tables.

## Context

The three predictive screens gave a score and a tier, then a short list of input
names marked "Raises risk" or with a bar. A reader could not tell:

- **How much.** "Ever promoted — raises risk" said nothing about whether it moved the
  score by one point or by twenty.
- **Compared with what.** A reason is only a reason against something. The screens never
  said what a typical employee scores, or what the typical value of an input is.
- **On what evidence.** Nothing showed what the data the model learned from holds: that
  53% of the people surveyed who had been absent six to ten days in their last three
  months left, against 28% overall.
- **What would change it.** Promotion readiness is the one score an employee and a
  manager can work towards, and the screen did not say what the next appraisal would
  have to be.
- **The workforce as a whole.** There was a three-colour bar and a ranked list. Nothing
  showed how scores spread, which inputs drive them across the organisation, or where
  they gather by department.

The attrition reasons also had a technical flaw. They were *occlusion* effects: the
change in the score if one input were set to its typical value. For a non-linear model
like the attrition SVM, these effects do not add up to the score, so they could not be
drawn as a breakdown without misleading.

## Decision

**Every score is explained by exact Shapley values against a typical record**
(`model/synapse_ml/explain.py`). The typical record is the medians and modes the
pipeline's own imputers learned (attrition), or the served model's `typical` values
(promotion). Each recorded input receives its share of the gap between the typical
record's score and this score, averaged over every order in which the inputs could be
revealed. The models read at most seven inputs, so the exact sum over all 2⁷ coalitions
is one batched `predict` call per run: 0.08 s for three people, and well under a second
for a workforce. This gives three guarantees:

- **They add up.** The typical score plus every reason equals the score, so the screen
  can draw it as a walk from "a typical employee" to this one.
- **Only recorded facts are reasons.** An input that is not on record is imputed to the
  typical value, and an input at the typical value contributes exactly zero.
- **Interactions are shared fairly.** When two inputs only matter together, they split
  the effect equally (tested).

**The inference service returns the explanation with the score** (`api/schemas.py`):

- every factor carries the employee's `value` and the `typical` one; attrition factors
  also carry the survey `band` and `typical_band`, and the `evidence`: the leave rate of
  each band in the data the model learned from, and whether that data on its own points
  the way the model does (`consistent`);
- `baseline`: the typical record's score (attrition, promotion);
- `outlook` (promotion): readiness after the next appraisal for every rating from 40
  to 100. The next rating becomes the latest, and its change on today's latest becomes
  the change, so the record is read exactly as the model will read it then. The outlook
  also gives the lowest rating that reaches Medium and High, and the score if the
  rating stays the same;
- `chances` (performance): the chance the next rating lands in each band, from the same
  conformal errors that give the confidence;
- `reference` on the whole response: the typical record, where the tiers start on the
  score scale, the attrition evidence table, and the forecast model's rating curve.

**The attrition evidence is plain counts** (`synapse_ml/attrition/evidence.py`). These
are leave rates by band from the 199 survey responses, written as aggregates only to
`artifacts/attrition/evidence.json`. An organisation's own model stores the same table
from its own records when it graduates. Where the model counts an answer against the
direction of its own row (the SVM reads answers together), the screen says so rather
than hiding it.

**Laravel stores the explanation with the run** (`2026_11_01_000000_explain_predictions`):
an `explanation` column on each score table and a `reference` column on each run table,
filled by `App\Support\Ml\PredictionExplanation`. The explanation is stored, not fetched
when the page opens, so an old run is always explained by the model that scored it. A
run from before this change has neither column, and the screens fall back to the old
factor list rather than inventing a breakdown.

**The screens show it** (`resources/js/features/prediction-insights/`):

- **A workforce overview** on each page: how scores spread (stacked by tier, with tier
  lines), what drives risk across the workforce (attrition: for each input, how many
  people it raises or lowers), the *appraisal map* (promotion: everyone by latest
  appraisal and change), and scores by department. Every chart has a table view.
- **A breakdown for each person**: a sentence on why, a *ledger* that walks from the
  typical score through each reason's points to the score, where the person sits in the
  run, and per model the survey evidence (attrition), the next-appraisal curve
  (promotion), or the band chances and the model's rating curve (performance).
- **The list** names each person's strongest reason with its points.

Colours follow the dataviz checks. Rose and teal mark unfavourable and favourable
reasons. This pair stays apart for colour-blind readers (deuteranopia ΔE 10), which the
app's rose/emerald pair does not (ΔE 5.6). Every mark also carries a signed number and
an arrow, so colour is never the only cue.

**The assistant** gives an explained attrition reason its points and the typical
employee's score. Pay is still never stated.

## Consequences

- HR can read every score the same way: from a typical record, in points, with the
  data behind it.
- Attrition factor values changed meaning, from occlusion deltas to Shapley values.
  Both are on the probability scale, so stored runs and the assistant read either.
  Only runs from this change on carry a `baseline`, and only those are drawn as a
  ledger.
- `artifacts/attrition/evidence.json` must exist where the service runs (the Docker
  image copies `model/artifacts/`). Without it, attrition scores are still explained,
  only without the survey evidence. Regenerate it with `python -m
  synapse_ml.attrition.evidence` after the survey changes.
- An inference service from before this change still works with the new Laravel. Its
  runs are stored unexplained.
