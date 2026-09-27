# Model graduation, for real — and a panel people can follow

The graduation panel on Promotion Readiness, Performance Forecast and Attrition Risk was
a simulation: its counts were invented in the browser, and nothing could ever train.
It is now the whole lifecycle for all three surfaces. The requirements are counted from
the organisation's own records, and a model can be trained on them once they suffice.
That model is checked against the general model on the organisation's own people, and
it becomes the one that scores only when someone chooses to switch (reversibly). The
panel is rewritten so that anyone reading it can tell what graduation is and what is
still needed. See
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md)
(superseding [ADR 0031](../decisions/0031-model-graduation-frontend-only.md)) and
[Model graduation](../modules/model-graduation.md).

## Highlights

- **Real counts, from one source.** Each surface builds the exact examples its own model
  would learn from, with the same code producing the checklist and the training rows:
  - **promotion**: an appraisal, and whether a promotion followed before the next one;
  - **performance**: an appraisal and the next;
  - **attrition**: a stored risk score, and whether the person resigned within the year.

  Promotions with no appraisal before them, follow-ups still open, people who left early,
  lapsed appraisal pairs, other kinds of exit and unrecorded departures are all left out,
  and the panel names each group with its count.
- **Defensible thresholds.**
  - **100 of the rarer outcome** (Collins et al., 2016) for promotion and attrition.
  - **235 comparisons** (Riley et al., 2019) for performance, forecasting across three
    cycles in a row.
  - **80 %** of appraisal pairs on an unchanged form, and **90 %** of departures with a
    recorded type.

  The inference service enforces the same minimums.
- **Offered only if it's better.** The service fits the surface's own model class on the
  organisation's records and scores every example with a model that never saw that
  person. It must beat the general model *and* knowing nothing in 90 % of 1,000
  resamples of the organisation's people, and a forecast's ranges must hold 70–90 % of
  the ratings that followed. Failures are recorded with the reason, in plain words, and
  nothing is stored.
- **Switching is a decision.** Passing changes nothing on its own. Someone who manages
  the page switches to the new model (after a confirmation) and can switch back. Every
  run records whose model scored it. If the organisation's model is missing, a run fails
  with a message saying so rather than quietly using the general one.
- **A panel written for its reader.**
  - A one-line headline on whose data is behind the scores.
  - *What is model graduation?* in four short points.
  - *General model → Collecting your history → Your own model*, with "you are here".
  - A checklist with **Still needed: 86 more promotions**, **What you can do**, notes on
    records that can't count yet, and "at your recent pace" projections.
  - The check's result as three numbers in words (*your model / general model / knowing
    nothing*).
  - The statistical reasoning behind "Why this number?".

## Model (`model/`)

- **`synapse_ml/local/`** (new):
  - `training.py` covers the examples, `MINIMUMS`, grouped out-of-fold scoring, the
    paired group bootstrap, the verdict and the plain-language findings.
  - `store.py` keeps per-organisation storage under `artifacts/local/<tenant>/<model>/<version>/`,
    with tenant keys and versions pattern-checked before touching the filesystem.
- **`POST /train/{model}`** fits, checks and stores a passing model. **`POST /predict/{model}`**
  accepts a `variant` (tenant + version) and answers `model_version: "local:<version>"`;
  an unknown variant is a 404, never a fallback. The registry serves reference and
  organisation models through one code path.
- **`PatternRouter.fit(complete=False)`** fits each history pattern on the rows that carry
  its inputs, so a first appraisal without a change still trains the latest-only
  submodel. It still never guesses a value.
- **`PerformanceForecastModel.for_sample(n)`** scales leaf size and conformal regions to
  a few hundred rows, and keeps people whole across the calibration split.
- **Local models read inputs in the range their own records span**, not the reference's.
- **Attrition preprocessing** keeps a column nobody recorded (no attendance tracked)
  instead of dropping it and misaligning every band after it. This makes no difference
  to the reference model.
- Tests: `test_local_training.py` covers synthetic organisations with and without a
  pattern, per surface, plus the store's isolation and path safety. `test_api_local.py`
  checks that a model trained through `/train` serves only its organisation, that a
  failed check stores nothing, and the minimums and tenant validation. **134 passed.**

## Server

- **Migration** `2026_09_27_000000_create_local_models` adds the `local_models` table
  (every attempt, and its status, comparison, findings and counts) and a nullable
  `local_model_id` on the three surfaces' runs.
- **`App\Support\Ml\Graduation\`**:
  - `ModelGraduation` runs check / train / activate / revert, activity-logged.
  - `PromotionGraduation`, `PerformanceGraduation` and `AttritionGraduation` each provide
    their surface's training set, requirements and fields.
  - Supporting pieces: `FieldCounts` (real field coverage), `Departure`, `Pace`
    (projections that stop at "well over 20 years"), `Requirement`, `TrainingSet`,
    `GraduationException`.
- **`AppraisalHistory`** now also carries each appraisal's period and a fingerprint of
  the form it was scored on.
- **Assessors** score with the surface's active local model when there is one and
  record `local_model_id`. Run resources expose `scored_by`, and promotion runs expose
  `base_rate`.
- **`MlClient::train()`** has its own timeout (`ML_SERVICE_TRAIN_TIMEOUT`, default 300s).
  `predict()` takes a variant, and a missing local model gets its own message.
- **Routes**: `POST|DELETE analytics/<surface>/graduation` and
  `POST analytics/<surface>/graduation/{localModel}/activate`, behind each surface's
  `*.manage` permission and registered before `{run}`. The index pages pass a
  `graduation` prop.
- Tests: `ModelGraduationTest` (18) covers each training set's rules, the gate, pass,
  fail, supersede, switch and switch back, a missing model, permissions and tenant
  isolation. **The full suite is 1105/1105 green.**

## Frontend

- **`features/model-graduation/`** is rewritten around the server's `graduation` prop.
  - Components: `GraduationPanel`, `StageRail`, `RequirementChecklist`,
    `RequirementDialog`, `TrainingPanel`, `FieldCoverageTable` (now collapsible, real
    counts) and `ScoredBy`.
  - Plumbing: `use-graduation.ts`, `api.ts`, `routes.ts`.
  - Removed: `mock-engine.ts`, `model-provenance.tsx` and `requirement-ledger.tsx`.
- Meters use a lighter step of their fill colour as the track. Status always comes with
  an icon and a label.
- Page copy follows whose model scored the run. Promotion odds use the organisation's own
  promotion rate, "50" is the middle of its own history, and the forecast detail and
  track record stop saying "reference workforce". Run lines read "scored by your own
  model".

## Notes

- **Verified end to end on the real stack** (inside a transaction that was rolled back,
  with the test artifacts deleted afterwards). Synthetic histories large enough to open
  each gate were trained, passed their check, were switched to and scored runs:

  | Surface | Measure | Your model | General model | Other |
  |---|---|---|---|---|
  | Promotion | Prediction error | 0.159 | 0.250 | — |
  | Performance | Average miss | 4.4 pts | 4.8 pts | ranges held 79 % |
  | Attrition | Tells leavers from stayers | 64 % | 44 % | — |

- **On the development database** organisation 1 is *collecting* on promotion and
  performance and still on the general model for attrition, which has no stored scores
  yet. Its checklist says:
  - 13 promotions on record can't count, because no appraisal came in the year before
    them;
  - 3 more will count once their follow-up closes;
  - at about 8 promotions a year, the 100 needed is roughly 13 years away.
- Run `php artisan migrate` for the new table and column.
