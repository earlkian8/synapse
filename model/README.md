# Synapse HR-ERP — Machine Learning Models

Two **Predictive Workforce Analytics** models that complement the Synapse HR modules. Each
task uses a deliberately chosen algorithm (rationale in the git-ignored
`MODEL-JUSTIFICATION.md`):

| # | Notebook | Algorithm | Task | Target | Dataset |
|---|----------|-----------|------|--------|---------|
| 1 | `notebooks/02_performance_model.ipynb` | **Gradient Boosting** | Performance forecasting (40–100) | `performance_score` | `employee_promotion_prediction.csv` |
| 2 | `notebooks/03_promotion_model.ipynb` | **Logistic Regression** | Promotion-readiness scoring | `promoted` | `employee_promotion_prediction.csv` |

Each notebook runs the same disciplined flow: **EDA → preprocessing → model (CV) →
evaluation → (threshold tuning) → risk/readiness scoring → persistence.** Gradient boosting
uses scikit-learn's native `HistGradientBoostingRegressor`, so there is **no xgboost/lightgbm
dependency** and the environment installs cleanly on Python 3.14 without a build toolchain.

Attrition Risk is **not** one of these — it's a frontend-only demo surface in the HR app
that generates illustrative scores client-side; there is no attrition model, dataset or
notebook. See `../docs/decisions/0030-attrition-risk-frontend-only.md`.

## Setup

Built and tested on **CPython 3.14.4**. The virtual environment lives inside this folder.

```bash
# from model/
py -3.14 -m venv .venv
.venv/Scripts/python.exe -m pip install --upgrade pip
.venv/Scripts/python.exe -m pip install -r requirements.txt

# register the kernel so Jupyter can use this venv
.venv/Scripts/python.exe -m ipykernel install --user \
    --name synapse-venv --display-name "Python (synapse .venv)"
```

Then launch Jupyter and pick the **"Python (synapse .venv)"** kernel:

```bash
.venv/Scripts/jupyter.exe lab        # or: jupyter notebook
```

To re-run everything headlessly (no UI):

```bash
.venv/Scripts/python.exe -m nbconvert --to notebook --execute --inplace \
    notebooks/02_performance_model.ipynb
```

## Logging — nothing disappears

All three notebooks import `synapse_ml.py`, which opens a **logged run** at the top of each
notebook (`run = sm.start_run("<model>")`). Every run writes to:

- `logs/<model>_<timestamp>.log` — an immutable record of that single run.
- `logs/<model>.log` — a rolling history appended across every run.
- the notebook output — `INFO`+ lines mirrored inline.

Library versions, data shapes, null counts, correlations, CV scores, test metrics and the
saved-artifact paths are all logged. So even if a kernel dies or the cell outputs are
cleared, the full story of each run survives on disk.

## Artifacts

Each run writes to `artifacts/<model>/`:

- `<model>_model.joblib` — the fitted scikit-learn `Pipeline` (preprocessing + estimator),
  ready to `joblib.load(...)` and `.predict(...)` on raw rows.
- `metrics.json` — the headline metrics.
- `*.png` — EDA and evaluation plots (class balance, correlations, ROC/PR curves,
  confusion matrix, permutation importance, threshold sweep).

`logs/`, `artifacts/`, and `.venv/` are git-ignored (see `.gitignore`).

## Regenerating the notebooks

The notebooks are generated from a single reviewable definition so they stay consistent:

```bash
.venv/Scripts/python.exe build_notebooks.py
```

## Notes on the data

- The **promotion** target is **imbalanced** (~10% positive). The classifier uses
  `class_weight="balanced"` and is evaluated with ROC-AUC / PR-AUC rather than accuracy,
  plus a decision-threshold sweep tuned for recall on the minority class.
- **Leakage guards:** the promotion model drops `salary_increase_percent` (a raise is part
  of a promotion). The performance model drops the `promoted` outcome and keeps historical
  performance as legitimate predictors.
