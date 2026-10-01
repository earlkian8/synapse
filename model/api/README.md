# Synapse ML Inference Service (FastAPI)

A thin HTTP layer that serves the trained models in `../artifacts/` to the Laravel app.
Laravel calls it **server-side** (never the browser) to assess **promotion readiness**,
forecast **performance** and score **attrition risk** — and, once an organisation has
recorded enough of its own history, to **train** a surface's model on that history
(model graduation, ADR 0046).

Two kinds of artifact are served. **Promotion** and **performance** are `synapse_ml`
objects that own their whole contract — they read each record, decline one that lacks a
required input, and return the score, tier or band, interval and explanation themselves,
so the notebook, the tests and this service run one code path (ADR 0045).
**Attrition** is a fitted scikit-learn pipeline whose own imputers fill unsent inputs.

## Run

From the `model/` directory, inside the venv:

```bash
# simplest
.venv/Scripts/python.exe -m api               # http://127.0.0.1:8001

# or explicitly with uvicorn (e.g. for --reload during development)
.venv/Scripts/python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 8001
```

Environment knobs: `ML_HOST` (default `127.0.0.1`), `ML_PORT` (default `8001`),
`ML_RELOAD` (set to any value to auto-reload).

## Endpoints

### `GET /health`
Liveness plus which models are loaded and their headline metrics (for operators; the
Laravel pages read only whether their model is listed).

```json
{ "status": "ok", "service": "synapse-ml-inference",
  "models": { "promotion": { "kind": "classifier", "version": "LogisticRegression+QuadraticPlatt (pattern submodels)@…",
                             "feature_count": 2, "metrics": { "cv_roc_auc": 0.843, "cv_ece": 0.004, … } },
              "performance": { "kind": "regressor", "feature_count": 1,
                               "metrics": { "test_mae": 4.64, "test_interval_coverage": 0.795, … } }, … } }
```

### `POST /predict/{model_name}`
`model_name` ∈ `promotion | performance | attrition`. Send a batch of instances; each
carries a caller `ref` (echoed back) and its `features` — always a JSON **object**, `{}`
when there is nothing to send.

**Promotion** reads `rating_latest` (attainment 0–100 of the latest completed appraisal,
required) and `rating_change` (minus the previous one, optional).
**Performance** reads `rating_latest` (the latest completed appraisal *before the forecast
period*, required). Both contracts are written to `artifacts/<model>/feature_contract.json`.

```jsonc
// request
{ "instances": [
    { "ref": "7",  "features": { "rating_latest": 75.29, "rating_change": 6.2 } },
    { "ref": "8",  "features": { "rating_latest": 75.29 } },
    { "ref": "9",  "features": {} }
]}

// response
{ "model": "promotion", "model_version": "LogisticRegression+QuadraticPlatt (pattern submodels)@…",
  "results": [
    { "ref": "7", "status": "scored", "probability": 0.15306, "score": 76.8, "tier": "medium",
      "basis": "two_appraisals",
      "factors": [ { "feature": "rating_change", "label": "Change since previous appraisal",
                     "impact": 24.0, "direction": "up" }, … ], "warnings": [] },
    { "ref": "8", "status": "scored", "basis": "latest_appraisal", … },
    { "ref": "9", "status": "insufficient", "missing": ["rating_latest"],
      "score": null, "tier": null, … }
  ],
  "warnings": [] }
```

A performance result carries `score` (the forecast rating), `interval`
(`{low, high, coverage: 0.8}` — the range four in five next ratings land in), `band`
(`below | on_track | exceeds`, cut at 60 / 80) and `confidence` (the chance the next
rating lands in that band).

- `status` is `scored`, or `insufficient` when a required input is absent — nothing is
  guessed; `missing` names what was needed.
- `probability` (promotion) is calibrated: the share of reference employees with this
  record promoted within a year. `score` is its percentile among the reference
  workforce; `tier` is by lift over the 10 % base rate (high ≥ 2×, medium ≥ 1×).
- `factors` explain each prediction. **Promotion**: readiness points each recorded input
  moves the score against a typical value (effects under one point omitted).
  **Attrition**: *what-if-typical* probability deltas (an input that was not sent is
  imputed to exactly the typical value, so it can never appear as a reason). None for
  the performance forecast, which has one input. Protected/demographic attributes are
  never inputs and never reasons.
