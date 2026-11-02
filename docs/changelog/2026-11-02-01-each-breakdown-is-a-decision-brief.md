# Each breakdown is a decision brief

Attrition Risk, Promotion Readiness and Performance Forecast now open each person as a
decision brief. It says how urgent the case is, what to do next and why, how the person
looks on the other two screens, what would change the score, and the record around it.
The workforce charts move off the pages into three new reports. See
[ADR 0099](../decisions/0099-each-breakdown-is-a-decision-brief.md).

## Highlights

- **A verdict, not just a score.** Each breakdown opens with a read HR can act on, with
  the person's other predictions weighed in: "Likely to leave, and a strong performer
  (forecast to exceed expectations): a loss the team would feel. Talk with them this
  week." Notes flag what limits trust: inputs not on record, one appraisal only, an old
  run, a forecast pulling toward the middle. An open offboarding case turns it into
  "Exit in progress".
- **Next steps with their reasons.** "Check in on how they are settling in: 7 mos here
  without a promotion adds +17 points. In the data the model learned from, 36% of people
  at '< 1 yr' left, against 24% at '3–5 yrs'." A stay conversation lists questions built
  from the person's own reasons. Promotion steps name the result the next appraisal
  needs and the weakest appraisal section. Forecast steps cover goals, coaching and a
  mid-cycle check-in.
- **Their other predictions, side by side.** Risk, readiness and forecast for the same
  person, each opening them on that screen.
- **What would lower it (attrition).** The model's score with a promotion now, regular
  employment or a raise into the next pay band, and all of them together. A change that
  would *raise* risk is shown as such.
- **If the forecast holds (promotion).** The forecast's likely range is drawn on the
  next-appraisal curve, with the rough chance of reaching the next tier ("about 1 in 9").
- **Track record (forecast).** This person's earlier forecasts against what each cycle
  actually gave.
- **Compared with.** Everyone in the run, their department and their position, as dot
  strips with the person picked out and their place in words.
- **The record.** Their score in every run, attendance by month, appraisals by section,
  employment and promotions, leave, training and awards. Each part needs the permission
  of the module it comes from.
- **Workforce summary in Reports.** New Attrition Risk, Promotion Readiness and
  Performance Forecast reports (group *Analytics*). Each covers the latest run and can
  be filtered by department, tier or band and searched. It has charts and exports to
  CSV. Each analytics page links to its report.

## Backend

- `App\Support\Ml\PredictionContext` + `PredictionContextController`:
  `GET analytics/{attrition|promotion-readiness|performance-forecast}/people/{employee}`,
  behind the surface's `*.view` permission. Each record block is gated by its module's
  own view permission. Tenant-scoped and read-only. JSON errors via `bootstrap/app.php`.
- `PredictionExplanation` stores attrition `scenarios`.
- Reports: `AttritionRiskReport`, `PromotionReadinessReport`,
  `PerformanceForecastReport`; shared `Concerns\ReadsPredictionRun`; `averagesBy()` in
  `BuildsReport`. The Reports sidebar entry, the system guide and the Help Center now
  include the three analytics permissions.

## Model service

- `Registry.scenarios` scores each attrition record with a promotion now, regular
  employment (probationary and contract staff only) and a raise to the lowest figure of
  the next pay band, plus all that apply together. Only inputs on record are changed.
  One batched `predict`; `scenarios` added to `api/schemas.py`.

## Frontend

- `features/prediction-insights`: new dialog frame with underline tabs, verdict card,
  next steps, other predictions, peer comparison, score trend (with forecast ranges and
  actuals), record panels, context fetch hook (with retry). The overview pieces are
  removed: chart card, driver chart, by-department bars, histogram, workforce position.
- `brief.ts` in each feature: the verdict and step rules, plain logic over what is on
  record.
- The appraisal map can pick out one person; the next-appraisal chart can draw the
  forecast's range.
- Pages: the overview charts are removed, a "Workforce summary" link is added, and
  `?person=` opens a breakdown.

## Notes

- No model is called for the verdicts or steps, and no Gemini call was made.
- Scenarios exist only on runs scored after this change; older runs say so.
- The "chance of reaching" figure joins two models and is labelled a rough guide.
