# Promotion Readiness

The first **Predictive Workforce Analytics** surface. HR runs an **assessment** that
scores every active employee with a completed appraisal for promotion readiness using a
trained machine-learning model — how their appraisal record compares with the records of
people who were promoted — producing a 0–100 **readiness score**, a **Low / Medium /
High** tier, the **history it rests on**, and the **factors** behind each score, to
support fair, evidence-based advancement and succession planning. Employees the model
cannot assess are listed with the reason, never scored from a guess. Predictions come from the standalone **ML inference service**
(FastAPI, see `model/api`), called server-side; everything is tenant-scoped (ADR 0005).
See [ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md) for the design
and [promotion-readiness tables](../database/promotion-readiness-tables.md) for the schema.
The model and the way records reach it are specified by
[ADR 0045](../decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md).

> Status: **Active** · Route prefix: `/analytics/promotion-readiness`
> Sidebar: Analytics & AI → Promotion Readiness (gated by `analytics.promotion.view`)

## Surfaces

- **`/analytics/promotion-readiness`** — the overview: headline metric cards
  (assessed, promotion-ready, developing, average readiness), then a **Not assessed**
  list — the active employees the model declined, each with the reason (no appraisal on
  record, or one still in draft) and what would include them — then the **ranked
  roster**: every assessed employee by readiness score, with their tier, whether the
  score rests on one appraisal or two, and their strongest positive factor. Search by
  employee, filter by tier, and pick a past run from the history selector. HR can **run
  a new assessment** or **delete** a historical one. Selecting an employee opens a
  **detail dialog**: the score and tier, what the probability means ("12 % of people
  with this record were promoted within a year — 1.2× the average"), the **basis** (one
  appraisal or two, and what that means), the factors in readiness points, and the
  appraisals it was built from — latest, previous and the change — with any note about
  a value held at the model's trained range.

## How an assessment works

`App\Support\Ml\PromotionReadinessAssessor` is the single source of truth for "assess
promotion readiness":

1. Gather all **active** employees, with their performance evaluations and each one's
   period eager-loaded.
2. `App\Support\Ml\AppraisalHistory` reads each record the way the model was trained:
   **completed** appraisals only (submitted or acknowledged — a draft can still change),
   ordered by when their **period ended**, each as **`overall_percent`** — attainment on
   0–100, the one figure comparable across appraisal frameworks
   ([ADR 0028](../decisions/0028-appraisal-frameworks-and-tenant-rating-models.md)).
3. `App\Support\Ml\PromotionFeatureMapper` sends two facts: `rating_latest` (the latest
   completed appraisal) and `rating_change` (that minus the previous one), the latter
   **only when a previous appraisal exists** — it is never invented. Nothing else is
   sent: not department, salary, employment type, tenure, certifications, overtime, nor
   any demographic attribute (why each is left out: ADR 0045 §1).
4. The batch is scored by the inference service (`MlClient::predict('promotion', …)`).
   The model **declines** an employee with no completed appraisal rather than scoring
   them from a guess.
5. The result is persisted as a `PromotionReadinessRun` header (tier counts, average,
   and `unassessed` — the declined employees with the reason) with one
   `PromotionReadinessScore` per assessed employee (probability, score, tier, basis,
   factors, the features sent, the appraisals behind them, and any warnings). The run is
   activity-logged, and the success toast says how many were left out.

If the inference service is unreachable, the action degrades gracefully — a plain
"temporarily unavailable, try again shortly" toast, no run recorded — and the page
shows the same message as a banner; existing assessments stay visible.

Nothing about the model itself reaches the browser. `ServiceBanner` renders **only**
when the service is down, the page's `service` prop carries liveness and nothing
else, and an `MlException`'s message is written for the person who clicked the
button — never a shell command, a status code, or the service's response body,
which are logged server-side instead. This is an HR screen, not a model dashboard.

## Where these scores come from (model graduation)

The page embeds the **model graduation** panel beneath its header (see
[Model graduation](./model-graduation.md) and
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md)):
one sentence on whose data is behind the scores, and — expanded — what graduation is,
where this page is, a checklist of what is still needed and what to do about it, and the
controls to train, check and switch to a model of the organisation's own.

This surface's own model would learn **who has actually been promoted here**. One
example is a completed appraisal — its attainment, and its change on the previous one —
and whether a promotion followed before the person's next completed appraisal (or within
a year). Each promotion is credited once, to the latest appraisal before it; an example
counts once its follow-up has closed; someone who left before then without a promotion
is left out. The checklist:

