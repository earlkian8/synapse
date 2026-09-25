# Synapse HR-ERP — Machine Learning Models

The three **Predictive Workforce Analytics** models behind the HR app, and the FastAPI
service that serves them to Laravel. Each task uses a deliberately chosen algorithm
(rationale in the git-ignored `MODEL-JUSTIFICATION.md`):

| Notebook | Algorithm | Task | Target | Dataset (`data/raw/`) |
|---|---|---|---|---|
| `01_attrition_model` | **Random Forest** | Attrition-risk scoring | `left` | `attrition-survey-1.csv` + `-2.csv` |
| `02_performance_model` | **Gradient Boosting** | Performance forecasting (40–100) | `performance_score` | `employee_promotion_prediction.csv` |
| `03_promotion_model` | **Logistic Regression** | Promotion-readiness scoring | `promoted` | `employee_promotion_prediction.csv` |

## Layout

```
model/
├── api/                  FastAPI inference service — serves artifacts/ to Laravel
├── synapse_ml/           the library shared by notebooks, tests and the service
│   ├── paths.py          every directory, defined once
│   ├── runs.py           logged runs + artifact persistence
│   ├── tabular.py        generic tabular helpers
│   └── attrition/        the attrition model
│       ├── survey.py     load, merge and clean the surveys
│       ├── features.py   the feature contract and shared preprocessor
│       ├── model.py      the candidate models and the chosen one
│       └── evaluation.py repeated grouped CV, permutation test, importance
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

## Notes on the promotion dataset

- The **promotion** target is **imbalanced** (~10% positive). The classifier uses
  `class_weight="balanced"` and is evaluated with ROC-AUC / PR-AUC rather than accuracy,
  plus a decision-threshold sweep tuned for recall on the minority class.
- **Leakage guards:** the promotion model drops `salary_increase_percent` (a raise is part
  of a promotion). The performance model drops the `promoted` outcome and keeps historical
  performance as legitimate predictors.
- Gradient boosting uses scikit-learn's native `HistGradientBoostingRegressor`, so there is
  no xgboost/lightgbm dependency and the environment installs cleanly on Python 3.14.
