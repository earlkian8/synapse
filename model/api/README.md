# Synapse ML Inference Service (FastAPI)

A thin, stateless HTTP layer that serves the trained scikit-learn pipelines in
`../artifacts/` to the Laravel app. Laravel calls it **server-side** (never the
browser) to score employees for **promotion readiness** and **attrition risk** — and,
in the same shape, performance forecasting.

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
Liveness plus which models are loaded and their headline metrics.

```json
{ "status": "ok", "service": "synapse-ml-inference",
  "models": { "promotion": { "kind": "classifier", "version": "LogisticRegression@…",
                             "feature_count": 40, "metrics": { "test_roc_auc": 0.94, … } }, … } }
```

### `POST /predict/{model_name}`
`model_name` ∈ `promotion | performance | attrition`. Send a batch of instances;
each carries a caller `ref` (echoed back) and whatever `features` are known —
**missing features are imputed by the pipeline**, so partial inputs are fine.

```jsonc
// request
{ "instances": [
    { "ref": "emp-1", "features": { "years_at_company": 6, "performance_score": 88,
                                    "manager_rating": 4.2, "certifications_count": 3 } }
]}

// response
{ "model": "promotion", "model_version": "LogisticRegression@…",
  "results": [
    { "ref": "emp-1", "probability": 0.99, "score": 99.2, "tier": "high",
      "factors": [ { "feature": "performance_score", "label": "Current performance",
                     "impact": 6.85, "direction": "up" }, … ] }
] }
```

- `score` is `probability × 100` for classifiers, or the predicted value for the
  regressor (`performance`).
- `tier` ∈ `low | medium | high` (cut at 0.33 / 0.66), classifiers only.
- `factors` explain each prediction. For the **linear promotion model** they are the
  per-employee logit contributions. For the **attrition forest** they are
  *what-if-typical* deltas: how much the probability would change if that one input
  were the training median / mode instead of the employee's value (an input that was
  not sent is imputed to exactly that value, so it can never appear as a reason).
  Null for the performance regressor. **Protected/demographic attributes
  (gender, age, marital status, education, city tier) are deliberately excluded**
  from explanations for fairness.

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
- `/health` lists only the models whose artifact exists. The Laravel attrition page
  treats "service up but no `attrition` model" as unavailable, so train the notebook
  before expecting the Run button to enable.