- **100 promotions that followed an appraisal** — with a note for promotions on record
  that can't count (no appraisal in the year before) or can't count *yet*, and a
  projection at the organisation's recent pace;
- **50 promotions with two appraisals before them** — improvement is the strongest
  signal, and it takes two appraisals to see;
- **100 appraisals not followed by a promotion** — fills on its own;
- **80 % of appraisal pairs scored on an unchanged form** (ADR 0028);
- **the prediction service is ready.**

A trained model is judged by **prediction error** (Brier score) on the organisation's
own people. Once switched to, runs record it and the page's wording follows: odds are
stated against the organisation's own promotion rate (`base_rate` on the run), and
"50" is the middle of its own history.

The field list shows the *latest* and *previous completed appraisal* as used in every
score, and everything else the system records as recorded but not used, each with its
reason (ADR 0045 §1): tenure, certifications, attendance and training add nothing;
department and salary do not transfer from the reference workforce; promotion history
and overtime are excluded on purpose. Peer feedback is not recorded anywhere.

## The model

**Calibrated logistic regression, one submodel per history pattern** — trained on a
100,000-row reference workforce re-expressed in the two ERP inputs above
(`model/synapse_ml/promotion/`, notebook `03_promotion_model`). In the reference,
promotion is driven by **improvement**: with no change on the previous appraisal almost
nobody is promoted at any level; with ten points or more a quarter to a half are.

- **Probability** — the share of reference employees with this record promoted within a
  year, recalibrated by a monotone quadratic Platt step (expected calibration error
  0.004).
- **Score (0–100)** — where that probability sits among the whole reference workforce
  (mid-rank percentile). 50 is the reference's middle.
- **Tier** — by lift over the reference's 10 % promotion rate: **High** at least twice
  as likely as average, **Medium** at least as likely, **Low** below average. Out of fold
  they hold 17 % / 16 % / 67 % of the reference, of whom 33 % / 15 % / 3 % were promoted.
- **Basis** — `two_appraisals` (ROC-AUC 0.84) or `latest_appraisal` (0.69): with one
  appraisal the change is unknown, and the score averages over every change it could be.
  It sharpens after the next cycle; the page says so on every such score.
- **Factors** — how many readiness points each recorded input moves the score against a
  typical record (occlusion); effects under one point are not offered as reasons.

Guaranteed and tested: a better latest rating or a larger improvement never lowers
readiness; rescoring is identical; no score is ever produced for a record the model
cannot see. Recorded property of the reference: a consistently high performer with no
improvement reads as *Low* — the factors show exactly that.

## The assistant

`App\Services\Assistant\Modules\PromotionReadinessModule`, on the shared
`PredictiveModule` ([ADR 0058](../decisions/0058-assistant-attrition-promotion-and-forecast.md)).
Reads need `analytics.promotion.view`; everything else `analytics.promotion.manage`.

- **Reads:** `promotion_readiness_summary` (counts by tier, the average, who was left
  out by reason, the change since the previous assessment), `find_promotion_readiness`
  (by tier, department or name; `declined: true` lists who the model left out and what
  would include them), `get_promotion_readiness` (score and tier, the odds against the
  right average — `PromotionReadinessRun::baseRate()`, now on the model — the basis, the
  factors in readiness points, the appraisals), `get_promotion_model_status`.
- The guidance treats readiness as one input to a human decision, never the decision.
- **Reads come from the stored runs**, never the live model, so they work while the
  inference service is off. Reading a named person's score is audited as `viewed`
  ([ADR 0027](../decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md));
  lists and summaries are not, and the pre-model topic brief carries counts only, never
  names.
- **Writes** go through `App\Support\Ml\PromotionReadinessAssessor`, which gained `run($actor, $channel)` and
  `delete($run, $channel)` — the run controller now calls `delete()` too — and
  `ModelGraduation` (`train` / `activate` / `revert`, also with `$channel`). Each is
  audited "… via assistant".
- **Confirmed:** `delete_promotion_assessment` (the card says which run the page would show next) and
  `switch_promotion_model` (`own` — the newest model that passed its check — or `general`).
  `run_promotion_assessment` and `train_promotion_model` run directly: a run is a new snapshot, and training only
  produces a candidate.
- Words are the page's (`App\Support\Ml\PredictionWording`).

## Permissions

`analytics.promotion.view` (the overview & detail), `analytics.promotion.manage` (run /
delete an assessment; train, switch to and switch back from the organisation's own model). Built-in **HR Manager** gets both; Super Admin bypasses all gates.

## Out of scope (this cut)

Scheduled/automatic re-assessment, writing a
recommendation back onto the employee record, and an assistant capability.
