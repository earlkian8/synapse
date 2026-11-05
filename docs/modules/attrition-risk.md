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
  risk), then the **ranked roster**: every active employee by risk score, with their
  tier and the strongest reason pushing their risk up, in points. Search, filter by tier,
  and pick a past run from the history selector. HR can **run a new assessment** or
  **delete** a historical one. The workforce view (how risk is spread, what raises it
  for the most people, risk by department) is the **Attrition Risk report** in
  [Reports](./reports.md), linked from the page as "Workforce summary" ([ADR 0099](../decisions/0099-each-breakdown-is-a-decision-brief.md)).
  `?person=<employee id>` opens that person's breakdown, which is how the other
  predictive screens link to it.
- **The breakdown** is a decision brief in four tabs ([ADR 0099](../decisions/0099-each-breakdown-is-a-decision-brief.md)):
  - *Summary*: the score and its move since the person's previous run; a **verdict**
    (Act now / Check in soon / No action needed, or Exit in progress when an offboarding
    case is open) that reads the risk beside their forecast and readiness ("Likely to
    leave, and a strong performer: a loss the team would feel"), with notes on the
    strongest reason, inputs not on record and an old run; **suggested next steps**, each
    naming the reason on record behind it, with a stay conversation and questions built
    from the person's own reasons; **their other predictions** (readiness and forecast,
    each opening the person there); and how they **compare with** everyone in the run,
    their department and their position.
  - *Reasons*: a sentence, then a ledger that walks from a typical employee's score
    through each reason's points to this score ([ADR 0098](../decisions/0098-the-predictive-screens-say-why.md)); withdrawal is one reason,
    tagged *Emotional indicator*, with the two counts it was read from; *emotional
    factors*: the four behavioural indicators of emotional states the record reads on
    (withdrawal, career stagnation, workload strain, effort–reward imbalance), each with a
    level meter, what it was read from, whether the score reads it and its points, and
    the survey's leave rate at that level ([ADR 0101](../decisions/0101-the-attrition-model-reads-withdrawal.md)); *what the data shows*: the
    leave rate at every answer in the survey the model learned from, with this
    employee's answer picked out and a note where the survey alone points the other way;
    and *what it was read from*: every input, "Not on record" where it was estimated,
    overtime marked as not used by the model, and absences and lateness as read together
    as withdrawal. Runs from before ADR 0098 show the older
    factor list.
  - *What would lower it*: the model's score with a promotion now, regular employment
    (probationary and contract staff), a raise into the next pay band, and all of them
    together, sorted by how much each lowers risk; a change that would raise it is shown
    as such. Then what the model cannot see, to ask about.
  - *Record*: their risk in every run, attendance by month, appraisals by section,
    employment and promotions, leave, training and awards, each only for a viewer who
    may open that module.

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

   Values go in their natural units; the model bands them itself, and reads absences and
   late arrivals together as **withdrawal** — the ERP never computes an indicator
   ([ADR 0101](../decisions/0101-the-attrition-model-reads-withdrawal.md)). An employee with **no
   attendance tracked** in the window gets no attendance inputs (they are imputed, and
   confidence drops to 5/8) rather than zeros that would claim a perfect record. No
   demographic or protected attribute is ever sent.
3. The batch is scored by the inference service (`MlClient::predict('attrition', …)`).
4. The result is persisted as an `AttritionRiskRun` header (model version, tier counts,
   average risk and confidence, and the `reference` its scores are read against,
   indicator definitions included) with one `AttritionRiskScore` per employee
   (probability, score, tier, confidence, factors, `explanation` — baseline, scenarios
   and indicator readings — and the feature snapshot for audit). The run is
   activity-logged (`attrition-risk`).

If the service is unreachable, or running without the attrition model trained, the
action degrades gracefully — a plain "temporarily unavailable" toast, no run recorded —
and the page shows the same message as a banner; existing assessments stay visible.
Nothing about the model itself (algorithm, version, metrics) reaches the browser.

## The model

An **RBF support-vector machine**, isotonically calibrated, trained on the merged export
of an attrition survey (`model/data/raw/attrition_dataset.csv`), cleaned by
`model/synapse_ml/attrition/survey.py`: 199 usable responses, 55 who resigned
voluntarily and 144 still employed. See `model/notebooks/01_attrition_model.ipynb`. The
survey data lives in the git-ignored `model/data/` (see `model/data/README.md`).

- **Its inputs:** employment type, tenure, pay, time since the last promotion and whether
  there was one, and **withdrawal**: absences and late arrivals over 90 days, read
  together as a behavioural indicator of emotional disengagement
  ([ADR 0101](../decisions/0101-the-attrition-model-reads-withdrawal.md)). Overtime is
  recorded but not an input: its leave rate is flat across its bands.
