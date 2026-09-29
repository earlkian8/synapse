# Assistant: Attrition Risk, Promotion Readiness and Performance Forecast

The assistant gains retrieval (RAG) and function calling for the three Predictive
Workforce Analytics surfaces:
- **attrition risk**;
- **promotion readiness**;
- the **performance forecast**.

Each capability reads the stored runs, runs and deletes them through the screens' own
classes, and handles model graduation. These scores are statistical judgements about
people, so pay never reaches the model, a named person's score is audited when read,
and the guidance says what a score is not. With this, every screen in the app has an
assistant capability. See
[ADR 0058](../decisions/0058-assistant-attrition-promotion-and-forecast.md).

## Highlights

- **These now work in chat, with the page's numbers and words:**
  - "Who is at risk of leaving in Sales?", "why is Maria at watch?";
  - "who is ready for promotion?", "who did the assessment leave out?";
  - "who is forecast below target?", "how did the last forecast do?";
  - "is our own model ready?"
- **Running an assessment or forecast, and training the organisation's own model,**
  work in chat. **Deleting a run and switching the model** wait for a Confirm that says
  what the page would show next.
- **Pay is never discussed.** The attrition model uses it, but the assistant never
  states it or the factor it drove.
- **Reads come from the stored runs**, so questions are answered while the inference
  service is off.

## Fixes

- **The assistant quoted performance forecasts "on the 1–5 index".** Forecasts are
  attainment on 0–100 (ADR 0045), so every forecast in the appraisal brief was
  mislabelled. It now reads "74% (likely 66–81) · On track, 71% chance".

## Backend

- **`PredictiveModule`** is a shared base with eight tools per surface: summary,
  roster, one person, model status, run, delete, train and switch. On it:
  - **`AttritionRiskModule`** (`analytics.attrition.*`);
  - **`PromotionReadinessModule`** (`analytics.promotion.*`);
  - **`PerformanceForecastModule`** (`analytics.performance.*`).

  Reads need `.view`, and everything else needs `.manage`.
- **`App\Support\Ml\PredictionWording`** holds the screens' vocabulary on the server:
  - tiers, bands and their meanings;
  - why someone was declined;
  - the attrition inputs, with pay withheld;
  - the one-line forecast.
- **Runners:** `AttritionRiskAssessor`, `PromotionReadinessAssessor` and
  `PerformanceForecaster` take an audit `$channel` and gain `delete($run, $channel)`.
  The three run controllers call it.
- **`ModelGraduation::train/activate/revert`** take `$channel`.
- **`PromotionReadinessRun::baseRate()`** and `REFERENCE_RATE` moved from the run
  resource, so the page and the assistant state the same odds.
- **The assistant's `PerformanceModule`** quotes forecasts through `PredictionWording`.
- **New IMPERATIVES:** run, rerun, train, switch, assess, rescore.

## Frontend

- **The chat button** also appears for `analytics.attrition.view`,
  `analytics.promotion.view` and `analytics.performance.view`.
- **New suggestions:** "Who is at risk of leaving?", "Who is ready for promotion?" and
  "Who is forecast below target next cycle?".

## Verification

- **Pest:** the full suite passes (1378 tests: the previous 1369 plus 9 new).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database (13 seeded attrition
  runs):
  - `get_attrition_risk` and a held `delete_attrition_assessment` went through the real
    assistant;
  - the read card showed the inputs without pay and said pay is not discussed;
  - the Confirm card said "The page would show the one of Mar 15, 2025 instead";
  - confirming deleted the run, and the Attrition Risk page then showed Mar 15, 2025 as
    the latest;
  - both the read (`viewed`) and the delete were audited via assistant;
  - no console errors beyond the seeded avatars' known CSP blocks.
- The inference service was not running. The live run path is covered by an
  HTTP-faked test, including an unreachable service.

## Tests

- `Analytics/PredictiveAssistantTest` (9):
  - permissions and which calls wait for Confirm;
  - the attrition summary, roster and read, with pay absent from every card;
  - promotion odds against the reference average, and who was declined and why;
  - the forecast line, the actual result, and "too few to judge";
  - a run through the screen's path, and an unreachable service;
  - deleting by latest, previous or date;
  - switching models only to one that passed;
  - the model status;
  - tenancy, and a brief that carries no names.
- `Performance/PerformanceAssistantTest`: the forecast line uses the real 0–100 scale.
