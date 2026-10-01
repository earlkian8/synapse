# 0058 — Attrition Risk, Promotion Readiness and Performance Forecast join the assistant; a score is read, not recomputed, and pay stays out

- **Status:** Accepted
- **Date:** 2026-09-29
- **Extends:**
  - [0027 — Assistant employee retrieval behind a disclosure policy](./0027-assistant-employee-retrieval-and-disclosure-policy.md) (withheld fields, `viewed` audit);
  - [0045 — Performance and promotion models that can be relied on](./0045-performance-and-promotion-models-that-can-be-relied-on.md);
  - [0046 — Model graduation](./0046-model-graduation-trains-on-the-organisations-own-records.md);
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md).
- **Related:** [Attrition Risk](../modules/attrition-risk.md#the-assistant),
  [Promotion Readiness](../modules/promotion-readiness.md#the-assistant),
  [Performance Forecast](../modules/performance-forecast.md#the-assistant).

## Context

The three Predictive Workforce Analytics surfaces were the last screens without an
assistant capability. They differ from every other module in one way: what they hold
are **statistical judgements about people** (how likely someone is to leave, how their
record compares with people who were promoted, what their next appraisal is likely to
be), made by a model and stored as runs.

Three things had to be settled before chat could reach them:

- **Pay.** The attrition model takes monthly salary. The page shows it, as an input and
  as a factor, to anyone who may see the page. ADR 0027 withholds pay from the
  assistant for everybody, because a tool result is sent to the model provider and kept
  in the transcript.
- **What a score is for.** A risk score is a prompt for a supportive conversation. A
  readiness score is one input to a human decision. A forecast is a planning aid. None
  is a verdict, and a model that reads them back with confidence can make them sound
  like one.
- **Where scores come from.** The inference service is a separate process. It is often
  off, and it is slow over a whole workforce.

The canonical classes already existed (`AttritionRiskAssessor`,
`PromotionReadinessAssessor`, `PerformanceForecaster`, `ModelGraduation`). But deleting
a run lived in the three controllers, and nothing took an audit channel.

Auditing the existing assistant also found a live bug. `PerformanceModule`'s appraisal
brief described forecasts as "on the 1–5 index". Since ADR 0045 they are attainment on
0–100 (the bands start at 60 and 80), so every forecast it quoted was mislabelled.

## Decision

### 1. One base, three surfaces

`PredictiveModule` holds what the three share. `AttritionRiskModule`,
`PromotionReadinessModule` and `PerformanceForecastModule` supply their words, their
runs and their detail lines. Each surface gets the same eight tools, named for it:

| Role | Attrition | Promotion | Forecast | Permission |
| --- | --- | --- | --- | --- |
| latest run's summary | `attrition_risk_summary` | `promotion_readiness_summary` | `performance_forecast_summary` | view |
| ranked roster (tier/band, department, name; who was declined) | `find_attrition_risks` | `find_promotion_readiness` | `find_performance_forecasts` | view |
| one person, and what is behind it | `get_attrition_risk` | `get_promotion_readiness` | `get_performance_forecast` | view |
| model graduation status | `get_attrition_model_status` | `get_promotion_model_status` | `get_forecast_model_status` | view |
| run a new one | `run_attrition_assessment` | `run_promotion_assessment` | `run_performance_forecast` | manage |
| delete one (confirmed) | `delete_attrition_assessment` | `delete_promotion_assessment` | `delete_performance_forecast` | manage |
| train the organisation's own model | `train_attrition_model` | `train_promotion_model` | `train_forecast_model` | manage |
| switch own ↔ general (confirmed) | `switch_attrition_model` | `switch_promotion_model` | `switch_forecast_model` | manage |

The permissions are the screens' own: `analytics.<surface>.view` and `.manage`.

### 2. Reads use the stored runs

Summaries, rosters and one person's score come from the persisted runs, never from the
live model. A question is answered whether or not the inference service is up. Only
running, training, and the graduation status's "is the service ready" check reach the
service. Their failures come back as the screen's own words (`MlException`,
`GraduationException`), never a status code.

### 3. Said the way the page says it

`App\Support\Ml\PredictionWording` holds the screens' vocabulary on the server:

- the tiers and bands, and what each means;
- why a person was declined, and what would include them;
- the attrition inputs and how each is formatted;
- one forecast line: "74% (likely 66–81) · On track, 71% chance".

Specific statements match the page too:

- Promotion odds are stated against the right average (`PromotionReadinessRun::baseRate()`,
  moved from the run resource so both use it): the organisation's own promotion rate when
  its own model scored the run, else the reference workforce's 10%.
- The forecast's "how it did" is `ForecastTrackRecord`, with the page's rule of no
  verdict below 20 checked forecasts.
- Risk and readiness scores are whole numbers, as on the page.

### 4. Pay stays out, a named read is audited, and the guidance says what a score is not

- **Pay:**
  - `monthly_salary` is never stated;
  - a factor it drove is dropped;
  - the card says only "Pay is also one of its inputs; it is not discussed here";
  - the guidance forbids discussing pay.
- **Audit:** reading a named person's score is written as `viewed` (ADR 0027), for
  example "Viewed the attrition risk of Maria Santos via assistant". Lists and summaries
  are not.
- **Topic context is aggregate only.** A question like "who is at risk of leaving?"
  brings counts and averages before the model is called, never names. Names come from
  the roster tool the model has to call.
- **The guidance names the limits:**
  - attrition risk is a prompt for a supportive check-in, never grounds for discipline,
    dismissal or holding someone back, never to be told to the person, and not to be
    explained beyond its factors;
  - readiness is one input to a human decision;
  - a forecast is not a rating.

### 5. The screens' own paths, with a channel

- Each runner gains `run($actor, $channel)` and `delete($run, $channel)`. The three run
  controllers now call `delete()` instead of deleting and logging themselves.
- `ModelGraduation::train/activate/revert` take `$channel` too. The assistant's writes
  are audited "… via assistant".
- **What waits for Confirm:**
  - deleting a run, and switching the model, wait. The card says what the page would
    then show ("The page would show the one of Mar 15, 2025 instead") or whose model
    would score the next run;
  - running and training do not. A run is a new snapshot, and training only produces a
    candidate, which switching then has to adopt.

### 6. The forecast line is fixed where it was wrong

`PerformanceModule` quotes a forecast through `PredictionWording::forecast()`. Its test
had been written with 1–5 data and a band that no longer exists. It now uses the real
scale.

## Consequences

- **"Who is at risk of leaving in Sales?", "why is Maria at watch?", "who is ready for
  promotion?", "who did the assessment leave out?", "how did the last forecast do?",
  "is our own model ready?"** are answered in chat, with the page's numbers and words.
- **A person's pay never reaches the model through these tools**, even though it moves
  their risk score.
- **The tool budget grows by up to 24 declarations** for someone with every analytics
  permission. A view-only user is offered four per surface.
- **Every screen in the app now has an assistant capability.**

## Alternatives considered

- **Scoring live through the model on each question.** This would be slower, would fail
  whenever the service is off, and would disagree with the page, which shows a run.
  Rejected.
- **One combined "analytics" module with a surface argument.** It would mean fewer
  declarations, but each tool's description, enum and guidance would have to cover
  three different scales at once, which is where a model mixes them up. Rejected in
  favour of surface-named tools over one shared base.
- **Letting the assistant state pay "only as a factor"** ("pay raises her risk"). Even
  without the amount, that tells the reader something about the person's pay relative
  to others. Rejected.
- **Confirming runs and training too.** Neither changes what anyone sees until the page
  is re-read or the model is switched, and both are audited. The Confirm is kept for
  the two acts that change the page: deleting and switching.