- `warnings` on a result note any value held at the model's trained range (e.g. a 30 %
  appraisal read as 40 %, the reference's floor). `warnings` on the response name inputs
  the model does not read — a sign the caller and the model disagree about the contract.
  Laravel logs them.
- `score` for attrition is `probability × 100`, tiered at 0.33 / 0.66.

Scoring with an organisation's own model: add a `variant` naming it. The response's
`model_version` then reads `local:<version>`. An unknown variant is a **404** — the
service never falls back to the reference model, which would misreport whose model
scored.

```jsonc
{ "instances": [ … ], "variant": { "tenant": "org-12", "version": "20260927133148-381514" } }
```

### `POST /train/{model_name}`
Fit `model_name`'s own model class on one organisation's labelled examples, check it
against the reference model on those same records, and store it only if it passes
(`synapse_ml/local/`). Laravel assembles the rows (`App\Support\Ml\Graduation`); each
names the employee it belongs to (`group`), so no person lands on both sides of a test.

```jsonc
// request
{ "tenant": "org-12",
  "rows": [
    { "group": "41", "features": { "rating_latest": 70.0 }, "outcome": 0 },
    { "group": "41", "features": { "rating_latest": 80.0, "rating_change": 10.0 }, "outcome": 1 },
    …
  ] }   // performance: outcome = the next rating, plus "cycle"; attrition: outcome = resigned

// response
{ "model": "promotion", "tenant": "org-12", "verdict": "passed",
  "version": "20260927133148-381514",
  "findings": [ "On your own records, your model’s predicted chances of promotion were closer to what happened than the general model’s (prediction error 0.159 against 0.250), and it came out ahead in 100% of re-checks." ],
  "comparison": { "metric": "brier", "better": "lower", "local": 0.1586, "reference": 0.2503, "baseline": 0.2243,
                  "wins_over_reference": 1.0, "wins_over_baseline": 1.0, "required_share": 0.9,
                  "examples": 960, "people": 320 },
  "counts": { "promoted": 326, "not_promoted": 634, "promoted_with_change": 227, "people": 320 } }
```

- **The check.** Every example is scored out of fold by a model fitted without that
  employee (5 grouped folds). The local model must beat the reference *and* knowing
  nothing (the organisation's promotion rate; repeating the last rating; a coin flip) on
  the surface's measure — Brier score, mean absolute error, ROC-AUC — in at least 90 %
  of 1,000 resamples of the organisation's people. A forecast's ranges must also hold
  70–90 % of the ratings that followed.
- `verdict: "failed"` stores nothing and says why in `findings`; they are written for
  the HR reader and shown as they are.
- **422** below the minimums (`synapse_ml/local/training.MINIMUMS`, mirrored by the
  Laravel checklist) or on malformed rows; **404** for an unknown model.
- Stored under `artifacts/local/<tenant>/<model>/<version>/` (`model.joblib`,
  `metrics.json`). Tenant keys (`^[a-z0-9][a-z0-9-]{0,63}$`) and versions are checked
  before touching the filesystem.

## Notes

- Pure-Python deps (FastAPI / uvicorn / pydantic) chosen to stay Python-3.14
  friendly; no native build step.
- The only thing the service stores is an organisation's own model, when one passes
  its check. Assessments, and the record of every training attempt, live in the
  Laravel `promotion_readiness_*`, `performance_forecast*`, `attrition_risk_*` and
  `local_models` tables. Back up `artifacts/local/` with the database: a run for an
  organisation that switched to its own model fails until that model is back.
- **Attrition inputs are raw ERP values** (`tenure_years`, `monthly_salary`,
  `years_since_promotion`, `ever_promoted`, `overtime_hours_90d`, `absences_90d`,
  `lates_90d`, `employment_type`). The pipeline bands them with the survey's own edges
  (`synapse_ml/attrition/features.py`, also written to
  `artifacts/attrition/feature_contract.json`), so callers never need to know them.
- `/health` lists only the models whose artifact exists. Each Laravel page treats
  "service up but its model missing" as unavailable, so train the notebook before
  expecting the Run button to enable.
