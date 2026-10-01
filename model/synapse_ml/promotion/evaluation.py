"""How the promotion-readiness model is evaluated.

Every estimate is out-of-fold: each reference employee is scored by a model that
never saw them. Beyond ranking (ROC-AUC, PR-AUC) the protocol measures what a
readiness score is used *for*:

- **calibration** — does "20 % of people like this were promoted" come true about
  20 % of the time (expected calibration error, reliability table, Brier score);
- **per history pattern** — how good the score is with one appraisal versus two,
  since that is what a live record varies in;
- **mixed history** — the live situation, where some people have a previous appraisal
  and some do not: pattern submodels versus filling the gap with the median;
- **tiers** — how many people each tier holds and how often they were promoted;
- **monotonicity** — a better rating or a larger improvement never lowers readiness.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd
from sklearn.base import clone
from sklearn.metrics import (
    average_precision_score,
    brier_score_loss,
    log_loss,
    roc_auc_score,
)
from sklearn.model_selection import StratifiedKFold

from ..appraisal.patterns import PatternRouter, patterns
from . import features
from .model import SEED, PromotionReadinessModel

FOLDS = 5


def splits(y, folds: int = FOLDS, seed: int = SEED):
    return list(StratifiedKFold(folds, shuffle=True, random_state=seed).split(np.zeros(len(y)), y))


def expected_calibration_error(y, p, bins: int = 20) -> float:
    """Mean |observed − predicted| over equal-count bins of the prediction, weighted by
    each bin's share of rows."""
    y, p = np.asarray(y), np.asarray(p)
    order = np.argsort(p, kind="stable")
    error = 0.0
    for chunk in np.array_split(order, bins):
        if len(chunk):
            error += abs(y[chunk].mean() - p[chunk].mean()) * len(chunk) / len(p)
    return float(error)


def reliability_table(y, p, bins: int = 10) -> pd.DataFrame:
    y, p = np.asarray(y), np.asarray(p)
    order = np.argsort(p, kind="stable")
    rows = [
        {"predicted": p[c].mean(), "observed": y[c].mean(), "n": len(c)} for c in np.array_split(order, bins) if len(c)
    ]
    return pd.DataFrame(rows)


def summarise(y, p) -> dict[str, float]:
    return {
        "roc_auc": float(roc_auc_score(y, p)),
        "pr_auc": float(average_precision_score(y, p)),
        "brier": float(brier_score_loss(y, p)),
        "log_loss": float(log_loss(y, np.clip(p, 1e-9, 1 - 1e-9))),
        "ece": expected_calibration_error(y, p),
    }


def oof(estimator, X: pd.DataFrame, y: pd.Series, folds=None) -> np.ndarray:
    """Out-of-fold probabilities of ``estimator`` on the columns of ``X``."""
    folds = folds or splits(y)
    out = np.zeros(len(y))
    for train, test in folds:
        fitted = clone(estimator).fit(X.iloc[train], y.iloc[train])
        out[test] = fitted.predict_proba(X.iloc[test])[:, 1]
    return out


def compare(candidates: dict[str, Any], X: pd.DataFrame, y: pd.Series) -> pd.DataFrame:
    """Every candidate, with full history, on identical folds."""
    folds = splits(y)
    rows = {name: summarise(y, oof(est, X, y, folds)) for name, est in candidates.items()}
    return pd.DataFrame(rows).T


def by_pattern(estimator, X: pd.DataFrame, y: pd.Series) -> pd.DataFrame:
    """How good the score is with each history a live record can have."""
    folds = splits(y)
    rows = {}
    for p in patterns(features.OPTIONAL):
        cols = [*features.REQUIRED, *p]
        rows[" + ".join(cols)] = {"basis": features.BASIS[p], **summarise(y, oof(estimator, X[cols], y, folds))}
    return pd.DataFrame(rows).T


