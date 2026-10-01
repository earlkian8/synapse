# 0046 — Model graduation trains on the organisation's own records

- **Status:** Accepted
- **Date:** 2026-09-27
- **Supersedes:** [ADR 0031 — Model graduation panels, embedded per surface](./0031-model-graduation-frontend-only.md)
  (its per-surface placement stands; its simulated counts and its "do not ship
  retraining" decision do not).
- **Related:** [Model graduation module](../modules/model-graduation.md),
  [model-graduation tables](../database/model-graduation-tables.md),
  [0043 — Attrition Risk trained on the attrition surveys](./0043-attrition-risk-trained-on-the-attrition-surveys.md),
  [0045 — Performance and promotion models that can be relied on](./0045-performance-and-promotion-models-that-can-be-relied-on.md),
  [0028 — Appraisal frameworks](./0028-appraisal-frameworks-and-tenant-rating-models.md).

## Context

ADR 0031 put a graduation panel on each predictive surface. It said, correctly, that
the scores came from someone else's workforce and that a model trained on a small
organisation's records would look confident on noise. But the panel was a simulation:
its counts were invented in the browser (`mock-engine.ts`, `localStorage`), and nothing
could ever train. Two things have changed since:

- **The groundwork now exists.** ADR 0043 and ADR 0045 rebuilt all three models on
  short contracts of facts the ERP records, stored every score per employee, and read
  the appraisal record through one class (`AppraisalHistory`). A model fitted on the
  organisation's own records can now use exactly the contract the general model
  uses — so it can be served through the same pages, and compared like for like.
- **Readers could not follow the panel.** It spoke in its own vocabulary
  ("provisional", "outcome linkage", "binding constraint", "held-out rows") and did not
  say what a reader could do about any of it.

## Decision

**Build the whole lifecycle for all three surfaces: count the real examples, open the
gate only when they suffice, train, check against the general model on the
organisation's own people, and switch — deliberately, reversibly — only to a model that
passed.**

### 1. The gate counts exactly what training would use

Each surface has one builder (`App\Support\Ml\Graduation\{Promotion,Performance,Attrition}Graduation::trainingSet()`)
that assembles the labelled examples from the organisation's tables. The requirements
on the page are counts *of that set*, and the rows sent to the service are *that set*,
so the checklist and the training can never disagree.

| Surface | One example | Outcome | Its own rules |
|---|---|---|---|
| Promotion | a completed appraisal (its attainment, and its change on the previous one) | a promotion before the person's next completed appraisal, or within a year | each promotion is credited once, to the latest appraisal before it; an example is used only once its follow-up has closed; someone who left before then without a promotion is left out |
| Performance | a completed appraisal | the person's next completed appraisal | consecutive cycles only — different periods, at most 400 days apart |
| Attrition | a **stored risk score** — the record exactly as it was scored | resigned within the following year | per person, scores at least a year apart; only a *resignation* is leaving; other exits, and departures with no offboarding record, are left out |

Attrition learns from stored snapshots rather than reconstructed records because
tenure, salary and last quarter's attendance *as they were* cannot be rebuilt
afterwards — the stored score is the only faithful record. That is also why ADR 0031's
"outcome linkage" requirement is not a checklist item any more: it is how the examples
are made.

### 2. Thresholds come from sample-size research, and the service enforces them too

| Surface | Requirements |
|---|---|
| Promotion | 100 promotions that followed an appraisal; 100 appraisals not followed by one; 50 promotions with two appraisals before them; 80 % of appraisal pairs on an unchanged form |
| Performance | 235 rating-to-next-rating comparisons; 2 review cycles with the one before to compare (three in a row); 80 % on an unchanged form |
| Attrition | 100 resignations within a year of a score; 100 scores followed by a year of staying; 90 % of departures with a recorded type |
| All three | the prediction service is up with the general model loaded (it is what the new one is compared against) |

- **100 of the rarer outcome** (Collins, Ogundimu & Altman, 2016): below that, the
  check below cannot tell a better model from a lucky one.
- **234 + one per input** for a continuous outcome (Riley et al., 2019): what pins the
  residual spread — which is what the forecast's likely range is built from — within
  10 %.
- **Unchanged form** documents ADR 0028's cost: a change in score across an edit of an
  appraisal framework partly measures the edit.

ADR 0031's "120 promotions" was 10–20 events per input over a dozen inputs; the
models now read one or two, so the binding question is no longer how many inputs can be
estimated but whether the comparison can be trusted — hence 100 of each outcome.

`model/synapse_ml/local/training.py` holds the same minimums (`MINIMUMS`) and refuses
(`422`) below them, rather than trusting the caller.

### 3. Same model class, same contract, judged on the organisation's own people

`POST /train/{model}` fits the surface's own class — `PromotionReadinessModel`
(pattern submodels, now able to fit each pattern on the rows that carry its inputs),
`PerformanceForecastModel.for_sample(n)` (conformal regions scaled to a few hundred rows,
people kept whole across the calibration split), or the attrition forest (whose imputers
now keep a column nobody recorded rather than dropping it). The local model reads its
inputs in the range *its* records span.

Two small-sample adjustments, found by training on the seeded history (see
*Demo history* below), keep the local models from failing for reasons of their own:

- **The local forecast is a monotone line** (`MonotoneLine`), not boosted steps. On a
  few hundred comparisons a boosted model's steps are coarser than the relationship:
  on the seeded history it missed by 4.50 points — worse than simply repeating the last
  rating (4.62, so it lost that check) — where a line misses by 4.32. A negative slope
  is flattened, so a better rating still never forecasts a worse one.
- **Promotion calibration falls back to plain Platt scaling** when the quadratic bend
  turns back inside the scores it was fitted on — at a few hundred rows that bend is
  noise. It used to refuse outright, failing a model with a real signal. A score whose
  direction the outcomes contradict is still refused.

The reference models are unaffected: their fits never take either branch.

It is offered only if, out of fold (every example scored by a model fitted without that
employee), it is:

1. more accurate than the general model — promotion by Brier score, performance by
   mean absolute error, attrition by ROC-AUC (its scores are relative, not calibrated);
2. more accurate than knowing nothing — the organisation's usual promotion rate,
   repeating the last rating, a coin flip;
3. each in at least **90 % of 1,000 resamples of the organisation's people** (each
   person drawn with all their examples), so a lucky split cannot carry it;
