# 0099 — Each breakdown is a decision brief; the workforce summary moves to Reports

- **Status:** Accepted
- **Date:** 2026-11-02
- **Related:**
  - [0098 — The predictive screens say why](./0098-the-predictive-screens-say-why.md)
    (the explanations this builds on, and the overview charts it moves);
  - [0046 — Model graduation](./0046-model-graduation-trains-on-the-organisations-own-records.md)
    (an organisation's own attrition model gets scenarios too);
  - module docs: [Attrition Risk](../modules/attrition-risk.md),
    [Promotion Readiness](../modules/promotion-readiness.md),
    [Performance Forecast](../modules/performance-forecast.md),
    [Reports](../modules/reports.md).

## Context

ADR 0098 made every score explain itself, and it gave each page a workforce overview:
how scores spread, what drives them, and scores by department. Two things were wrong
with where that left the screens.

- **The overview is a summary, not a decision.** HR opens these pages to decide about
  people. Four charts above the list pushed the people down the page, and they repeat
  what a report is for: the whole workforce, filtered, printed and exported.
- **The breakdown explained the model, not the decision.** It showed how a score adds up
  and what the survey holds. It did not say how urgent the case is, what to do next,
  or how the person looks on the other two screens. A high flight risk means one thing
  for someone forecast to exceed expectations and another for someone forecast below
  target. It also did not show whether the score is rising, how the person compares
  with their department, what would lower it, or the record the model cannot read:
  leave, appraisal sections, training, awards, an exit already filed.

## Decision

**The workforce summary moves to Reports.** Three reports join the catalogue under a new
*Analytics* group: Attrition Risk, Promotion Readiness and Performance Forecast
(`App\Support\Reports\Reports\*`, shared parts in `Concerns\ReadsPredictionRun`). Each
reads its surface's latest completed run and lists everyone in it with the tier, the
strongest reason and the record behind it. Each can be filtered by department, tier or
band and a search, and exports to CSV. Its charts are the split by tier, the average by
department, and, for attrition, the inputs that raise risk for the most people. Each
report needs its surface's `*.view` permission. Pay is never a column. The analytics
pages drop the overview and link to their report ("Workforce summary").

**Each breakdown is a decision brief in four tabs.** Summary comes first, so the decision
is the first thing read and the evidence is one tab away.

- **Summary.** The score with its move since the person's previous run. Then a
  **verdict**: how urgent it is, in a sentence that reads the score beside the other two
  screens and the record ("Likely to leave, and a strong performer: a loss the team
  would feel"), with notes on anything that limits trust: an old run, inputs not on
  record, one appraisal only. Then **suggested next steps**, each naming the reason on
  record behind it; a stay conversation lists questions to ask, built from the person's
  own reasons. Then **their other predictions**, each opening the person on that
  screen (`?person=`). Last, **compared with** everyone in the run, their department
  and their position.
- **Reasons.** The ledger and evidence from ADR 0098. Promotion adds the appraisal map
  with the person picked out; selecting another dot opens that person. Performance adds
  the latest appraisal by section.
- **The third tab is per surface.** *What would lower it* (attrition) shows the model's
  score with a change HR can make. *Next appraisal* (promotion) shows the outlook curve
  with the forecast's likely range on it, and the rough chance of reaching the next
  tier. *Track record* (performance) shows this person's earlier forecasts against what
  each period actually gave.
- **Record.** The person's score in every run, attendance by month, appraisals by
  section, employment and promotions, leave, training and awards.

The verdict and the steps are plain rules over what is on record (`brief.ts` in each
feature). No model is called and Gemini is never involved, so they cost nothing and say
nothing the record does not show. A reason the data does not back on its own (for
example "no absences" raising risk in the survey) prompts no step.

**Attrition scenarios are scored with the run** (`Registry.scenarios` in
`model/api/registry.py`). For each record the service also scores the same record with
a promotion now, with regular employment (for probationary and contract staff), with a
raise to the lowest figure of the next pay band the model reads, and with all of these
together. Only changes that apply to the record are scored, and only on inputs that are
on record. The scores come from one extra batched `predict`. They are stored in the
score's `explanation.scenarios` (`PredictionExplanation`), and the dialog sorts them by
how much they lower risk. A change that would *raise* risk is shown as such: in the
survey, people promoted under a year ago left more often, and the screen says so rather
than hiding it. The copy says these show what the model weighs, not what will happen.

**The rest of the context is fetched when the dialog opens**:
`GET analytics/{surface}/people/{employee}` (`PredictionContextController`, built by
`App\Support\Ml\PredictionContext`). It needs the surface's `*.view` permission. Every
block of the record is gated by its own module's view permission (`attendance.view`,
`leave.view`, `performance.view`, `employees.view`, `training.view`, `awards.view`,
`offboarding.view`, and each other surface's `*.view`), so a viewer who cannot open
Leave gets `leave: null`. Promotions show positions, never pay. Fetching on open keeps
the page payload as it was, and a person seen once is not fetched again in that visit.
A failed fetch leaves the prediction itself readable and offers a retry.

## Consequences

- The pages are shorter and the list of people comes first. The workforce view is a
  click away, and it now filters, prints and exports, which the overview charts never
  did.
- The kit in `features/prediction-insights` changes: the overview pieces (chart card,
  driver chart, by-department bars, histogram, workforce position) are removed. The
  dialog frame, verdict, steps, other predictions, peer comparison, trend chart and
  record panels are added.
- Scenarios exist only on runs scored by a service with this change. Older runs show a
  note instead. An older service still works; its runs have no scenarios.
- The chance of reaching the next readiness tier joins two models (the forecast's range
  read as a normal spread, and the outlook curve). The screen calls it "about" and says
  it is a rough guide.
- The context endpoint is another place records are read. It reuses the modules'
  permissions and tenant scopes, and it is read-only.
