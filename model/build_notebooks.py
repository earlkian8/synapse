"""
Generate the two Synapse HR-ERP "Predictive Workforce Analytics" notebooks with nbformat.

Run once (inside the venv) to (re)create:

    notebooks/02_performance_model.ipynb   — Gradient Boosting performance forecasting
    notebooks/03_promotion_model.ipynb     — Logistic Regression promotion-readiness assessment

Attrition Risk is not one of these — it's a frontend-only demo surface in the HR app with
no trained model. See docs/decisions/0030-attrition-risk-frontend-only.md.

Each notebook is built around the algorithm chosen for that task (see MODEL-JUSTIFICATION.md).
Keeping the notebooks under source control as generated artifacts means we can always rebuild
them from this single, reviewable definition. Each shares the `synapse_ml` logging/persistence
plumbing so every run is recorded under logs/ and artifacts/.
"""

from __future__ import annotations

from pathlib import Path

import nbformat as nbf
from nbformat.v4 import new_code_cell, new_markdown_cell, new_notebook

HERE = Path(__file__).resolve().parent
NB_DIR = HERE / "notebooks"
NB_DIR.mkdir(exist_ok=True)


def md(text: str):
    return new_markdown_cell(text.strip("\n"))


def code(text: str):
    return new_code_cell(text.strip("\n"))


# ======================================================================================
# Shared cell fragments
# ======================================================================================

SETUP_CELL = """
# --- environment & logged run -------------------------------------------------------
import sys
from pathlib import Path

# notebooks/ live one level below the model root where synapse_ml.py sits
HERE = Path.cwd()
MODEL_DIR = HERE if (HERE / "synapse_ml.py").exists() else HERE.parent
sys.path.insert(0, str(MODEL_DIR))

import warnings
warnings.filterwarnings("ignore", category=FutureWarning)

import numpy as np
import pandas as pd
import matplotlib.pyplot as plt
import seaborn as sns

sns.set_theme(style="whitegrid", context="notebook")
pd.set_option("display.max_columns", 80)

import synapse_ml as sm
run = sm.start_run("{model_name}")   # opens logs/{model_name}_<ts>.log + artifacts/{model_name}/
log = run.log
"""

PREPROCESS_IMPORTS = """
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.impute import SimpleImputer
from sklearn.preprocessing import StandardScaler, OneHotEncoder
"""

PREPROCESS_DEF = """
preprocess = ColumnTransformer([
    ("num", Pipeline([("impute", SimpleImputer(strategy="median")),
                      ("scale", StandardScaler())]), numeric_cols),
    ("cat", Pipeline([("impute", SimpleImputer(strategy="most_frequent")),
                      ("ohe", OneHotEncoder(handle_unknown="ignore", sparse_output=False))]),
     categorical_cols),
])
"""


# ======================================================================================
# 1) PERFORMANCE — Gradient Boosting regression
# ======================================================================================


