"""
Generate the Synapse HR-ERP "Predictive Workforce Analytics" notebooks with nbformat.

    notebooks/01_attrition_model.ipynb     — Random Forest attrition risk (attrition surveys)
    notebooks/02_performance_model.ipynb   — Gradient Boosting forecast with conformal intervals
    notebooks/03_promotion_model.ipynb     — calibrated Logistic Regression promotion readiness

Run from model/:  python scripts/build_notebooks.py

The notebooks are generated from this single, reviewable definition so they stay
consistent. They hold the narrative — load, explore, evaluate, persist — while the logic
they call lives in the ``synapse_ml`` package, where it is tested. An unchanged notebook
is left untouched.
"""

from __future__ import annotations

import sys
from pathlib import Path

import nbformat as nbf
from nbformat.v4 import new_code_cell, new_markdown_cell, new_notebook

NB_DIR = Path(__file__).resolve().parent.parent / "notebooks"


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

# notebooks/ live one level below the model root, where the synapse_ml package sits
HERE = Path.cwd()
MODEL_DIR = HERE if (HERE / "synapse_ml").is_dir() else HERE.parent
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

# ======================================================================================
# 1) ATTRITION — Random Forest classification on the merged attrition surveys
# ======================================================================================


def build_attrition() -> nbf.NotebookNode:
    nb = new_notebook()
    cells = []

    cells.append(md("""
# 01 · Attrition Risk — Random Forest on the attrition surveys

**Goal** — a 0–100 **flight-risk score** for every active employee that HR can act on
*before* a resignation, served from data the Synapse ERP actually holds.

**Data** — two exports of one survey (`data/raw/attrition-survey-{1,2}.csv`) asking people
about an employer they worked for: employment type, tenure, monthly salary, time since their
last promotion, their overtime / absences / lateness over their last three months — and how
it ended. Every predictor has an ERP answer (employee record, promotions, 90 days of
attendance).

**Target** — `left = 1` for *I resigned voluntarily*, `0` for *I am still employed there*.
Retirement, contract end and dismissal are exits the employee did not choose, so they are
excluded rather than counted as attrition.

**Algorithm — Random Forest**, chosen by the comparison in §4: on this small, banded table
the tree ensembles beat every linear model, because the signal is in interactions and
non-monotone shapes (new hires *and* mid-tenure staff leave most), not straight lines.

**Where the logic lives** — this notebook is the narrative. Cleaning is
`synapse_ml.attrition.survey`, the feature contract `…features`, the models `…model`, the
evaluation protocol `…evaluation` — all tested under `tests/`.

**Honesty up front** — 155 usable rows is small and the signal is modest. The notebook says
exactly how modest (§4–§6) rather than presenting one lucky split.
"""))

    cells.append(code(SETUP_CELL.format(model_name="attrition")))

    cells.append(md("## 1 · Load, merge & clean both surveys"))
    cells.append(code("""
from synapse_ml.attrition import evaluation, features, model as models, survey

df, report = survey.load()
for line in report.lines():
    log.info("cleaning · %s", line)
merged = survey.write_merged(df)
log.info("wrote merged dataset · %s (%d rows)", merged.relative_to(MODEL_DIR), len(df))

X, y = df[features.FEATURES], df[survey.TARGET]
groups = df["pattern"]           # identical answer sets stay on one side of every split
rate = float(y.mean())
log.info("rows=%d · left=%d (%.1f%%) · stayed=%d", len(y), y.sum(), rate * 100, (1 - y).sum())
df.head()
"""))
    cells.append(code("""
by_source = pd.crosstab(df["source"], y.map({0: "stayed", 1: "left"}))
by_source["left rate"] = (by_source["left"] / by_source.sum(axis=1)).round(2)
log.info("outcome by survey:\\n%s", by_source.to_string())

fig, ax = plt.subplots(figsize=(5, 3.2))
by_source[["stayed", "left"]].plot(kind="bar", stacked=True, ax=ax, color=["#4c72b0", "#dd8452"], rot=0)
ax.set_title(f"Outcome by survey · overall left rate {rate:.0%}")
run.save_fig(fig, "01_class_balance"); plt.show()
by_source
"""))

    cells.append(md("""
## 2 · Who left — rate by answer band

Each feature in the bands the survey asked in. The two surveys reached different populations
(survey 2 is mostly long-tenured, better-paid public-sector staff, few of whom resigned), so
the pooled pattern is partly *who answered which survey* — §6 tests that directly.
"""))
    cells.append(code("""
fig, axes = plt.subplots(2, 4, figsize=(18, 7.5))
for ax, feature in zip(axes.flat, features.FEATURES):
    if feature in features.BAND_EDGES:
        band = X[feature].map(lambda v: features.band_of(feature, v) if pd.notna(v) else np.nan)
    else:
        band = X[feature]
    t = pd.DataFrame({"band": band, "left": y}).dropna().groupby("band")["left"].agg(["mean", "size"])
    labels = [features.BAND_LABELS[feature][int(b)] for b in t.index] if feature in features.BAND_LABELS else list(t.index)
    ax.bar(range(len(t)), t["mean"], color="#8172b3")
    ax.set_xticks(range(len(t)), [f"{l}\\n(n={n})" for l, n in zip(labels, t["size"])], fontsize=8)
    ax.axhline(rate, color="k", lw=0.8, ls="--")
    ax.set_title(features.FEATURE_LABELS[feature]); ax.set_ylim(0, 1)
    log.info("left rate by %s:\\n%s", feature, t.assign(label=labels).to_string())
fig.suptitle("Share who left, by answer band (dashed = overall rate)")
fig.tight_layout(); run.save_fig(fig, "02_attrition_by_driver"); plt.show()
"""))

    cells.append(md("""
## 3 · Evaluation protocol

With 155 rows a single train/test split swings on a handful of people, so every estimate is
**repeated, grouped, stratified 5-fold cross-validation** (10 repeats): each row is scored by a
model that never saw it, rows with an identical answer pattern share a fold, and each repeat
reshuffles (`synapse_ml.attrition.evaluation`). Every candidate shares one preprocessor —
median-impute, band each value with the survey's own edges, one-hot employment type — so the
comparison is between estimators.
"""))

    cells.append(md("## 4 · Which algorithm — a like-for-like comparison"))
    cells.append(code("""
comparison, oofs = {}, {}
for name, pipe in models.candidates().items():
    oofs[name] = evaluation.oof_predictions(pipe, X, y, groups)
    comparison[name] = evaluation.summarise(oofs[name], y)
    log.info("CV · %-20s ROC-AUC %.3f ± %.3f · PR-AUC %.3f · Brier %.3f", name,
             comparison[name]["roc_auc"], comparison[name]["roc_auc_sd"],
             comparison[name]["pr_auc"], comparison[name]["brier"])

table = pd.DataFrame(comparison).T.round(3)
fig, ax = plt.subplots(figsize=(7, 3.5))
ax.barh(table.index, table["roc_auc"], xerr=table["roc_auc_sd"], color="#55a868")
ax.axvline(0.5, color="k", lw=0.8, ls="--"); ax.set_xlim(0.4, 0.8)
ax.set_title("Repeated grouped CV · ROC-AUC (mean ± sd over repeats)")
run.save_fig(fig, "03_model_comparison"); plt.show()
table
"""))

    cells.append(md("""
## 5 · Is the signal real? — a permutation test

Shuffle the outcome, re-run the cross-validation, repeat 100 times. The p-value is the share of
shuffles that did at least as well as the real outcomes.
"""))
    cells.append(code("""
chosen = models.random_forest()
observed = comparison[models.CHOSEN]["roc_auc"]
null, p_value = evaluation.permutation_test(chosen, X, y, groups, observed)
log.info("permutation test · observed ROC-AUC %.3f · null mean %.3f sd %.3f · p=%.3f",
         observed, null.mean(), null.std(), p_value)

fig, ax = plt.subplots(figsize=(6, 3.5))
ax.hist(null, bins=20, color="#cccccc", label="shuffled outcomes")
ax.axvline(observed, color="#c44e52", lw=2, label=f"real outcomes ({observed:.3f})")
ax.set_title(f"Permutation test · p = {p_value:.3f}"); ax.legend()
run.save_fig(fig, "04_permutation_test"); plt.show()
"""))

    cells.append(md("""
## 6 · Does it travel? — train on one survey, test on the other

The hardest honest test: a model that only learned *which survey population someone came
from* falls apart here. Reported, not optimised — it is the known limit of this data and the
reason the model is labelled *provisional* until retrained on the organisation's own departures.
"""))
    cells.append(code("""
cross_source = evaluation.cross_source(chosen, X, y, df["source"])
for split, auc in cross_source.items():
    log.info("cross-source · %s · ROC-AUC %.3f", split, auc)
cross_source
"""))

    cells.append(md("## 7 · The chosen model, out of fold — curves, calibration and tiers"))
    cells.append(code("""
from sklearn.calibration import calibration_curve
from sklearn.metrics import PrecisionRecallDisplay, RocCurveDisplay, roc_auc_score

oof = oofs[models.CHOSEN].mean(axis=0)     # each row's 10 out-of-fold scores, averaged
fig, axes = plt.subplots(1, 3, figsize=(16, 4.5))
RocCurveDisplay.from_predictions(y, oof, ax=axes[0]); axes[0].plot([0, 1], [0, 1], "k--", lw=0.8)
axes[0].set_title(f"ROC (out of fold) · AUC {roc_auc_score(y, oof):.3f}")
PrecisionRecallDisplay.from_predictions(y, oof, ax=axes[1])
axes[1].axhline(rate, color="k", lw=0.8, ls="--"); axes[1].set_title("Precision-recall (dashed = base rate)")
frac, mean_pred = calibration_curve(y, oof, n_bins=5, strategy="quantile")
axes[2].plot(mean_pred, frac, "o-"); axes[2].plot([0, 1], [0, 1], "k--", lw=0.8)
axes[2].set_xlabel("score / 100"); axes[2].set_ylabel("share who left"); axes[2].set_title("Calibration (quantile bins)")
fig.tight_layout(); run.save_fig(fig, "05_evaluation_curves"); plt.show()

cuts = features.TIER_CUTS
tier = pd.cut(oof, bins=[-0.01, cuts["low_below"], cuts["medium_below"], 1.01], labels=["Stable", "At watch", "High risk"])
tier_table = pd.crosstab(tier, y.map({0: "stayed", 1: "left"}))
tier_table["left rate"] = (tier_table["left"] / tier_table.sum(axis=1)).round(2)
log.info("out-of-fold tiers vs outcome:\\n%s", tier_table.to_string())
tier_table
"""))

    cells.append(md("""
The score is **relative, not a calibrated probability**: the forest is trained with balanced
class weights and the survey over-samples leavers relative to any real workforce, so a score of
70 means *this profile looks much more like the people who left than those who stayed* — not
"70% chance". The tier table is what each tier has meant on unseen rows.
"""))

    cells.append(md("## 8 · What drives it — permutation importance, out of fold"))
    cells.append(code("""
imp = evaluation.oof_permutation_importance(chosen, X, y, groups).rename(features.FEATURE_LABELS).sort_values()
log.info("out-of-fold permutation importance (ROC-AUC drop):\\n%s", imp.sort_values(ascending=False).to_string())

fig, ax = plt.subplots(figsize=(7, 4))
imp.plot(kind="barh", ax=ax, color=np.where(imp > 0, "#55a868", "#bbbbbb"))
ax.set_title("Permutation importance on held-out folds (ROC-AUC drop)")
run.save_fig(fig, "06_feature_importance"); plt.show()
"""))

    cells.append(md("""
## 9 · Fit on everything, check ERP parity, persist

The served model is fit on all rows (the CV above estimated how a model built this way
performs). Then the load-bearing check: an employee's exact ERP figures must score exactly like
the survey answers they correspond to.
"""))
    cells.append(code("""
from sklearn.base import clone

final = clone(chosen).fit(X, y)

erp = pd.DataFrame([{"employment_type": "regular", "tenure_years": 4.2, "monthly_salary": 18_500,
                     "ever_promoted": 0, "years_since_promotion": 4.2,
                     "overtime_hours_90d": 7.25, "absences_90d": 3, "lates_90d": 2}])[features.FEATURES]
answered = pd.DataFrame([{"employment_type": "regular", "tenure_years": 4.0, "monthly_salary": 20_000,
                          "ever_promoted": 0, "years_since_promotion": 4.0,
                          "overtime_hours_90d": 5.0, "absences_90d": 4.0, "lates_90d": 1.5}])[features.FEATURES]
p_erp, p_answered = final.predict_proba(erp)[0, 1], final.predict_proba(answered)[0, 1]
assert abs(p_erp - p_answered) < 1e-12, (p_erp, p_answered)
log.info("ERP parity · exact ERP values score %.4f, the matching survey answers %.4f", p_erp, p_answered)
"""))
    cells.append(code("""
rf_cv = comparison[models.CHOSEN]
run.save_model(final, "attrition_model")
run.save_json(features.feature_contract(X), "feature_contract")
run.save_metrics({
    "algorithm": "RandomForestClassifier",
    "evaluation": f"{evaluation.REPEATS}x repeated grouped stratified {evaluation.FOLDS}-fold CV",
    "cv_roc_auc": rf_cv["roc_auc"], "cv_roc_auc_sd": rf_cv["roc_auc_sd"],
    "cv_pr_auc": rf_cv["pr_auc"], "cv_brier": rf_cv["brier"],
    "permutation_p_value": p_value,
    "cross_source_roc_auc": cross_source,
    "comparison": {name: round(scores["roc_auc"], 4) for name, scores in comparison.items()},
    "positive_rate": rate,
    "n_rows": int(len(y)), "n_left": int(y.sum()),
    "rows_by_source": df["source"].value_counts().to_dict(),
    "dropped": report.dropped,
})
run.finish(summary=f"RandomForest: CV ROC-AUC={rf_cv['roc_auc']:.3f}±{rf_cv['roc_auc_sd']:.3f}, "
                   f"PR-AUC={rf_cv['pr_auc']:.3f}, permutation p={p_value:.3f}")
"""))

    nb.cells = cells
    return nb


