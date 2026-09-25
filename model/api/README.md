# Synapse ML Inference Service (FastAPI)

A thin, stateless HTTP layer that serves the trained models in `../artifacts/` to the
Laravel app. Laravel calls it **server-side** (never the browser) to assess **promotion
readiness**, forecast **performance** and score **attrition risk**.

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

## Notes

- Pure-Python deps (FastAPI / uvicorn / pydantic) chosen to stay Python-3.14
  friendly; no native build step.
- The service holds no state and stores nothing — persistence of assessments
  lives in the Laravel `promotion_readiness_*`, `performance_forecast*` and
  `attrition_risk_*` tables.
- **Attrition inputs are raw ERP values** (`tenure_years`, `monthly_salary`,
  `years_since_promotion`, `ever_promoted`, `overtime_hours_90d`, `absences_90d`,
  `lates_90d`, `employment_type`). The pipeline bands them with the survey's own edges
  (`synapse_ml/attrition/features.py`, also written to
  `artifacts/attrition/feature_contract.json`), so callers never need to know them.
- `/health` lists only the models whose artifact exists. Each Laravel page treats
  "service up but its model missing" as unavailable, so train the notebook before
  expecting the Run button to enable.
