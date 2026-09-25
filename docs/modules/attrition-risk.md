# Attrition Risk

The third **Predictive Workforce Analytics** surface, alongside
[Promotion Readiness](./promotion-readiness.md) and
[Performance Forecast](./performance-forecast.md). HR runs an **assessment** that scores
every active employee's flight risk with a trained model, producing a 0–100 **risk
score**, a **Stable / At watch / High risk** tier, a **confidence**, and the **factors**
behind each score — so a retention conversation can happen before a resignation, not
after. Predictions come from the standalone **ML inference service** (FastAPI, see
`model/api`), called server-side; everything is tenant-scoped (ADR 0005). See
[ADR 0043](../decisions/0043-attrition-risk-trained-on-the-attrition-surveys.md) for the
design and [attrition-risk tables](../database/attrition-risk-tables.md) for the schema.

> Status: **Active** · Route prefix: `/analytics/attrition`
> Sidebar: Analytics & AI → Attrition Risk (gated by `analytics.attrition.view`)

## Surfaces

- **`/analytics/attrition`** — headline cards (assessed, high risk, at watch, average
  risk), a cohort **risk-distribution bar**, and the **ranked roster** — every active
  employee by risk score, with their tier and the strongest thing pushing their risk up.
  Search, filter by tier, and pick a past run from the history selector. HR can **run a
  new assessment** or **delete** a historical one. Selecting an employee opens a **detail
  dialog**: the score, the tier, the confidence, *what moves this score* (each input's
  effect, rose raising risk, emerald lowering it), and *what it is based on* — every one
  of the eight inputs with the employee's recorded value, or "Not on record" where it was
  estimated.

## How an assessment works

`App\Support\Ml\AttritionRiskAssessor` is the single source of truth for "assess
attrition risk":

1. Gather all **active** employees with their promotions and four 90-day attendance
   aggregates (tracked days, absences, late arrivals, overtime minutes).
2. `App\Support\Ml\AttritionFeatureMapper` maps each to the model's eight inputs:

   | Input | From |
   |---|---|
   | `employment_type` | Employee record (`regular`, `probationary`, `part_time`, `contractual`) |
   | `tenure_years` | `date_hired` |
   | `monthly_salary` | `basic_salary` |
   | `ever_promoted`, `years_since_promotion` | Latest promotion; never promoted = the whole tenure |
   | `absences_90d` | Attendance days with status `absent` (leave, rest days, holidays excluded) |
   | `lates_90d` | Attendance days with `late_minutes > 0` |
   | `overtime_hours_90d` | Sum of worked `overtime_minutes` ÷ 60 |

   Values go in their natural units; the model bands them itself. An employee with **no
   attendance tracked** in the window gets no attendance inputs (they are imputed, and
   confidence drops to 5/8) rather than zeros that would claim a perfect record. No
   demographic or protected attribute is ever sent.
3. The batch is scored by the inference service (`MlClient::predict('attrition', …)`).
4. The result is persisted as an `AttritionRiskRun` header (model version, tier counts,
   average risk and confidence) with one `AttritionRiskScore` per employee (probability,
   score, tier, confidence, factors, and the feature snapshot for audit). The run is
   activity-logged (`attrition-risk`).

If the service is unreachable, or running without the attrition model trained, the
action degrades gracefully — a plain "temporarily unavailable" toast, no run recorded —
and the page shows the same message as a banner; existing assessments stay visible.
Nothing about the model itself (algorithm, version, metrics) reaches the browser.

## The model

A **Random Forest** trained on two exports of an attrition survey, merged and cleaned by
`model/synapse_ml/attrition/survey.py` (155 usable responses: 50 who resigned
voluntarily, 105 still employed) — see `model/notebooks/01_attrition_model.ipynb`. The
survey data lives in the git-ignored `model/data/` (see `model/data/README.md`).

- **How good it is:** cross-validated ROC-AUC **0.63 ± 0.04** (chance is 0.50),
  PR-AUC 0.45 against a 0.32 base rate; a permutation test puts that at p = 0.03. Real,
  but modest.
- **What it leans on:** tenure, salary and time since the last promotion most; then
  absences and late arrivals.
- **Its limit:** the two surveys reached different populations, and a model trained on
  one does not rank the other well. Treat scores as a provisional, population-level
  prior until the model is retrained on this organisation's own departures.
- **Reading the score:** it is *relative*, not a probability — 70 means the profile
  looks much more like people who left than people who stayed. Tiers cut at 33 / 66. On
  held-out survey rows, 24% of "Stable" had left against 36–38% of "At watch" and "High
  risk".
- **Factors** are what-if-typical deltas: how much the score would move if that one
  input were the typical value. Effects under two points are not shown.

## Where these scores come from (model graduation)

The page embeds the **`ModelProvenance`** panel beneath its header: these scores come
from a survey of workers at other employers, not this organisation's own departure
history. Because every score is now **stored**, it can be matched to who later leaves —
the `outcome_linkage` requirement is met and the surface reads `collecting`, like the
other two. Its headline requirement is **80 recorded departures** (10 to 20 outcomes for
each of the 8 inputs), which a stable organisation accrues slowest of the three.

The field-coverage list shows **Used now**: hire date, employment type, salary, time
since last promotion, and the three 90-day attendance counts. **Recorded, not used**:
department (the survey's free-text answers could not be matched to a department list)
and training completions (the survey did not ask). **Not recorded anywhere**: exit
interviews, engagement, pay against market. The panel's counts are simulated (ADR 0031);
its thresholds and reasoning are real.

## Permissions

`analytics.attrition.view` (the overview & detail), `analytics.attrition.manage` (run /
delete an assessment). **HR Manager** gets both; **Department Head** gets view; Super
Admin bypasses all gates. Reports show the latest run as an *Attrition risk* signal chip
on the Workforce and Attendance groups.

## Running it locally

1. `model/`: place the two survey exports in `data/raw/` (see `data/README.md`), then
   execute `notebooks/01_attrition_model.ipynb` once (writes `artifacts/attrition/`).
2. `model/`: `.venv/Scripts/python.exe -m api` (port 8001; `/health` should list
   `attrition`).
3. `server/`: `php artisan migrate`, then open **Analytics & AI → Attrition Risk** and
   **Run assessment**.

## Out of scope (this cut)

Per-organisation retraining on offboarding history, scheduled re-assessment, writing a
risk flag onto the employee record, and an assistant capability.