def build_performance() -> nbf.NotebookNode:
    nb = new_notebook()
    cells = []

    cells.append(md("""
# 02 · Performance Forecasting — Gradient Boosting

**Goal** — forecast an employee's upcoming **performance score** (continuous 40–100) from
historical KPI completion (`kpi_achievement_percent`, `performance_last_year`,
`performance_two_years_ago`), attendance consistency (`attendance_rate`, `late_days`,
`avg_monthly_hours`) and training participation (`training_hours_last_year`,
`certifications_count`, `skill_assessment_score`). This gives HR an early read on likely
high/low performers, complementing the manual weighted-KPI scoring in the Performance module.

**Algorithm — Gradient Boosting** (`HistGradientBoostingRegressor`, scikit-learn's
histogram-based gradient boosting — the scalable variant for this ~100k-row table). Chosen
for its accuracy on tabular data with non-linear feature interactions. Rationale + citations
in `../MODEL-JUSTIFICATION.md`.

As confirmed, the performance model is built on the **promotion dataset**
(`employee_promotion_prediction.csv`) — its `performance_score` column is the richest
performance signal across the datasets. Logged to `logs/performance*.log`; artifacts under
`artifacts/performance/`.

> **Leakage note:** the `promoted` outcome is dropped (downstream of performance);
> historical performance columns are kept as legitimate predictors.
"""))

    cells.append(code(SETUP_CELL.format(model_name="performance")))

    cells.append(md("## 1 · Load data"))
    cells.append(code("""
df = run.load_csv("employee_promotion_prediction.csv")
print(df.shape)
df.head()
"""))
    cells.append(code("""
import io
_info = io.StringIO()
df.info(buf=_info)
log.info("dataframe info:\\n%s", _info.getvalue())
df.describe().T
"""))

    cells.append(md("## 2 · Target distribution & drivers"))
    cells.append(code("""
TARGET = "performance_score"
ID_COL = "employee_id"
DROP = [ID_COL, "promoted"]   # promoted is downstream of performance -> leakage

y = df[TARGET].astype(float)
log.info("target=%s · mean=%.2f std=%.2f min=%.2f max=%.2f",
         TARGET, y.mean(), y.std(), y.min(), y.max())

fig, ax = plt.subplots(figsize=(6, 4))
sns.histplot(y, bins=40, kde=True, ax=ax, color="#4c72b0")
ax.set_title(f"Distribution of {TARGET}")
run.save_fig(fig, "01_target_distribution"); plt.show()
"""))
    cells.append(code("""
num_df = df.drop(columns=DROP).select_dtypes("number")
corr = num_df.corr()[TARGET].drop(TARGET).sort_values()
log.info("numeric correlation with %s:\\n%s", TARGET, corr.to_string())

fig, ax = plt.subplots(figsize=(7, 8))
corr.plot(kind="barh", ax=ax, color=np.where(corr > 0, "#dd8452", "#4c72b0"))
ax.set_title(f"Correlation of numeric features with {TARGET}")
run.save_fig(fig, "02_feature_correlation"); plt.show()
"""))

    cells.append(md("## 3 · Preprocessing & split"))
    cells.append(code(PREPROCESS_IMPORTS + """
X = df.drop(columns=DROP + [TARGET])
numeric_cols, categorical_cols = sm.split_feature_types(df.drop(columns=DROP), target=TARGET)
log.info("numeric=%d · categorical=%d %s", len(numeric_cols), len(categorical_cols), categorical_cols)
""" + PREPROCESS_DEF + """
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
log.info("split · train=%s test=%s", X_train.shape, X_test.shape)
"""))

    cells.append(md("""
## 4 · Gradient Boosting model

`HistGradientBoostingRegressor` — additive trees fit on the gradient of the squared-error
loss. Read by 3-fold cross-validated R² (3 folds keep the 100k-row run quick), then fit on
all training data.
"""))
    cells.append(code("""
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.model_selection import KFold

cv = KFold(n_splits=3, shuffle=True, random_state=42)
model = Pipeline([
    ("prep", preprocess),
    ("reg", HistGradientBoostingRegressor(
        learning_rate=0.05, max_iter=600, max_leaf_nodes=31,
        l2_regularization=1.0, early_stopping=True, random_state=42)),
])

cv_r2 = cross_val_score(model, X_train, y_train, cv=cv, scoring="r2", n_jobs=-1)
log.info("CV R2 = %.4f (+/- %.4f)", cv_r2.mean(), cv_r2.std())
model.fit(X_train, y_train)
log.info("fitted GradientBoosting on %d rows", len(X_train))
"""))

    cells.append(md("## 5 · Evaluation"))
    cells.append(code("""
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score

pred = model.predict(X_test)
mae = mean_absolute_error(y_test, pred)
rmse = float(np.sqrt(mean_squared_error(y_test, pred)))
r2 = r2_score(y_test, pred)
log.info("test · MAE=%.3f RMSE=%.3f R2=%.4f", mae, rmse, r2)

fig, axes = plt.subplots(1, 2, figsize=(13, 5))
axes[0].scatter(y_test, pred, s=6, alpha=0.25, color="#4c72b0")
lims = [y_test.min(), y_test.max()]; axes[0].plot(lims, lims, "k--", lw=1)
axes[0].set_xlabel("actual"); axes[0].set_ylabel("predicted")
axes[0].set_title(f"Predicted vs actual · R2={r2:.3f}")
resid = y_test - pred
axes[1].scatter(pred, resid, s=6, alpha=0.25, color="#c44e52"); axes[1].axhline(0, color="k", lw=1)
axes[1].set_xlabel("predicted"); axes[1].set_ylabel("residual")
axes[1].set_title(f"Residuals · MAE={mae:.2f} RMSE={rmse:.2f}")
fig.tight_layout(); run.save_fig(fig, "03_pred_vs_actual_residuals"); plt.show()
"""))
    cells.append(code("""
# Permutation importance (model-agnostic) on a test subsample.
from sklearn.inspection import permutation_importance

sample = X_test.sample(min(4000, len(X_test)), random_state=42)
perm = permutation_importance(model, sample, y_test.loc[sample.index], scoring="r2",
                              n_repeats=5, random_state=42, n_jobs=-1)
imp = pd.Series(perm.importances_mean, index=X_test.columns).sort_values().tail(15)
log.info("top permutation importances:\\n%s", imp.sort_values(ascending=False).to_string())

fig, ax = plt.subplots(figsize=(7, 6))
imp.plot(kind="barh", ax=ax, color="#55a868")
ax.set_title("Permutation importance (R2 drop) · top 15")
run.save_fig(fig, "04_permutation_importance"); plt.show()
"""))

    cells.append(md("""
## 6 · Forecast roster

Forecast scores for the test set and flag the largest gaps vs. last year's score — the
employees whose trajectory is bending up or down.
"""))
    cells.append(code("""
scored = X_test.copy()
scored["forecast_score"] = pred.round(1)
scored["actual_score"] = y_test.values.round(1)
scored["delta_vs_last_year"] = (pred - X_test["performance_last_year"]).round(1)
log.info("biggest forecast drops vs last year:\\n%s",
         scored.nsmallest(5, "delta_vs_last_year")[
             ["performance_last_year", "forecast_score", "delta_vs_last_year"]].to_string())
run.checkpoint_df(scored.reset_index(names="row_id"), "forecast_roster")
scored[["performance_last_year", "forecast_score", "actual_score", "delta_vs_last_year"]].head()
"""))

    cells.append(md("## 7 · Persist model & metrics"))
    cells.append(code("""
run.save_model(model, "performance_model")
run.save_metrics({
    "algorithm": "HistGradientBoostingRegressor",
    "cv_r2": float(cv_r2.mean()),
    "test_mae": mae, "test_rmse": rmse, "test_r2": r2,
    "target_mean": float(y.mean()), "target_std": float(y.std()),
    "n_train": int(len(X_train)), "n_test": int(len(X_test)),
})
run.finish(summary=f"GradientBoosting: test R2={r2:.3f}, MAE={mae:.2f}, RMSE={rmse:.2f}")
"""))

    nb.cells = cells
    return nb


