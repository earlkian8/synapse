"""How the performance-forecast model is evaluated.

The point forecasts are compared on identical cross-validation folds. The served
model is then judged on a test set it never touched — neither for fitting nor for
measuring its errors — against the three promises it makes:

- **the interval** holds four in five next ratings, overall *and* in every region of
  the scale (a guarantee that only holds on average is not one a reader can use);
- **the band** it names is right as often as its ``confidence`` says;
- **monotone** — a better latest appraisal never forecasts a worse next one.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd
from sklearn.base import clone
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import KFold

from . import features
from .model import SEED, PerformanceForecastModel

FOLDS = 5


def summarise(y, pred) -> dict[str, float]:
    return {
        "mae": float(mean_absolute_error(y, pred)),
        "rmse": float(np.sqrt(mean_squared_error(y, pred))),
        "r2": float(r2_score(y, pred)),
    }


def compare(candidates: dict[str, Any], X: pd.DataFrame, y: pd.Series, seed: int = SEED) -> pd.DataFrame:
    """Every point forecaster on identical folds."""
    folds = list(KFold(FOLDS, shuffle=True, random_state=seed).split(X))
    rows = {}
    for name, estimator in candidates.items():
        pred = np.zeros(len(y))
        for train, test in folds:
            pred[test] = clone(estimator).fit(X.iloc[train], y.iloc[train]).predict(X.iloc[test])
        rows[name] = summarise(y, pred)
    return pd.DataFrame(rows).T


def held_out(model: PerformanceForecastModel, X: pd.DataFrame, y: pd.Series) -> pd.DataFrame:
    """Every test row's forecast next to what actually happened."""
    made = model.forecasts(X["rating_latest"].tolist())
    frame = pd.DataFrame(made, index=X.index)
    frame["latest"] = X["rating_latest"].values
    frame["actual"] = y.values
    frame["inside"] = (frame["actual"] >= frame["low"]) & (frame["actual"] <= frame["high"])
    frame["actual_band"] = [features.band_of(v) for v in frame["actual"]]
    frame["band_right"] = frame["actual_band"] == frame["band"]
    frame["width"] = frame["high"] - frame["low"]
    return frame


def coverage(frame: pd.DataFrame) -> pd.DataFrame:
    """Interval coverage and width, overall and per region of the latest rating."""
    regions = pd.cut(frame["latest"], [0, 50, 60, 70, 80, 90, 101], right=False)
    table = frame.groupby(regions, observed=True).agg(
        coverage=("inside", "mean"), mean_width=("width", "mean"), n=("inside", "size")
    )
    table.loc["all"] = [frame["inside"].mean(), frame["width"].mean(), len(frame)]
    return table


def confidence_reliability(frame: pd.DataFrame, bins: int = 8) -> pd.DataFrame:
    """Does a forecast stated at 70 % confidence name the right band 70 % of the time?"""
    order = np.argsort(frame["confidence"].values, kind="stable")
    rows = []
    for chunk in np.array_split(order, bins):
        part = frame.iloc[chunk]
        rows.append({"stated": part["confidence"].mean(), "observed": part["band_right"].mean(), "n": len(part)})
    return pd.DataFrame(rows)


def monotonicity(model: PerformanceForecastModel, steps: int = 601) -> dict[str, Any]:
    spec = features.INPUTS[0]
    grid = np.linspace(spec.low, spec.high, steps)
    made = model.forecasts(grid.tolist())
    point = np.array([f["point"] for f in made])
    low = np.array([f["low"] for f in made])
    high = np.array([f["high"] for f in made])
    return {
        "checks": steps - 1,
        "point_decreases": int(np.sum(np.diff(point) < -1e-9)),
        "largest_step_down": float(max(0.0, -np.diff(point).min())),
        "interval_never_excludes_point": bool(np.all((low <= point) & (point <= high))),
    }
