"""How the attrition model is evaluated.

The dataset is small (about 200 rows), so a single train/test split would swing on a handful
of people. Every estimate here is **repeated, grouped, stratified k-fold** out-of-fold
scoring: each row is scored by a model that never saw it, rows sharing an answer
pattern stay in the same fold (so a duplicate cannot leak its own label), and each
repeat reshuffles.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
from sklearn.base import clone
from sklearn.inspection import permutation_importance
from sklearn.metrics import average_precision_score, brier_score_loss, roc_auc_score
from sklearn.model_selection import StratifiedGroupKFold

from .model import SEED, fit

REPEATS = 10
FOLDS = 5


def _splits(X, y, groups, folds: int, seed: int):
    return StratifiedGroupKFold(n_splits=folds, shuffle=True, random_state=seed).split(X, y, groups)


def oof_predictions(pipeline, X: pd.DataFrame, y: pd.Series, groups, repeats: int = REPEATS,
                    folds: int = FOLDS, seed: int = SEED) -> np.ndarray:
    """Out-of-fold probabilities for each repeat, shape ``(repeats, len(y))``."""
    out = np.zeros((repeats, len(y)))
    for r in range(repeats):
        for train, test in _splits(X, y, groups, folds, seed + r):
            fitted = fit(clone(pipeline), X.iloc[train], y.iloc[train])
            out[r, test] = fitted.predict_proba(X.iloc[test])[:, 1]
    return out


def summarise(oof: np.ndarray, y: pd.Series) -> dict[str, float]:
    """Mean ROC-AUC (and its spread across repeats), PR-AUC and Brier score."""
    aucs = [roc_auc_score(y, p) for p in oof]
    return {
        "roc_auc": float(np.mean(aucs)),
        "roc_auc_sd": float(np.std(aucs)),
        "pr_auc": float(np.mean([average_precision_score(y, p) for p in oof])),
        "brier": float(np.mean([brier_score_loss(y, p) for p in oof])),
    }


def permutation_test(pipeline, X: pd.DataFrame, y: pd.Series, groups, observed: float,
                     n_permutations: int = 100, seed: int = SEED) -> tuple[np.ndarray, float]:
    """Is ``observed`` ROC-AUC distinguishable from chance?

    Shuffles the outcome, re-runs one repeat of the cross-validation, and returns the
    null distribution with the p-value (share of shuffles at least as good).
    """
    rng = np.random.default_rng(seed)
    null = np.zeros(n_permutations)
    for i in range(n_permutations):
        shuffled = pd.Series(rng.permutation(y.values), index=y.index)
        oof = oof_predictions(pipeline, X, shuffled, groups, repeats=1, seed=1000 + i)[0]
        null[i] = roc_auc_score(shuffled, oof)
    p_value = float((1 + (null >= observed).sum()) / (1 + n_permutations))
    return null, p_value


def cross_source(pipeline, X: pd.DataFrame, y: pd.Series, sources: pd.Series) -> dict[str, float]:
    """Train on each survey, test on the rest — does the model travel between populations?"""
    scores = {}
    for source in sorted(sources.unique()):
        train = (sources == source).values
        fitted = fit(clone(pipeline), X[train], y[train])
        scores[f"{source}->other"] = float(roc_auc_score(y[~train], fitted.predict_proba(X[~train])[:, 1]))
    return scores


def oof_permutation_importance(pipeline, X: pd.DataFrame, y: pd.Series, groups, repeats: int = 3,
                               folds: int = FOLDS, seed: int = SEED) -> pd.Series:
    """Mean drop in held-out ROC-AUC when each feature is shuffled."""
    importances = []
    for r in range(repeats):
        for train, test in _splits(X, y, groups, folds, seed + r):
            fitted = fit(clone(pipeline), X.iloc[train], y.iloc[train])
            result = permutation_importance(fitted, X.iloc[test], y.iloc[test], scoring="roc_auc",
                                            n_repeats=10, random_state=seed)
            importances.append(result.importances_mean)
    return pd.Series(np.mean(importances, axis=0), index=X.columns)


def nested_cv(build, grid: list[dict], X: pd.DataFrame, y: pd.Series, groups, repeats: int = 5,
              inner_repeats: int = 2, folds: int = FOLDS, seed: int = SEED) -> tuple[list[float], list[dict]]:
    """Does tuning flatter the estimate? Out-of-fold ROC-AUC per repeat when the
    settings are chosen inside each training fold, never seeing the rows they are
    scored on. ``build(**settings)`` makes a pipeline; ``grid`` lists the settings tried.
    Returns the per-repeat scores and the settings each outer fold chose."""
    groups = np.asarray(groups)
    scores, chosen = [], []
    for r in range(repeats):
        oof = np.zeros(len(y))
        for train, test in _splits(X, y, groups, folds, seed + 100 + r):
            X_tr, y_tr, g_tr = X.iloc[train], y.iloc[train], groups[train]
            inner = [np.mean([roc_auc_score(y_tr, p) for p in
                              oof_predictions(build(**settings), X_tr, y_tr, g_tr, inner_repeats, folds, seed + 200 + r)])
                     for settings in grid]
            best = grid[int(np.argmax(inner))]
            chosen.append(best)
            oof[test] = fit(build(**best), X_tr, y_tr).predict_proba(X.iloc[test])[:, 1]
        scores.append(float(roc_auc_score(y, oof)))
    return scores, chosen
