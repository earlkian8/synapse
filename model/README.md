# Synapse HR-ERP — Machine Learning Models

The three **Predictive Workforce Analytics** models behind the HR app, and the FastAPI
service that serves them to Laravel. Each task uses a deliberately chosen algorithm
(rationale in the git-ignored `MODEL-JUSTIFICATION.md`):

| Notebook | Algorithm | Task | Target | Dataset (`data/raw/`) |
|---|---|---|---|---|
| `01_attrition_model` | **Random Forest** | Attrition-risk scoring | `left` | `attrition-survey-1.csv` + `-2.csv` |
| `02_performance_model` | **Gradient Boosting** (monotone) + conformal intervals | Next-appraisal forecast (0–100, with range and band confidence) | next `performance_score` | `employee_promotion_prediction.csv` |
| `03_promotion_model` | **Logistic Regression** (calibrated, one submodel per history pattern) | Promotion-readiness scoring | `promoted` | `employee_promotion_prediction.csv` |

## Layout

```
model/
├── api/                  FastAPI inference service — serves artifacts/ to Laravel
├── synapse_ml/           the library shared by notebooks, tests and the service
│   ├── paths.py          every directory, defined once
│   ├── runs.py           logged runs + artifact persistence
│   ├── attrition/        the attrition model
│   │   ├── survey.py     load, merge and clean the surveys
│   │   ├── features.py   the feature contract and shared preprocessor
│   │   ├── model.py      the candidate models and the chosen one
│   │   └── evaluation.py repeated grouped CV, permutation test, importance
│   ├── appraisal/        what the two appraisal models share
│   │   ├── reference.py  load and validate the reference workforce
│   │   ├── inputs.py     read a live record against a contract (absent ≠ zero; clip + note)
│   │   └── patterns.py   one submodel per history pattern — never a guessed input
│   ├── promotion/        features.py · model.py · evaluation.py
│   ├── performance/      features.py · model.py · evaluation.py
│   └── local/            an organisation's own models: training.py (fit + check) · store.py
├── notebooks/            the narrative: explore, evaluate, persist (generated)
├── scripts/
│   └── build_notebooks.py  generates notebooks/ from one reviewable definition
├── tests/                pytest
├── data/                 raw/ · processed/ · archive/ — git-ignored, see data/README.md
├── artifacts/            trained pipelines, metrics, plots — generated, git-ignored
├── logs/                 one log per run + a rolling log per model — generated, git-ignored
├── pyproject.toml        tool configuration (pytest)
└── requirements.txt      pinned dependencies
```

Notebooks hold the story; logic they need lives in `synapse_ml/`, where it is tested.

## Setup

Built and tested on **CPython 3.14**. The virtual environment lives inside this folder.

```bash
# from model/
py -3.14 -m venv .venv
.venv/Scripts/python.exe -m pip install --upgrade pip
.venv/Scripts/python.exe -m pip install -r requirements.txt

# register the kernel so Jupyter can use this venv
.venv/Scripts/python.exe -m ipykernel install --user \
    --name synapse-venv --display-name "Python (synapse .venv)"
```

Place the datasets as described in `data/README.md`.

## Common tasks

All commands run from `model/`.

| Task | Command |
|---|---|
| Train a model (headless) | `.venv/Scripts/python.exe -m nbconvert --to notebook --execute --inplace notebooks/01_attrition_model.ipynb` |
| Explore interactively | `.venv/Scripts/jupyter.exe lab`, kernel **"Python (synapse .venv)"** |
| Serve the models | `.venv/Scripts/python.exe -m api` → http://127.0.0.1:8001 (see `api/README.md`) |
| Run the tests | `.venv/Scripts/python.exe -m pytest` |
| Rebuild the merged survey dataset | `.venv/Scripts/python.exe -m synapse_ml.attrition.survey` |
| Regenerate the notebooks | `.venv/Scripts/python.exe scripts/build_notebooks.py` |

