# Model graduation

How each predictive surface — [Promotion Readiness](./promotion-readiness.md),
[Performance Forecast](./performance-forecast.md) and [Attrition Risk](./attrition-risk.md)
— moves from a **general model**, learned from other workplaces' data, to **its own
model**, trained on this organisation's records. See
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md).

## What a reader sees

**On the page**, a one-line **Model graduation** strip sits beneath each surface's
header. It carries a pill (*Using the general model* / *Using your own model*), one dash
per requirement with "3 of 5 requirements met", and one sentence: whose model is scoring
the page and, while the general model is, the single thing furthest from ready ("Furthest
to go: promotions on record — 86 more promotions."). When only a requirement that fills on
its own is left, such as the prediction service being down, it says "Still needed" and
names that one instead. The strip is tinted, and its button
turns primary, only when a decision is waiting (the gate is open, or a trained model
awaits a switch). The button opens the modal on the tab that decision needs, and the
requirement count opens it on *Requirements*.

**The modal** is the whole story, one question per tab. The header names the page and
repeats whose model is scoring, the requirement count and the examples on record. The footer is pinned and
holds **Train on our records**, so the action is in view on every tab. The button is
locked until every requirement is met, and secondary while a model that already passed
is waiting to be switched to.

1. **Overview**:
   - **Next step**: the one thing to do now, with a button to the tab that does it. That
     is reviewing a model that passed, training once the gate opens, or the requirement
     furthest to go, with its shortfall, what to do and the projection at the
     organisation's recent pace.
   - **Where this page is**: *General model* → *Collecting your history* → *Your own
     model*, with "You are here". The step into the last carries a lock until graduation.
   - **What is model graduation?**, in four short points: every score comes from a model;
     graduating means learning from your own records; it only replaces the general model
     if it proves better; nothing changes until someone switches.
2. **Requirements**: one table.
   - A header row per group (*Enough history*, *History that can be trusted*, *The
     system*), then a row per requirement.
   - Each row has a status icon and label (never colour alone), its one-line summary,
     progress ("14 of 100") with a meter, and **Still needed** in words ("86 more
     promotions").
   - The actionable requirement furthest from met is marked *Furthest to go*.
   - A row opens the requirement in place, with a way back: its standing, **What you can
     do** (or *This fills on its own* for counts that follow from others), when it might
     be met, **Why this number**, **What is counted**, and records that exist but can't
     count yet.
3. **Your own model**: the model in use (with *Switch back*), a model that passed its
   check (with *Switch to this model*), or the last check that failed and why. Each check
   is shown as a small table of your model, the general model and knowing nothing, with
   the findings in plain words. Below that is whether training is unlocked.
4. **Data used**: one table of every field a score draws on or could, with how many
   active employees' records carry it. It is grouped into *Used in every score*,
   *Recorded, but not used* (each says why) and *Not recorded anywhere*.

Switching either way asks for a confirmation first.

Every number is counted from the organisation's records each time the page loads.

## Stages

| Stage | Label | When |
|---|---|---|
| `provisional` | General model | Nothing the surface's own model would learn from is recorded yet (no completed appraisals; for attrition, no stored risk scores). |
| `collecting` | Collecting your history | That history has started; the general model still scores. |
| `graduated` | Your own model | A model trained on the organisation's records passed its check and someone switched to it. |

## The lifecycle

1. **Train** (`POST analytics/<surface>/graduation`) — only when every requirement is
   met. Laravel sends the surface's training set to the inference service
   (`POST /train/{model}`), which fits the surface's own model class on it and checks it
   out of fold against the general model and against knowing nothing, across 1,000
   resamples of the organisation's people. It must come out ahead in 90 % of them (and a
   forecast's ranges must hold 70–90 % of the ratings that followed).
   - passed → stored by the service, recorded as `ready` (superseding an older `ready`);
   - failed → recorded as `failed`, with each finding in plain words; nothing stored.
2. **Switch** (`POST analytics/<surface>/graduation/{localModel}/activate`, after a
   confirmation) — the `ready` model becomes `active` (the previous one `retired`).
   From the next run the assessor asks the service for that model, and the run records
   `local_model_id`; the run line reads "scored by your own model".
3. **Switch back** (`DELETE analytics/<surface>/graduation`, after a confirmation) — the
   active model is `retired`; the next run uses the general model. A retired model is
   kept on record (runs it scored still say so) but cannot be switched to again — train
   a new one.

All three routes need the surface's `analytics.<surface>.manage` permission; everyone
who can view the page sees the strip and the modal. Training, switching and switching back are
activity-logged under the surface's log name.

If the organisation's model is missing from the inference service, a run fails with a
message saying so and suggesting switching back — it is never scored by the general
model instead.

## Requirements per surface

| Surface | What its own model learns from | Requirements |
|---|---|---|
| Promotion Readiness | each completed appraisal, and whether a promotion followed before the next one (or within a year) | 100 promotions that followed an appraisal · 50 with two appraisals before them · 100 appraisals not followed by one · 80 % of appraisal pairs on an unchanged form · service ready |
| Performance Forecast | each completed appraisal, and the person's next one (consecutive cycles, ≤ 400 days apart) | 235 comparisons · 2 review cycles with the one before to compare · 80 % on an unchanged form · service ready |
| Attrition Risk | each stored risk score (a year apart per person), and whether the person resigned within the year | 100 resignations within a year of a score · 100 scores followed by a year of staying · 90 % of departures with a recorded type · service ready |

The thresholds: 100 of the rarer outcome to validate a prediction model (Collins,
Ogundimu & Altman, 2016); 234 + one per input to pin a forecast's residual spread within
10 % (Riley et al., 2019). The inference service enforces the same minimums.

## Demo history

The demo company is seeded with enough history to graduate all three surfaces
(`database/seeders/WorkforceHistorySeeder.php`, run by `DatabaseSeeder` right after
`PerformanceSeeder`). It simulates the company month by month from January 2019 to
today and writes everything through the real models:

- **~120 people at any time**, hiring to replace whoever leaves (voluntary turnover
  about a quarter a year, as in much of Philippine BPO and retail) — about 350 people
  in all, ~115 of them still here. The `OrganizationSeeder` team (and the mobile demo
  employee) are part of it: they stay, and their FY 2025 appraisal is kept, with the
  years before it written to lead up to it.
- **Appraisals FY 2019–FY 2025** every 31 December for everyone hired before October —
  full scorecards laid out by `EvaluationOpener::lines()` and scored by
  `PerformanceScorer`, on the framework each person is reviewed on.
- **Promotions** decided on each appraisal (level and improvement), effective in the
  spring after it, with a raise and often a new role in the department. Some of the
  early staff were promoted before appraisals were on record — the checklist names
  those as promotions that can't count.
- **Departures** through completed offboarding cases: mostly resignations, with
  terminations, contract ends and a retirement or two.
- **A risk assessment every 15 March and 15 September**, September 2019 to September
  2025 (13 runs), storing each person's record as it stood that day. The stored inputs are the record; the scores on these seeded runs are
  illustrative (the run's `model_version` and `note` say so), since seeding never calls
  the inference service.

The company's own patterns — what its models learn and the general ones miss:
promotion at about twice the general rate, on level and improvement; ratings that
**regress to the mean** (strong performers slip back, weak ones recover); and
resignations driven by **overtime** (burnout — which the survey behind the general model
found irrelevant), absences and lateness, and a long wait for promotion. The generator
is seeded, but the current team's hire dates come from the employee factory and
differ on every seed, so the exact counts vary a little between seeds — with every
requirement cleared by at least 30 % across twelve seeds (fewest resignations 130,
promotions 130, comparisons 503), and every surface's model passing its check on six
seeds out of six.

On a fresh seed every requirement is met, and training through the real inference
service passes on all three surfaces:

| Surface | Examples | Your model | General model | Knowing nothing |
|---|---|---|---|---|
| Promotion (prediction error) | 579 | 0.162 | 0.218 | 0.194 |
| Performance (average miss) | 560 | 4.1 pts | 5.0 pts | 4.6 pts (repeat last rating) — ranges hold 78 % |
| Attrition (tells leavers from stayers) | 834 | 69 % | 57 % | 50 % |

Each came out ahead in 100 % of the re-checks, against both.

To try it: `php artisan migrate:fresh --seed`, start the inference service, open any of
the three pages, expand **Model graduation**, **Train on our records**, then **Switch to
this model** and run the page again. Other seeders stay on the current team: attendance,
leave, onboarding and recruitment only use active employees, the offboarding board's
demo exits are seeded unless exits are already in flight, and the profile seeder's
invented promotion is never given to someone with an appraisal history.

## Where it lives

- **Laravel** — `App\Support\Ml\Graduation\`: `ModelGraduation` (check / train /
  activate / revert — the canonical operation), one `*Graduation` class per surface
  (training set, requirements, fields), `FieldCounts`, `Departure`, `Pace`,
  `Requirement`, `TrainingSet`. `App\Models\LocalModel`.
  `Analytics\ModelGraduationController`. `MlClient::train()` and `predict(…, $variant)`.
  Each surface's index controller passes the `graduation` prop.
- **Inference service** — `model/synapse_ml/local/` (`training.py`: examples, minimums,
  out-of-fold check, verdict and wording; `store.py`: per-organisation storage);
  `model/api`: `POST /train/{model}`, and `variant` on `POST /predict/{model}`.
- **Frontend** — `resources/js/features/model-graduation/`:
  - `GraduationPanel`: the strip on the page, and the switch confirmations.
  - `GraduationDialog`: the modal, its tabs and the footer's train button.
  - `graduation-summary`: the pill, the one-line and full headlines, and the dashes.
  - `RequirementsTable`, `RequirementDetail`, `TrainingPanel` (with the check as a
    table), `FieldCoverageTable`, `StageRail` and `ScoredBy`.
  - Plumbing: `use-graduation.ts`, `api.ts`, `routes.ts`.
- **Tables** — [model-graduation tables](../database/model-graduation-tables.md).