# ======================================================================================
# 2) PERFORMANCE — Gradient Boosting forecast with conformal intervals
# ======================================================================================


def build_performance() -> nbf.NotebookNode:
    nb = new_notebook()
    cells = []

    cells.append(md("""
# 02 · Performance Forecast — Gradient Boosting with conformal intervals

**Goal** — forecast each employee's **next appraisal** (attainment, 0–100) with a range that
can be trusted and a band (*Below / On track / Exceeds*) that says how likely it is to be right.

**Data** — the reference workforce (`data/raw/employee_promotion_prediction.csv`, 100,000 rows,
three years of ratings each). A forecast can only know what came *before* the period it
forecasts, so the reference is read one cycle shifted: last year's rating → this year's.

**What the ERP can supply, and so what the model reads** — one input, `rating_latest`: the
latest *completed* appraisal before the forecast period, as `overall_percent`. §2 shows why
nothing else earns a place. The previous model read 40 columns, most of which the ERP never
has, and three of them (manager rating, KPI attainment, the current score) from the very
appraisal it claimed to forecast.

**Algorithm — Gradient Boosting**, monotone in the latest rating, chosen in §3. Its errors,
measured on reference employees it never trained on, become each forecast's interval and
confidence (split conformal prediction, §4).

**Where the logic lives** — `synapse_ml.performance` (`features`, `model`, `evaluation`),
tested under `tests/`. This notebook is the narrative.
"""))

    cells.append(code(SETUP_CELL.format(model_name="performance")))

    cells.append(md("## 1 · Load the reference, in the contract's units"))
    cells.append(code("""
from sklearn.model_selection import train_test_split
from synapse_ml.appraisal import reference
from synapse_ml.performance import evaluation, features, model as models

df = reference.load()
X, y = features.reference_frame(df)
log.info("reference rows=%d · next rating mean=%.1f sd=%.1f", len(y), y.mean(), y.std())

# A test set that neither the forecaster nor its error measurements ever see.
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=models.SEED)
log.info("train=%d · test=%d", len(y_train), len(y_test))

fig, axes = plt.subplots(1, 2, figsize=(12, 4))
axes[0].hist(y, bins=60, color="#4c72b0"); axes[0].set_title("Next rating (reference)")
for name, below in features.BANDS[:-1]:
    axes[0].axvline(below, color="k", ls="--", lw=0.8)
sample = df.sample(4000, random_state=0)
axes[1].scatter(sample["performance_last_year"], sample["performance_score"], s=4, alpha=0.3)
axes[1].plot([40, 100], [40, 100], "k--", lw=0.8)
axes[1].set_xlabel("latest rating"); axes[1].set_ylabel("next rating"); axes[1].set_title("Latest → next")
fig.tight_layout(); run.save_fig(fig, "01_target_distribution"); plt.show()
"""))

    cells.append(md("""
## 2 · What earns a place as an input

Held-out R² of the same gradient-boosting learner on different inputs. Columns the ERP cannot
produce are shown only to measure what is given up by leaving them out.
"""))
    cells.append(code("""
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.model_selection import cross_val_score

learner = HistGradientBoostingRegressor(learning_rate=0.05, max_iter=300, random_state=models.SEED)
cands = {
    "latest rating (served)": ["performance_last_year"],
    "+ rating before it": ["performance_last_year", "performance_two_years_ago"],
    "+ tenure, time since promotion, certifications": ["performance_last_year", "performance_two_years_ago",
        "years_at_company", "years_since_last_promotion", "certifications_count"],
    "all 40 columns, minus same-appraisal ones": [c for c in df.select_dtypes("number").columns
        if c not in ("employee_id", "promoted", "performance_score", "manager_rating",
                     "kpi_achievement_percent", "peer_feedback_score", "bonus_last_year", "stock_options")],
}
inputs_table = pd.Series({name: cross_val_score(learner, df[cols], df["performance_score"], cv=3, scoring="r2").mean()
                          for name, cols in cands.items()}, name="cv_r2").round(4)
log.info("held-out R2 by input set:\\n%s", inputs_table.to_string())
inputs_table
"""))
    cells.append(md("""
The latest rating carries all of it. Nothing the ERP could add moves R² in the third decimal,
and even the columns it will never have add under 0.005 — so the served model reads one input
and cannot be misled by a missing one.
"""))

    cells.append(md("## 3 · Which point forecaster — a like-for-like comparison"))
    cells.append(code("""
comparison = evaluation.compare(models.candidates(), X_train, y_train)
log.info("5-fold CV on the training rows:\\n%s", comparison.round(4).to_string())

fig, ax = plt.subplots(figsize=(7, 3.2))
ax.barh(comparison.index, comparison["mae"], color="#55a868")
ax.set_xlabel("mean absolute error (points)"); ax.set_title("5-fold CV · lower is better")
run.save_fig(fig, "02_model_comparison"); plt.show()
comparison.round(4)
"""))
    cells.append(md("""
Gradient boosting and a straight line are within a hundredth of a point, and both beat
carrying the last rating forward. Boosting is served because, constrained to be monotone, it
bends where the scale does — near the floor and the ceiling — without ever forecasting a
worse next rating from a better latest one (§6).
"""))

    cells.append(md("""
## 4 · Fit, then measure the errors on rows it never saw

`PerformanceForecastModel.fit` trains the forecaster on three quarters of the training rows
and measures its errors on the last quarter, per region of the forecast (Mondrian conformal).
Those errors become every forecast's interval and confidence.
"""))
    cells.append(code("""
model = models.PerformanceForecastModel().fit(X_train, y_train)
log.info("fitted on %d rows · errors measured on %d · %d regions",
         model.n_fit, model.n_calibration, len(model.conformal.residuals))

examples = pd.DataFrame([{"latest": r, **model.forecast(r)} for r in (45, 55, 59, 61, 65, 70, 75, 79, 81, 90, 98)])
log.info("example forecasts:\\n%s", examples.round(2).to_string())
examples.round(2)
"""))

    cells.append(md("## 5 · Held out — does every promise hold?"))
    cells.append(code("""
held = evaluation.held_out(model, X_test, y_test)
test = evaluation.summarise(held["actual"], held["point"])
cov = evaluation.coverage(held)
band_accuracy = float(held["band_right"].mean())
log.info("test · MAE=%.2f RMSE=%.2f R2=%.4f · band accuracy=%.3f", test["mae"], test["rmse"], test["r2"], band_accuracy)
log.info("interval coverage (target %.0f%%) by latest rating:\\n%s", features.COVERAGE * 100, cov.round(3).to_string())
cov.round(3)
"""))
    cells.append(code("""
rel = evaluation.confidence_reliability(held)
log.info("stated confidence vs how often the band was right:\\n%s", rel.round(3).to_string())

fig, axes = plt.subplots(1, 3, figsize=(17, 4.5))
part = held.sample(3000, random_state=0).sort_values("latest")
axes[0].fill_between(part["latest"], part["low"], part["high"], color="#4c72b0", alpha=0.2, label="80% interval")
axes[0].plot(part["latest"], part["point"], color="#4c72b0", label="forecast")
axes[0].scatter(part["latest"], part["actual"], s=3, color="#333333", alpha=0.3, label="actual")
axes[0].set_xlabel("latest rating"); axes[0].set_title(f"Held-out forecasts · MAE {test['mae']:.2f}"); axes[0].legend()
axes[1].bar(range(len(cov) - 1), cov["coverage"].iloc[:-1], color="#55a868")
axes[1].set_xticks(range(len(cov) - 1), [str(i) for i in cov.index[:-1]], rotation=30)
axes[1].axhline(features.COVERAGE, color="k", ls="--", lw=0.8); axes[1].set_ylim(0.6, 1)
axes[1].set_title(f"Interval coverage by region · overall {cov.loc['all', 'coverage']:.3f}")
axes[2].plot(rel["stated"], rel["observed"], "o-"); axes[2].plot([0.4, 1], [0.4, 1], "k--", lw=0.8)
axes[2].set_xlabel("stated confidence"); axes[2].set_ylabel("band right"); axes[2].set_title("Is the confidence honest?")
fig.tight_layout(); run.save_fig(fig, "03_held_out_reliability"); plt.show()
"""))

    cells.append(md("## 6 · Monotone, everywhere"))
    cells.append(code("""
mono = evaluation.monotonicity(model)
log.info("monotonicity sweep: %s", mono)
assert mono["point_decreases"] == 0 and mono["interval_never_excludes_point"], mono
mono
"""))

    cells.append(md("""
## 7 · Persist

The served object is the fitted `PerformanceForecastModel` itself: the inference service calls
its `assess`, so the service and this notebook run the same code. A record without a completed
appraisal before the forecast period is *declined*, never forecast from a guess.
"""))
    cells.append(code("""
run.save_model(model, "performance_model")
run.save_json(model.contract(), "feature_contract")
run.save_metrics({
    "algorithm": model.algorithm,
    "inputs": model.features,
    "comparison_cv_mae": comparison["mae"].round(4).to_dict(),
    "test_mae": test["mae"], "test_rmse": test["rmse"], "test_r2": test["r2"],
    "test_interval_coverage": float(cov.loc["all", "coverage"]),
    "test_interval_coverage_by_region": {str(k): round(float(v), 4) for k, v in cov["coverage"].iloc[:-1].items()},
    "test_interval_mean_width": float(cov.loc["all", "mean_width"]),
    "interval_target_coverage": features.COVERAGE,
    "test_band_accuracy": band_accuracy,
    "confidence_reliability": rel.round(4).to_dict("records"),
    "monotonicity": mono,
    "n_fit": model.n_fit, "n_calibration": model.n_calibration, "n_test": int(len(y_test)),
})
run.finish(summary=f"Monotone GB + conformal: test MAE={test['mae']:.2f}, R2={test['r2']:.3f}, "
                   f"80% interval coverage={cov.loc['all', 'coverage']:.3f}, band accuracy={band_accuracy:.3f}")
"""))

    nb.cells = cells
    return nb


