# Model graduation

How each predictive surface — [Promotion Readiness](./promotion-readiness.md),
[Performance Forecast](./performance-forecast.md) and [Attrition Risk](./attrition-risk.md)
— moves from a **general model**, learned from other workplaces' data, to **its own
model**, trained on this organisation's records. See
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md).

## What a reader sees

A **Model graduation** panel sits beneath each surface's header. Collapsed, it says in
one sentence whose data is behind the scores ("These readiness scores come from a
general model, built on a general workforce dataset of employees at other
organisations…"), with a pill (*Using the general model* / *Using your own model*) and
one dot per requirement. It opens by itself when there is a decision waiting (the gate
is open, or a trained model awaits a switch). Expanded, top to bottom:

1. **What is model graduation?** — four short points: every score comes from a model;
   graduating means learning from your own records; it only replaces the general model
   if it proves better; nothing changes until someone switches.
2. **Where this page is** — *General model* → *Collecting your history* → *Your own
   model*, with "You are here". The step into the last carries a lock until graduation.
3. **What's needed** — the requirement checklist, grouped into *Enough history*,
   *History that can be trusted* and *The system*. Each row: a status icon and label
   (never colour alone), progress ("14 of 100") and a meter, **Still needed** in words
   ("86 more promotions"), **What you can do** (or *This fills on its own* for counts
   that follow from others), a note on records that exist but can't count yet, and
   **Why this number?** — a dialog with the threshold's justification and exactly what
   is counted. The actionable requirement furthest from met is marked *Furthest to go*
   and carries a projection at the organisation's recent pace.
4. **Your own model** — the train button (locked until every requirement is met), the
   latest result, the model in use, and switch / switch back.
5. **What the scores are based on** (collapsed) — every field a score draws on or could,
   with how many active employees' records carry it: *Used in every score*, *Recorded,
   but not used* (each says why), *Not recorded anywhere*.

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
who can view the page sees the panel. Training, switching and switching back are
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
- **Frontend** — `resources/js/features/model-graduation/`: `GraduationPanel`,
  `StageRail`, `RequirementChecklist`, `RequirementDialog`, `TrainingPanel`,
  `FieldCoverageTable`, `ScoredBy`; `use-graduation.ts`, `api.ts`, `routes.ts`.
- **Tables** — [model-graduation tables](../database/model-graduation-tables.md).