- **How good it is:** ROC-AUC **0.715** on 30 splits no choice was made on (chance is
  0.50; the model before withdrawal scored 0.698), **0.678** in nested cross-validation
  (before: 0.671), PR-AUC 0.44 against a 0.28 base rate, permutation test p = 0.01. Real,
  but modest; the survey's ceiling is about 0.67–0.72.
- **Emotional factors:** four behavioural indicators are read for every person
  (`synapse_ml/attrition/indicators.py`): withdrawal (in the score), career stagnation
  (in the score through the promotion inputs), workload strain and effort–reward
  imbalance (tested, not in the score — both rest on overtime and made it less
  accurate). Pay against peers was tested and dropped. They indicate a feeling; they do
  not measure one.
- **Its limit:** the survey's two sources reached different populations, and a model
  trained on one does not rank the other well. Treat scores as a provisional,
  population-level prior until the model is retrained on this organisation's own
  departures.
- **Reading the score:** it is *relative*, not a probability — 70 means the profile
  looks much more like people who left than people who stayed. Tiers cut at 33 / 66.
- **Factors** are exact Shapley values against a typical employee (the medians and modes
  the pipeline's imputers learned): a typical employee's score plus every factor is the
  score, an input not on record is never a reason, and effects under two points are
  shown together as "smaller effects". Withdrawal is one factor, with the two counts it
  was read from. Each factor carries the survey's leave rates for the employee's band or
  level and the typical one (`model/artifacts/attrition/evidence.json`, written by the
  notebook beside the model; an organisation's own model stores its records' table). See
  [ADR 0098](../decisions/0098-the-predictive-screens-say-why.md).

## Where these scores come from (model graduation)

The page embeds the **model graduation** panel beneath its header (see
[Model graduation](./model-graduation.md) and
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md)):
these scores come from a survey of workers at other employers until the organisation's
own history can replace it.

This surface's own model would learn **who has actually resigned here**, from the
**stored risk scores** — each the record exactly as it was scored, since tenure, salary
and last quarter's attendance can't be rebuilt afterwards. Per person, scores are taken
at least a year apart; each counts once its year has passed; only a *resignation* is
leaving, and other exits (and departures with no offboarding record) are left out. The
surface stays *General model* until the first assessment stores scores. The checklist:

- **100 resignations within a year of a risk score** — with a projection at the
  organisation's recent pace, and notes on scores still inside their year;
- **100 risk scores followed by a year of staying** — fills on its own;
- **90 % of departures with a recorded type** — process every departure through
  Offboarding;
- **the prediction service is ready.**

A trained model is judged by how often it ranks someone who resigned above someone who
stayed (ROC-AUC), against the general model and a coin flip.

The field list shows **used in every score**: employment type, hire date, salary, time
since last promotion, and the three 90-day attendance counts. **Recorded, but not
used**: department, training completions, and departure type (counted against the
people who left — it is what the organisation's own model learns from). **Not recorded
anywhere**: engagement, pay against market.

## The assistant

`App\Services\Assistant\Modules\AttritionRiskModule`, on the shared
`PredictiveModule` ([ADR 0058](../decisions/0058-assistant-attrition-promotion-and-forecast.md)).
Reads need `analytics.attrition.view`; everything else `analytics.attrition.manage`.

- **Reads:** `attrition_risk_summary` (counts by tier, averages, the change since the
  previous assessment), `find_attrition_risks` (by tier, department or name, highest
  first), `get_attrition_risk` (score and tier, confidence, what moves it, the behavioural
  indicators — never effort–reward imbalance, which is read from pay — the inputs on
  record and those estimated, the previous assessment), `get_attrition_model_status`.
- **Pay never reaches the model.** `monthly_salary` is not stated, a factor it drove is
  left out, and the card says only that pay is one of the inputs.
- The guidance treats a score as a prompt for a supportive check-in — never grounds for
  discipline, dismissal or holding someone back, never to be told to the person.
- **Reads come from the stored runs**, never the live model, so they work while the
  inference service is off. Reading a named person's score is audited as `viewed`
  ([ADR 0027](../decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md));
  lists and summaries are not, and the pre-model topic brief carries counts only, never
  names.
- **Writes** go through `App\Support\Ml\AttritionRiskAssessor`, which gained `run($actor, $channel)` and
  `delete($run, $channel)` — the run controller now calls `delete()` too — and
  `ModelGraduation` (`train` / `activate` / `revert`, also with `$channel`). Each is
  audited "… via assistant".
- **Confirmed:** `delete_attrition_assessment` (the card says which run the page would show next) and
  `switch_attrition_model` (`own` — the newest model that passed its check — or `general`).
  `run_attrition_assessment` and `train_attrition_model` run directly: a run is a new snapshot, and training only
  produces a candidate.
- Words are the page's (`App\Support\Ml\PredictionWording`).

## Permissions

`analytics.attrition.view` (the overview & detail), `analytics.attrition.manage` (run /
delete an assessment; train, switch to and switch back from the organisation's own model). **HR Manager** gets both; **Department Head** gets view; Super
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
