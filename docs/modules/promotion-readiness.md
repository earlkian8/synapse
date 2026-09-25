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

The page embeds a **`ModelProvenance`** panel directly beneath its header, stating
in one line that these readiness scores come from a general workforce dataset rather than
this organisation’s own promotion history. Expanded, it shows the
three-stage lifecycle (`provisional` → `collecting` → `graduated`) with the
retraining gate drawn closed, the requirement furthest from satisfied, and the
full requirement ledger — each row opening a drill-down with the statistical
justification for its threshold.

This surface's headline requirement is **120 promotions on record** — roughly 10
to 20 recorded outcomes for each of the dozen or so records a model built from this
organisation's own history would weigh.
Its current stage is `collecting`: stored scores are matched back to who was
actually promoted, so time spent now counts toward a future local model.

### What each score draws on

Beneath the requirement ledger, the panel lists **every input the score uses**,
with how many employee records actually carry it — grouped by whether the value
reaches the score at all:

| State | Meaning |
|---|---|
| **Used now** | Read from your records and fed into every score. |
| **Recorded, not used** | The system already holds it; wiring it in needs no new data entry. |
| **Not recorded anywhere** | No module produces it, so it cannot be filled in. |

For this surface, two fields are used now: the *latest completed appraisal* (35 of 42
in the simulation) and the *previous completed appraisal* (21 of 42), whose difference is
the change the score leans on most. Everything else the system records is shown as
**recorded, not used**, each with the reason it was measured and left out (ADR 0045 §1):
tenure, certifications, attendance and training add nothing; department and salary do
not transfer from the reference workforce; time since promotion and overtime carry a
little signal but are excluded on purpose. Peer feedback and engagement are not recorded
anywhere. [Attrition Risk](./attrition-risk.md) is the surface that does feed 90-day
absences, lateness and overtime into its score.

The panel is **frontend-only**: counts are fabricated in the browser and persisted
to `localStorage`, and no retraining runs behind it. Only the counts are
simulated — the thresholds and their reasoning are real. Shared implementation
lives in `resources/js/features/model-graduation/`; see
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md).

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

## Permissions

`analytics.promotion.view` (the overview & detail), `analytics.promotion.manage` (run /
delete an assessment). Built-in **HR Manager** gets both; Super Admin bypasses all gates.

## Out of scope (this cut)

Scheduled/automatic re-assessment, writing a
recommendation back onto the employee record, and an assistant capability.
