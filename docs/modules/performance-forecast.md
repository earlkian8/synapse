# Performance Forecast

The second **Predictive Workforce Analytics** surface (after
[Promotion Readiness](./promotion-readiness.md)). HR runs a **forecast** that
projects every active employee's **next-period performance rating** (0–100) using a
trained machine-learning model, producing the **range** it is likely to land in, a
**Below / On track / Exceeds** band with the **chance that band is right**, and the
employee's **rating trajectory** behind it — to plan reviews, coaching and
development ahead of the cycle. Once the period is appraised, the run is checked
against what actually happened. Predictions come from the standalone **ML inference
service** (FastAPI, see `model/api`), called server-side; everything is
tenant-scoped (ADR 0005). See
[ADR 0018](../decisions/0018-performance-forecasting.md) for the design and
[performance-forecast tables](../database/performance-forecast-tables.md) for the
schema. The model and the way records reach it are specified by
[ADR 0045](../decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md).

> Status: **Active** · Route prefix: `/analytics/performance-forecast`
> Sidebar: Analytics & AI → Performance Forecast (gated by `analytics.performance.view`)

## Surfaces

- **`/analytics/performance-forecast`** — the overview: headline metric cards
  (forecasted, exceeding, average rating, average confidence), the **target period**
  being forecast, a **How this forecast did** card once that period's appraisals are
  completed, a **Not forecast** list (employees with no completed appraisal before the
  period, each with the reason), then the **ranked roster** — every forecast employee by
  predicted rating, with their band, the likely range and the movement vs. their last
  appraisal. Search by employee, filter by band, and pick a past run from the history
  selector. HR can **run a new forecast** or **delete** a historical one. Selecting an
  employee opens a **detail dialog**: the predicted rating and the range four in five
  next ratings land in, the band and the chance it is right, a **trajectory chart**
  (completed appraisals → the forecast point with its range, and the actual result once
  there is one), and the appraisal the forecast rests on.

## How a forecast works

`App\Support\Ml\PerformanceForecaster` is the single source of truth for "forecast
performance":

1. Gather all **active** employees, with their performance evaluations and each one's
   period eager-loaded.
2. The forecast targets the **next non-closed evaluation period** (the soonest cycle
   that is not yet `closed`), stored on the run; `null` when none exists.
3. `App\Support\Ml\PerformanceFeatureMapper` cuts each record at that period:
   `AppraisalHistory` keeps only **completed** appraisals whose period **ended before the
   target period began**, as `overall_percent`. A forecast never reads its own period's
   appraisal — not a draft of it, and not a finished one. It sends one input,
   `rating_latest`; nothing else the ERP records adds anything (ADR 0045 §1).
4. The batch is scored by the inference service (`MlClient::predict('performance', …)`),
   which declines an employee with nothing to forecast from.
5. The result is persisted as a `PerformanceForecastRun` header (target period, band
   counts, averages, and `unassessed` — declined employees with the reason: no appraisal,
   one still in draft, or only appraised in the period being forecast) with one
   `PerformanceForecast` per forecast employee (rating, range, band, confidence, the
   feature sent, the appraisals behind it, and any warnings). The run is activity-logged.

### How a forecast did

Once the target period has completed appraisals, `App\Support\Ml\ForecastTrackRecord`
checks the viewed run against them: the average miss, how many actual ratings landed
inside their forecast range (the promise: about four in five), and how often the band was
right against what the confidence said to expect. The page withholds a verdict below 20
checked forecasts, and says plainly when ratings here move more than the reference's.
Each employee's dialog shows their actual result on the trajectory.

If the inference service is unreachable, the action degrades gracefully — a plain
"temporarily unavailable, try again shortly" toast, no run recorded — and the page
shows the same message as a banner; existing forecasts stay visible.

Nothing about the model itself reaches the browser. `ServiceBanner` renders **only**
when the service is down, the page's `service` prop carries liveness and nothing
else, and an `MlException`'s message is written for the person who clicked the
button — never a shell command, a status code, or the service's response body,
which are logged server-side instead. This is an HR screen, not a model dashboard.

## Where these scores come from (model graduation)

The page embeds a **`ModelProvenance`** panel directly beneath its header, stating
in one line that these forecasts come from a general workforce dataset rather than this
organisation’s own appraisal history. Expanded, it shows the
three-stage lifecycle (`provisional` → `collecting` → `graduated`) with the
retraining gate drawn closed, the requirement furthest from satisfied, and the
full requirement ledger — each row opening a drill-down with the statistical
justification for its threshold.

This surface learns from **cycle-to-cycle comparisons** rather than from people,
so its headline requirement is 200 of them. Its distinctive requirement is **30
people with three or more appraisals**: a trajectory needs three points, and with
two every forecast is really last cycle restated. Its current stage is
`collecting`.

### What each score draws on

Beneath the requirement ledger, the panel lists **every input the score uses**,
with how many employee records actually carry it — grouped by whether the value
reaches the score at all:

| State | Meaning |
|---|---|
| **Used now** | Read from your records and fed into every score. |
| **Recorded, not used** | The system already holds it; wiring it in needs no new data entry. |
| **Not recorded anywhere** | No module produces it, so it cannot be filled in. |

For this surface one field is used now: the *latest completed appraisal* before the
period being forecast. Everything else is **recorded, not used**, each with its reason:
across the reference workforce nothing else the system records — earlier ratings,
tenure, certifications, attendance, lateness, overtime, training, department, employment
type — changes the forecast at all once the latest appraisal is known, and *KPI
attainment* is part of the appraisal it summarises. Deadline adherence and peer
feedback are not recorded anywhere. [Attrition Risk](./attrition-risk.md) is the
surface that does feed 90-day absences, lateness and overtime into its score.

The panel is **frontend-only**: counts are fabricated in the browser and persisted
to `localStorage`, and no retraining runs behind it. Only the counts are
simulated — the thresholds and their reasoning are real. Shared implementation
lives in `resources/js/features/model-graduation/`; see
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md).

## The model

**Gradient boosting with conformal intervals** — trained on a 100,000-row reference
workforce read one cycle shifted (last year's rating → this year's;
`model/synapse_ml/performance/`, notebook `02_performance_model`).

- **Predicted rating (0–100)** — a monotone `HistGradientBoostingRegressor`: a better
  latest appraisal never forecasts a worse next one. Held out: MAE 4.6, R² 0.845.
- **Range** — the interval four in five next ratings land in, from how far next ratings
  actually strayed from forecasts like this one on reference rows the model never saw
  (Mondrian split-conformal, 20 regions). Held out: 79.5 % inside, every region within
  about two points of 80 %.
- **Band** — the predicted rating cut at 60 and 80 (the reference's quartiles).
- **Confidence (0–1)** — the chance the next rating lands in that band. Stated and
  observed agree across the range; near a band edge it is honestly about a coin flip.
  (It used to be the share of inputs present, which measured nothing about accuracy.)

Recorded property of the reference: ratings there drift up about two points a year, so
forecasts do too. The track record is how an organisation sees whether that holds for it.

## Permissions

`analytics.performance.view` (the overview & detail), `analytics.performance.manage`
(run / delete a forecast). Built-in **HR Manager** gets both; Super Admin bypasses
all gates.

## Out of scope (this cut)

Scheduled/automatic re-forecasting, writing a forecast back onto the employee
record or the evaluation, an assistant capability, recalibrating the ranges from an
organisation's own track record, and reading the tenant's own rating bands in place of
60 / 80. There is one input, so there are no per-feature factors: the trajectory, the
range and the appraisal it rests on are the explanation.