The inference service serves whichever artifacts exist, so train a model before expecting
the service to list it. `tests/test_api_attrition.py` is skipped (with a pointer) until the
attrition notebook has been executed once.

## Runs, logs and artifacts

Every notebook opens a logged run first (`run = sm.start_run("<model>")`), which writes:

- `logs/<model>_<timestamp>.log` — an immutable record of that run;
- `logs/<model>.log` — a rolling history across runs;
- `artifacts/<model>/` — `<model>_model.joblib` (the fitted scikit-learn `Pipeline`,
  preprocessing included), `metrics.json`, and the evaluation plots. The attrition model
  also writes `feature_contract.json`: its inputs, units, band edges and tiers.

Library versions, data shapes, CV scores and artifact paths are all logged, so the full
story of a run survives a dead kernel or cleared outputs.

## The attrition model

Trained on two exports of one nine-question survey, merged and cleaned by
`synapse_ml/attrition/survey.py`: consenting respondents only; *resigned voluntarily*
(`left = 1`) vs *still employed* (`left = 0`), with retirement, contract end and dismissal
excluded; double-submissions dropped; every dropped row counted by reason.

Each answer is encoded in the unit the ERP records (years, pesos, hours, days, times) and
the pipeline bands it back with the survey's own edges, so a live employee's exact figures
score exactly like the survey answer they correspond to. With only 155 usable rows the
notebook reports repeated grouped cross-validation, a permutation test and a cross-survey
check rather than a single split. See
`../docs/decisions/0043-attrition-risk-trained-on-the-attrition-surveys.md`.

## The performance and promotion models

Both are trained on the reference workforce (`employee_promotion_prediction.csv`, 100,000
rows), **re-expressed in the few inputs the ERP actually records** — the previous models
read 40 columns, most of which the ERP never has, and scored live employees on imputed
guesses. See `../docs/decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md`.

- **Inputs.** Promotion: the latest completed appraisal's attainment (`overall_percent`)
  and its change on the previous one. Performance: the latest completed appraisal before
  the forecast period. Every other column was measured and is absent for a stated reason
  (notebook §2–3): most add nothing; department, overtime and time since promotion add a
  little and are excluded on purpose.
- **No guessed inputs.** A record with one appraisal is scored by a submodel fitted
  without the change (`appraisal/patterns.py`); a record with none is declined
  (`status: insufficient`). Filling the gap with a median invents an improvement — the
  strongest promotion signal — and costs calibration tenfold where the record is thin.
- **Reliable numbers.** Promotion probabilities are calibrated (ECE 0.004) and tiered by
  lift over the base rate; the forecast carries a conformal range (80 % held out, in every
  region of the scale) and a confidence that is the chance its band is right.
- **Tested guarantees.** Monotonicity (a better record never scores worse), determinism,
  batch/single parity, calibration, coverage, and the on-disk contract matching the model
  (`tests/test_promotion_model.py`, `tests/test_performance_model.py`,
  `tests/test_api_served.py`).
- **Leakage.** The forecast never reads the forecast period's own appraisal; the old
  model's same-appraisal inputs (manager rating, KPI attainment) are gone. The promotion
  model never saw `salary_increase_percent`.

## Models trained on an organisation's own records (model graduation)

Each surface's model can also be fitted on one organisation's history, sent by Laravel
through `POST /train/{model}` once its graduation checklist is met (ADR 0046).
`synapse_ml/local/training.py` fits the **same model class and contract** as the
reference model — so the result serves through the same pages — and offers it only if,
out of fold on that organisation's own people, it beats both the reference model and
knowing nothing in at least 90 % of 1,000 resamples of the people. Passing models are
stored per organisation under `artifacts/local/`; the ERP switches to one only when
someone decides to. Tests: `tests/test_local_training.py` (synthetic organisations with
and without a real pattern) and `tests/test_api_local.py`.