# ======================================================================================
# 2) PROMOTION — Logistic Regression classification (readiness scoring)
# ======================================================================================


def build_promotion() -> nbf.NotebookNode:
    nb = new_notebook()
    cells = []

    cells.append(md("""
# 03 · Promotion Readiness Assessment — Logistic Regression

**Goal** — produce an objective **promotion-readiness score** from performance history
(`performance_score`, `performance_last_year`, `manager_rating`), training completions
(`training_hours_last_year`, `mentoring_sessions`), certifications (`certifications_count`,
`skill_assessment_score`) and tenure (`years_at_company`, `years_in_current_role`,
`years_since_last_promotion`).

**Algorithm — Logistic Regression** (`LogisticRegression`). Chosen because promotion is a
high-stakes, must-be-defensible decision: logistic regression yields a calibrated probability
and transparent, auditable coefficients (odds ratios) HR can explain to employees — exactly
what an "objective readiness score" needs. Rationale + citations in
`../MODEL-JUSTIFICATION.md`. Binary classification on `employee_promotion_prediction.csv`
(~100k rows, ~10% promoted — strongly imbalanced).

> **Leakage note:** `salary_increase_percent` is dropped — a raise is granted *as part of* a
> promotion, so it would leak the outcome.

Logged to `logs/promotion*.log`; artifacts under `artifacts/promotion/`.
"""))

    cells.append(code(SETUP_CELL.format(model_name="promotion")))

    cells.append(md("## 1 · Load data"))
    cells.append(code("""
df = run.load_csv("employee_promotion_prediction.csv")
print(df.shape)
df.head()
"""))
    cells.append(code("""
import io
_info = io.StringIO()
df.info(buf=_info)
log.info("dataframe info:\\n%s", _info.getvalue())
df.describe().T
"""))

    cells.append(md("## 2 · Target & exploratory analysis"))
    cells.append(code("""
TARGET = "promoted"
ID_COL = "employee_id"
LEAKY = ["salary_increase_percent"]   # post-promotion artefact -> dropped
DROP = [ID_COL] + LEAKY

y = df[TARGET].astype(int)
rate = y.mean()
log.info("target=%s · positive rate=%.3f (%d of %d)", TARGET, rate, y.sum(), len(y))

fig, ax = plt.subplots(figsize=(4, 3))
y.value_counts().sort_index().plot(kind="bar", ax=ax, color=["#4c72b0", "#dd8452"])
ax.set_xticklabels(["Not promoted (0)", "Promoted (1)"], rotation=0)
ax.set_title(f"Promotion class balance · positive={rate:.1%}")
run.save_fig(fig, "01_class_balance"); plt.show()
"""))
    cells.append(code("""
fig, axes = plt.subplots(1, 2, figsize=(13, 4))
for ax, col in zip(axes, ["years_since_last_promotion", "department"]):
    rates = df.assign(_y=y).groupby(col)["_y"].mean().sort_values(ascending=False)
    log.info("promotion rate by %s:\\n%s", col, rates.to_string())
    rates.plot(kind="bar", ax=ax, color="#8172b3")
    ax.set_title(f"Promotion rate by {col}"); ax.set_ylabel("rate")
    ax.tick_params(axis="x", rotation=45)
fig.tight_layout(); run.save_fig(fig, "02_promotion_by_driver"); plt.show()
"""))
    cells.append(code("""
num_df = df.drop(columns=DROP + [TARGET]).select_dtypes("number").assign(_y=y)
corr = num_df.corr()["_y"].drop("_y").sort_values()
log.info("numeric correlation with promotion:\\n%s", corr.to_string())

fig, ax = plt.subplots(figsize=(7, 8))
corr.plot(kind="barh", ax=ax, color=np.where(corr > 0, "#dd8452", "#4c72b0"))
ax.set_title("Correlation of numeric features with promotion")
run.save_fig(fig, "03_numeric_correlation"); plt.show()
"""))

    cells.append(md("## 3 · Preprocessing & stratified split"))
    cells.append(code(PREPROCESS_IMPORTS + """
X = df.drop(columns=DROP + [TARGET])
numeric_cols, categorical_cols = sm.split_feature_types(df.drop(columns=DROP), target=TARGET)
log.info("numeric=%d · categorical=%d %s", len(numeric_cols), len(categorical_cols), categorical_cols)
""" + PREPROCESS_DEF + """
X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, stratify=y, random_state=42)
log.info("split · train=%s test=%s", X_train.shape, X_test.shape)
"""))

    cells.append(md("""
## 4 · Logistic Regression model

Scaling (done in preprocessing) matters for logistic regression's coefficients and
convergence. `class_weight="balanced"` handles the 10% positive rate. Read by 5-fold ROC-AUC.
"""))
    cells.append(code("""
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold

cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
model = Pipeline([
    ("prep", preprocess),
    ("clf", LogisticRegression(max_iter=2000, C=1.0, class_weight="balanced")),
])

cv_auc = cross_val_score(model, X_train, y_train, cv=cv, scoring="roc_auc", n_jobs=-1)
log.info("CV ROC-AUC = %.4f (+/- %.4f)", cv_auc.mean(), cv_auc.std())
model.fit(X_train, y_train)
log.info("fitted LogisticRegression on %d rows", len(X_train))
"""))

    cells.append(md("## 5 · Evaluation"))
    cells.append(code("""
from sklearn.metrics import (roc_auc_score, average_precision_score, classification_report,
                             confusion_matrix, ConfusionMatrixDisplay, RocCurveDisplay,
                             PrecisionRecallDisplay)

proba = model.predict_proba(X_test)[:, 1]
pred = (proba >= 0.5).astype(int)
roc = roc_auc_score(y_test, proba)
pr_auc = average_precision_score(y_test, proba)
report = classification_report(y_test, pred, target_names=["Not promoted", "Promoted"], digits=3)
log.info("test ROC-AUC=%.4f · PR-AUC=%.4f", roc, pr_auc)
log.info("classification report @0.5:\\n%s", report)
print(report)
"""))
    cells.append(code("""
fig, axes = plt.subplots(1, 3, figsize=(16, 4.5))
ConfusionMatrixDisplay(confusion_matrix(y_test, pred),
                       display_labels=["Not", "Promoted"]).plot(ax=axes[0], colorbar=False)
axes[0].set_title("Confusion matrix @ 0.5")
RocCurveDisplay.from_predictions(y_test, proba, ax=axes[1])
axes[1].set_title(f"ROC (AUC={roc:.3f})"); axes[1].plot([0, 1], [0, 1], "k--", lw=0.8)
PrecisionRecallDisplay.from_predictions(y_test, proba, ax=axes[2])
axes[2].set_title(f"Precision-Recall (AP={pr_auc:.3f})")
fig.tight_layout(); run.save_fig(fig, "04_evaluation_curves"); plt.show()
"""))
    cells.append(code("""
# Logistic-regression coefficients as odds ratios — the interpretability payoff.
ohe = model.named_steps["prep"].named_transformers_["cat"].named_steps["ohe"]
feat_names = numeric_cols + list(ohe.get_feature_names_out(categorical_cols))
coefs = pd.Series(model.named_steps["clf"].coef_[0], index=feat_names)
odds = np.exp(coefs).sort_values()
log.info("odds ratios (exp(coef)):\\n%s", odds.to_string())

top = pd.concat([odds.head(8), odds.tail(8)])
fig, ax = plt.subplots(figsize=(7, 7))
top.plot(kind="barh", ax=ax, color=np.where(top > 1, "#dd8452", "#4c72b0"))
ax.axvline(1.0, color="k", lw=0.8)
ax.set_title("Promotion odds ratios · strongest drivers (>1 ↑, <1 ↓)")
run.save_fig(fig, "05_odds_ratios"); plt.show()
"""))

    cells.append(md("""
## 6 · Decision-threshold tuning

The shortlist is ranked, so we tune the threshold to maximise F1 on the "Promoted" class
rather than defaulting to 0.5.
"""))
    cells.append(code("""
from sklearn.metrics import precision_recall_curve

prec, rec, thr = precision_recall_curve(y_test, proba)
f1s = 2 * prec * rec / (prec + rec + 1e-12)
best_idx = int(np.nanargmax(f1s[:-1]))
best_thr = float(thr[best_idx])
log.info("tuned threshold=%.3f · F1=%.3f · recall=%.3f · precision=%.3f",
         best_thr, f1s[best_idx], rec[best_idx], prec[best_idx])
print(classification_report(y_test, (proba >= best_thr).astype(int),
                            target_names=["Not promoted", "Promoted"], digits=3))

fig, ax = plt.subplots(figsize=(6, 4))
ax.plot(thr, prec[:-1], label="precision"); ax.plot(thr, rec[:-1], label="recall")
ax.plot(thr, f1s[:-1], label="F1")
ax.axvline(best_thr, color="k", ls="--", lw=0.8, label=f"best={best_thr:.2f}")
ax.set_xlabel("threshold"); ax.set_title("Threshold sweep (Promoted class)"); ax.legend()
run.save_fig(fig, "06_threshold_sweep"); plt.show()
"""))

    cells.append(md("""
## 7 · Readiness scoring

Turn probabilities into a 0–100 **readiness score** and Low / Medium / High tiers, then
checkpoint the scored roster.
"""))
    cells.append(code("""
scored = X_test.copy()
scored["readiness_score"] = (proba * 100).round(1)
scored["actual_promoted"] = y_test.values
scored["readiness_tier"] = pd.cut(proba, bins=[-0.01, 0.33, 0.66, 1.01],
                                  labels=["Low", "Medium", "High"])
log.info("readiness tier distribution:\\n%s", scored["readiness_tier"].value_counts().sort_index().to_string())
ready = scored.sort_values("readiness_score", ascending=False).head(10)
log.info("top-10 promotion-ready:\\n%s",
         ready[["readiness_score", "readiness_tier", "actual_promoted"]].to_string())
run.checkpoint_df(scored.reset_index(names="row_id"), "scored_readiness_roster")
ready[["readiness_score", "readiness_tier", "actual_promoted"]]
"""))

    cells.append(md("## 8 · Persist model & metrics"))
    cells.append(code("""
run.save_model(model, "promotion_model")
run.save_metrics({
    "algorithm": "LogisticRegression",
    "cv_roc_auc": float(cv_auc.mean()),
    "test_roc_auc": roc, "test_pr_auc": pr_auc,
    "tuned_threshold": best_thr,
    "tuned_recall_promoted": float(rec[best_idx]),
    "tuned_precision_promoted": float(prec[best_idx]),
    "positive_rate": float(rate),
    "dropped_leaky_features": LEAKY,
    "high_readiness_count": int((scored["readiness_tier"] == "High").sum()),
    "n_train": int(len(X_train)), "n_test": int(len(X_test)),
})
run.finish(summary=f"LogisticRegression: test ROC-AUC={roc:.3f}, PR-AUC={pr_auc:.3f}, tuned thr={best_thr:.2f}")
"""))

    nb.cells = cells
    return nb


# ======================================================================================
# Write all three
# ======================================================================================

BUILDERS = {
    "02_performance_model.ipynb": build_performance,
    "03_promotion_model.ipynb": build_promotion,
}

for filename, builder in BUILDERS.items():
    nb = builder()
    nb.metadata["kernelspec"] = {
        "display_name": "Python (synapse .venv)",
        "language": "python",
        "name": "python3",
    }
    nb.metadata["language_info"] = {"name": "python", "version": "3.14"}
    target = NB_DIR / filename
    nbf.write(nb, target)
    print(f"wrote {target} ({len(nb.cells)} cells)")