4. for a forecast, with ranges that held 70–90 % of the ratings that followed (80 %
   promised).

A model that passes is stored under `artifacts/local/<tenant>/<model>/<version>/` —
never loaded for another tenant; keys and versions are pattern-checked before touching
the filesystem. One that fails is recorded with the reason and nothing is stored. Every
finding is written as a sentence for the HR reader ("…missed by 4.4 points on average,
against 4.8 for the general model, and came out ahead in 95 % of re-checks").

### 4. Switching is a separate, reversible decision

`local_models` records every attempt (`failed` → nothing stored; `ready` → passed;
`active` → scoring the surface, at most one; `retired`). Passing changes nothing:
someone with the surface's `*.manage` permission switches to it, confirmed in a dialog,
and can switch back at any time. From then on the assessor sends
`variant: {tenant, version}` and the run records `local_model_id`, so every run says
whose model scored it. If the organisation's model is missing from the service, the run
fails with a message saying so — it is **never** quietly scored by the general model.

### 5. A panel written for the person reading it

The panel (`GraduationPanel`) leads with one sentence on whose data is behind the
scores, then — expanded — *what graduation is* (four short points), *where this page
is* (General model → Collecting your history → Your own model, with "you are here"),
*what's needed* (a checklist: progress in words, "still needed: 86 more promotions",
*what you can do*, and notes on records that exist but can't count yet), and *your own
model* (train, the check's result in three numbers, switch / switch back). The
statistical reasoning is behind "Why this number?". Field coverage is real, collapsed
beneath.

### 6. Demo history

`WorkforceHistorySeeder` gives the demo company seven years of history — about 120
people at a time with realistic turnover, annual appraisals FY 2019–FY 2025 as real
scorecards, promotions decided on them, departures through Offboarding, and a risk
assessment every March and September — with patterns of its own (promotion on level and
improvement at twice the general rate; ratings regressing to the mean; resignations
driven by overtime and disengagement). Every requirement on all three surfaces is met,
and a model trained on it passes its check on each (see
[the module doc](../modules/model-graduation.md#demo-history)).

## Consequences

- **Nothing on the page is simulated.** On the development database: organisation 1's
  promotion surface is *collecting* — 13 promotions on record can't count (no appraisal
  in the year before), 3 more will once their follow-up closes, and at about 8 a year
  the 100 needed is roughly 13 years away. Its attrition surface has no scores stored
  yet and says so.
- **Verified end to end on the real stack** (rolled back): synthetic histories large
  enough to open each gate trained, passed and scored runs with the organisation's own
  model — promotion prediction error 0.159 against the general model's 0.250;
  performance average miss 4.4 against 4.8 points, ranges holding 79 %; attrition
  ranking 64 % against 44 %. Organisations with no signal get a failed check and keep
  the general model.
- **For most organisations the gate stays shut for years**, and the page now says so in
  plain numbers rather than implying otherwise.
- **Out of scope:** scheduled retraining, recalibrating the general model to the
  organisation's base rate (ADR 0031's alternative — still viable, still a separate
  decision), and adding inputs the general model lacks (the local model keeps its
  contract, so it can be compared and served unchanged).

## Alternatives considered

- **Keep the simulation (ADR 0031).** Rejected: the groundwork for the real thing now
  exists, and a panel of invented numbers undermines a page whose whole point is being
  honest about data.
- **Adopt a passing model automatically.** Rejected: it changes what everyone's scores
  mean from the next run on; that is a decision for a person, and it must be
  reversible.
- **Fall back to the general model when the organisation's is missing.** Rejected: the
  run would claim one model and use another.
- **Rebuild attrition examples from current records.** Rejected: salary, employment
  type and attendance change; only the stored snapshot says what the record was.