def mixed_history(estimator, X: pd.DataFrame, y: pd.Series, share_without_previous: float = 0.5,
                  seed: int = SEED) -> pd.DataFrame:
    """The live situation: a share of people have no previous appraisal, so no change.

    Compares scoring each person with the submodel for what they have against one
    full-history model given the reference median for the missing change — the gap
    the median fills is a change nobody observed."""
    rng = np.random.default_rng(seed)
    without = rng.random(len(y)) < share_without_previous
    folds = splits(y)
    routed = np.zeros(len(y))
    imputed = np.zeros(len(y))
    for train, test in folds:
        router = PatternRouter(estimator, features.REQUIRED, features.OPTIONAL).fit(X.iloc[train], y.iloc[train])
        rows = X.iloc[test].to_dict("records")
        for i, row in enumerate(rows):
            if without[test[i]]:
                row.pop("rating_change")
        routed[test] = [p[1] for p in router.apply("predict_proba", rows)]

        full_cols = [*features.REQUIRED, *features.OPTIONAL]
        full = router.submodel(tuple(features.OPTIONAL))
        filled = X.iloc[test][full_cols].copy()
        filled.loc[without[test], "rating_change"] = float(X.iloc[train]["rating_change"].median())
        imputed[test] = full.predict_proba(filled)[:, 1]

    rows = {}
    for name, p in (("pattern submodels (served)", routed), ("median-filled change", imputed)):
        rows[name] = {
            **summarise(y, p),
            "ece_without_previous": expected_calibration_error(y[without], p[without]),
            "ece_with_previous": expected_calibration_error(y[~without], p[~without]),
        }
    return pd.DataFrame(rows).T


def tier_table(model: PromotionReadinessModel, X: pd.DataFrame, y: pd.Series, probabilities) -> pd.DataFrame:
    tiers = pd.Series([model.tier(p) for p in probabilities], index=X.index)
    table = pd.DataFrame(
        {
            "share": tiers.value_counts(normalize=True),
            "promoted_rate": y.groupby(tiers).mean(),
            "n": tiers.value_counts(),
        }
    )
    return table.reindex(["high", "medium", "low"])


def monotonicity(model: PromotionReadinessModel, steps: int = 61) -> dict[str, Any]:
    """Sweep each input over its range with the others fixed at several settings and
    count any step where readiness falls. The contract: a better latest rating and a
    larger improvement both raise readiness, whatever the other input."""
    expected = {"rating_latest": +1, "rating_change": +1}
    specs = {spec.name: spec for spec in features.INPUTS}
    grid = {name: np.linspace(spec.low, spec.high, steps) for name, spec in specs.items()}
    anchors = [{"rating_latest": r, "rating_change": c} for r in (45, 60, 70, 80, 95) for c in (-15, -5, 2, 8, 15)]
    violations = {name: 0 for name in expected}
    checks = 0
    for anchor in anchors:
        for p in patterns(features.OPTIONAL):
            present = {*features.REQUIRED, *p}
            for name, sign in expected.items():
                if name not in present:
                    continue
                rows = [{k: v for k, v in {**anchor, name: value}.items() if k in present} for value in grid[name]]
                probs = np.array(model.probabilities(rows))
                violations[name] += int(np.sum(sign * np.diff(probs) < -1e-12))
                checks += len(probs) - 1
    return {"checks": checks, "violations": violations}


def fairness(model: PromotionReadinessModel, df: pd.DataFrame, X: pd.DataFrame, probabilities) -> pd.DataFrame:
    """Readiness across attributes the model never sees. The model cannot use them, but
    its inputs could carry them; this shows whether they do."""
    scores = pd.Series([model.percentile.at(p) for p in probabilities], index=X.index)
    ready = pd.Series([model.tier(p) == "high" for p in probabilities], index=X.index)
    frames = []
    for column in ("gender", "education_level", "marital_status", "city_tier"):
        if column in df.columns:
            g = df[column]
            frames.append(
                pd.DataFrame(
                    {
                        "mean_score": scores.groupby(g).mean(),
                        "share_ready": ready.groupby(g).mean(),
                        "actually_promoted": df["promoted"].groupby(g).mean(),
                    }
                ).assign(attribute=column)
            )
    return pd.concat(frames).set_index("attribute", append=True).swaplevel()