# ======================================================================================
# 3) PROMOTION — calibrated Logistic Regression, one submodel per history pattern
# ======================================================================================


def build_promotion() -> nbf.NotebookNode:
    nb = new_notebook()
    cells = []

    cells.append(md("""
# 03 · Promotion Readiness — calibrated Logistic Regression

**Goal** — a readiness score HR can defend: how the employee's appraisal record compares with
the records of people who were promoted, with the reasons in plain terms.

**Data** — the reference workforce (`data/raw/employee_promotion_prediction.csv`, 100,000 rows,
10 % promoted).

**What the model reads** — only what the ERP records: the latest completed appraisal
(`overall_percent`) and its **change** on the previous one.
Department, salary, employment type and every demographic attribute are left out on purpose
(§2). An employee with no completed appraisal is **declined**, not scored from a guess; one
with a single appraisal is scored by a submodel that never needed the missing change (§5).

**Algorithm — Logistic Regression**, with a monotone calibration step (§4). The log-odds of
promotion are close to linear in both inputs, so the model that is easiest to defend is also
the one the data supports.

**Where the logic lives** — `synapse_ml.promotion` (`features`, `model`, `evaluation`),
tested under `tests/`. This notebook is the narrative.
"""))

    cells.append(code(SETUP_CELL.format(model_name="promotion")))

    cells.append(md("## 1 · Load the reference, in the contract's units"))
    cells.append(code("""
from synapse_ml.appraisal import reference
from synapse_ml.promotion import evaluation, features, model as models

df = reference.load()
X, y = features.reference_frame(df)
rate = float(y.mean())
log.info("reference rows=%d · promoted=%d (%.1f%%)", len(y), y.sum(), rate * 100)
X.describe().T.round(2)
"""))

    cells.append(md("""
## 2 · What drives promotion in the reference — and what is left out

Promotion is driven by **improvement**. With no change on the previous appraisal almost nobody
is promoted at any level; with ten points or more, a quarter to a half are. Level matters too,
but less.
"""))
    cells.append(code("""
grid = pd.crosstab(pd.cut(X["rating_latest"], [39, 55, 65, 75, 85, 101]),
                   pd.cut(X["rating_change"], [-30, -5, 0, 5, 10, 30]), values=y, aggfunc="mean")
log.info("promotion rate by latest rating (rows) and change (columns):\\n%s", grid.round(3).to_string())

fig, axes = plt.subplots(1, 2, figsize=(14, 4.5))
sns.heatmap(grid, annot=True, fmt=".2f", cmap="Greens", ax=axes[0], cbar=False)
axes[0].set_title("Promotion rate · latest rating × change since previous")
by_dept = df.groupby("department")["promoted"].mean().sort_values()
by_dept.plot(kind="barh", ax=axes[1], color="#bbbbbb")
axes[1].set_title("Promotion rate by the reference's departments (not used)")
fig.tight_layout(); run.save_fig(fig, "01_promotion_drivers"); plt.show()
grid.round(3)
"""))
    cells.append(md("""
Department is the reference's largest single effect (Engineering promotes a third of its people,
Support one in fifty) — and it is left out. It is a fact about that dataset's departments, not
about readiness, and a tenant's departments ("Information Technology", "Sales & Marketing") do not
match its names, so the old model's scores hinged on how a department happened to be spelled.
Salary is in another currency and period; employment type has no effect and no part-time level.
"""))

    cells.append(md("## 3 · What each candidate input is worth, held out"))
    cells.append(code("""
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import cross_val_score
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

lr = make_pipeline(StandardScaler(), LogisticRegression(max_iter=5000))
extra = df.assign(rating_change=X["rating_change"])
input_sets = {
    "latest only": ["performance_score"],
    "latest + change (served)": ["performance_score", "rating_change"],
    "+ time since promotion": ["performance_score", "rating_change", "years_since_last_promotion"],
    "+ rating two cycles back": ["performance_score", "rating_change", "performance_two_years_ago"],
    "+ tenure, certifications": ["performance_score", "rating_change", "years_at_company", "certifications_count"],
    "+ attendance, lateness": ["performance_score", "rating_change", "attendance_rate", "late_days"],
    "+ overtime": ["performance_score", "rating_change", "overtime_hours"],
    "+ department": ["performance_score", "rating_change", *pd.get_dummies(df["department"], prefix="dept").columns[1:]],
}
extra = extra.join(pd.get_dummies(df["department"], prefix="dept").astype(float))
coef = make_pipeline(StandardScaler(), LogisticRegression(max_iter=5000)).fit(
    extra[input_sets["+ time since promotion"]], y)[-1].coef_[0]
log.info("with time since promotion, its coefficient per SD is %.3f (negative: the recently promoted are promoted more)",
         coef[-1])
inputs_table = pd.Series({name: cross_val_score(lr, extra[cols], y, cv=5, scoring="roc_auc").mean()
                          for name, cols in input_sets.items()}, name="cv_roc_auc").round(4)
log.info("held-out ROC-AUC by input set:\\n%s", inputs_table.to_string())
inputs_table
"""))

    cells.append(md("""
Change is worth more than everything else together: it lifts ROC-AUC from 0.69 to 0.84. The
rating two cycles back, tenure, certifications, attendance and lateness add a thousandth or
less, so they are not asked for.

Three inputs do add something and are left out on purpose:

- **Department** (+0.08) — the reference's largest effect after change, and a fact about *its*
  departments, which the tenant's do not match; §2.
- **Overtime** (+0.008) — approved overtime depends on role and policy (exempt staff record
  none), and a readiness score that rises with hours worked penalises part-time staff and
  anyone with caring responsibilities. That fairness cost is not worth 0.008.

Time since the last promotion adds a small but consistent 0.002 — and is still left out. It
earns that only because in the reference the *recently* promoted are promoted again more often
(its coefficient is negative), which runs against any real time-in-grade practice. Carried into
a tenant it would be an artefact of this dataset, surfacing as explanations like "promoted eight
months ago: +2 readiness" that HR would rightly distrust. 0.002 does not buy that.
"""))
    cells.append(md("## 4 · Which algorithm — a like-for-like comparison (both appraisals)"))
    cells.append(code("""
comparison = evaluation.compare(models.candidates(), X, y)
log.info("5-fold CV, identical folds:\\n%s", comparison.round(4).to_string())
comparison.round(4)
"""))
    cells.append(md("""
Plain logistic regression already ranks as well as gradient boosting. What it misses is a gentle
bend in calibration: a little low around the tier cut-offs, a little high at the very top. The
served estimator recalibrates its scores with a quadratic Platt step fitted out of fold and held
flat past its turning point — smooth, monotone, and about three times better calibrated (ECE)
with the same ranking. (Isotonic recalibration scored similarly on ECE but produced a staircase:
a few hundred distinct probabilities, ties across thousands of people, and exact 0 % / 100 %.)
"""))
    cells.append(code("""
chosen = models.CalibratedLogistic()
oof = evaluation.oof(chosen, X, y)
plain = evaluation.oof(models.candidates()["Logistic Regression"], X, y)
rel = evaluation.reliability_table(y, oof, bins=10)
rel_plain = evaluation.reliability_table(y, plain, bins=10)
log.info("reliability (calibrated):\\n%s", rel.round(4).to_string())

from sklearn.metrics import PrecisionRecallDisplay, RocCurveDisplay, roc_auc_score
fig, axes = plt.subplots(1, 3, figsize=(17, 4.5))
RocCurveDisplay.from_predictions(y, oof, ax=axes[0]); axes[0].plot([0, 1], [0, 1], "k--", lw=0.8)
axes[0].set_title(f"ROC (out of fold) · AUC {roc_auc_score(y, oof):.3f}")
PrecisionRecallDisplay.from_predictions(y, oof, ax=axes[1])
axes[1].axhline(rate, color="k", lw=0.8, ls="--"); axes[1].set_title("Precision-recall (dashed = base rate)")
axes[2].plot(rel_plain["predicted"], rel_plain["observed"], "o-", color="#bbbbbb", label="plain LR")
axes[2].plot(rel["predicted"], rel["observed"], "o-", color="#55a868", label="calibrated (served)")
axes[2].plot([0, 0.5], [0, 0.5], "k--", lw=0.8); axes[2].legend()
axes[2].set_xlabel("predicted"); axes[2].set_ylabel("promoted"); axes[2].set_title("Calibration (deciles)")
fig.tight_layout(); run.save_fig(fig, "02_evaluation_curves"); plt.show()
"""))

    cells.append(md("""
## 5 · Live records have gaps — one submodel per history pattern

A live employee may have one completed appraisal, not two. The previous model filled the
missing rating with the reference median, which *invents a change* — and change is the
strongest signal. Here half the rows lose their previous appraisal, and two ways of scoring
them are compared: the served pattern submodels, and one full model given the median change.
"""))
    cells.append(code("""
patterns_table = evaluation.by_pattern(chosen, X, y)
log.info("by history pattern:\\n%s", patterns_table.round(4).to_string())
mixed = evaluation.mixed_history(chosen, X, y)
log.info("mixed history (half without a previous appraisal):\\n%s", mixed.round(4).to_string())
mixed.round(4)
"""))
    cells.append(md("""
Filling the gap costs ranking and, worse, **calibration where the record is thin**: for people
without a previous appraisal the median-filled model is an order of magnitude less calibrated.
The pattern submodels are as calibrated for them as for everyone else — their score honestly
reflects that the change is unknown.
"""))

    cells.append(md("## 6 · Fit, tiers, and the checks the score must pass"))
    cells.append(code("""
model = models.PromotionReadinessModel().fit(X, y)
tiers = evaluation.tier_table(model, X, y, oof)
log.info("tiers (out-of-fold probabilities) · base rate %.3f · cut-offs %s:\\n%s",
         model.base_rate, {k: round(v, 3) for k, v in model.tiers.items()}, tiers.round(3).to_string())
for pattern, sub in model.router.submodels.items():
    log.info("odds ratios per SD · %-45s %s", " + ".join([*features.REQUIRED, *pattern]),
             {k: round(v, 3) for k, v in sub.odds_ratios().items()})
tiers.round(3)
"""))
    cells.append(code("""
mono = evaluation.monotonicity(model)
log.info("monotonicity sweep: %s", mono)
assert sum(mono["violations"].values()) == 0, mono

fair = evaluation.fairness(model, df, X, oof)
log.info("readiness across attributes the model never sees:\\n%s", fair.round(3).to_string())
fair.round(3)
"""))
    cells.append(code("""
examples = pd.DataFrame([
    {"rating_latest": 82.6, "rating_change": 2.4},
    {"rating_latest": 80.2},
    {"rating_latest": 80.2, "rating_change": 12.0},
    {"rating_latest": 95.0, "rating_change": 0.0},
    {"rating_latest": 65.0, "rating_change": -8.0},
    {},
])
assessed = model.assess(examples.to_dict("records"))
shown = examples.assign(
    status=[a["status"] for a in assessed], score=[a["score"] for a in assessed],
    probability=[a["probability"] for a in assessed], tier=[a["tier"] for a in assessed],
    basis=[a["basis"] for a in assessed],
    factors=[", ".join(f"{f['label']} {f['impact']:+.0f}" for f in (a["factors"] or [])) for a in assessed],
)
log.info("example assessments:\\n%s", shown.to_string())
shown
"""))

    cells.append(md("""
## 7 · Persist

The served object is the fitted `PromotionReadinessModel`: the inference service calls its
`assess`, so the service and this notebook run the same code.
"""))
    cells.append(code("""
cal = comparison.loc[models.CHOSEN]
run.save_model(model, "promotion_model")
run.save_json(model.contract(), "feature_contract")
run.save_metrics({
    "algorithm": model.algorithm,
    "inputs": model.features,
    "evaluation": f"{evaluation.FOLDS}-fold stratified CV, identical folds",
    "cv_roc_auc": float(cal["roc_auc"]), "cv_pr_auc": float(cal["pr_auc"]),
    "cv_brier": float(cal["brier"]), "cv_ece": float(cal["ece"]),
    "cv_ece_plain_logistic": float(comparison.loc["Logistic Regression", "ece"]),
    "cv_roc_auc_latest_only": float(patterns_table.loc["rating_latest", "roc_auc"]),
    "comparison": comparison.round(4).to_dict("index"),
    "mixed_history": mixed.round(4).to_dict("index"),
    "positive_rate": rate,
    "tiers": model.contract()["tiers"],
    "tier_outcomes": tiers.round(4).to_dict("index"),
    "monotonicity": mono,
    "n_rows": int(len(y)),
})
run.finish(summary=f"Calibrated LR (pattern submodels): CV ROC-AUC={cal['roc_auc']:.3f}, ECE={cal['ece']:.4f}, "
                   f"one-appraisal ROC-AUC={patterns_table.loc['rating_latest', 'roc_auc']:.3f}")
"""))

    nb.cells = cells
    return nb


# ======================================================================================
# Entry point
# ======================================================================================

BUILDERS = {
    "01_attrition_model.ipynb": build_attrition,
    "02_performance_model.ipynb": build_performance,
    "03_promotion_model.ipynb": build_promotion,
}

KERNEL = {"display_name": "Python (synapse .venv)", "language": "python", "name": "python3"}


def _sources(notebook: nbf.NotebookNode) -> list[tuple[str, str]]:
    return [(cell.cell_type, cell.source) for cell in notebook.cells]


def main() -> None:
    NB_DIR.mkdir(exist_ok=True)
    for filename, builder in BUILDERS.items():
        nb = builder()
        nb.metadata["kernelspec"] = KERNEL
        nb.metadata["language_info"] = {"name": "python", "version": "3.14"}
        target = NB_DIR / filename

        # nbformat gives every cell a fresh random id, so rewriting an unchanged
        # notebook would churn its diff for nothing. Only write what changed.
        if target.exists() and _sources(nbf.read(target, as_version=4)) == _sources(nb):
            print(f"unchanged {target}")
            continue

        nbf.write(nb, target)
        print(f"wrote {target} ({len(nb.cells)} cells)")


if __name__ == "__main__":
    sys.exit(main())
