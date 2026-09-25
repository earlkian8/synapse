"""The performance-forecast model, the candidates it was chosen against, and the object
the inference service serves.

**Point forecast.** Gradient boosting constrained to be monotone: a better latest
appraisal can never forecast a worse next one. On one input it learns the shape a
straight line cannot — ratings drift up about two points a year, except where the
scale's floor and ceiling (40 and 100 in the reference) hold them.

**Uncertainty.** A forecast without its error is not usable for a decision, so
every forecast carries the distribution of outcomes around it, taken from how wrong
the model actually was on reference employees it never trained on — *split
conformal* prediction, grouped by where the forecast lands (Mondrian conformal:
Vovk et al., 2005). Grouping matters because errors are not the same everywhere:
about ±6 points mid-scale, tighter near the floor and ceiling. From that
distribution come:

- ``interval`` — the range four in five next ratings fall in (``COVERAGE``), with a
  finite-sample guarantee on the reference;
- ``confidence`` — the chance the next rating lands in the band the forecast names.
  Near a band edge it is honestly about a coin flip.
"""

from __future__ import annotations

import math
from collections.abc import Mapping, Sequence
from typing import Any

import numpy as np
import pandas as pd
from sklearn.dummy import DummyRegressor
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.linear_model import LinearRegression
from sklearn.model_selection import train_test_split

from ..appraisal import inputs as contract_inputs
from . import features

SEED = 42
CHOSEN = "Gradient Boosting (monotone)"

SCALE = (0.0, 100.0)


def gradient_boosting(seed: int = SEED) -> HistGradientBoostingRegressor:
    """The served point forecaster: monotone in the latest rating, with leaves large
    enough that it cannot chase noise in a 100,000-row table."""
    return HistGradientBoostingRegressor(
        learning_rate=0.05,
        max_iter=400,
        max_leaf_nodes=15,
        min_samples_leaf=200,
        monotonic_cst=[1],
        early_stopping=True,
        random_state=seed,
    )


def candidates(seed: int = SEED) -> dict[str, Any]:
    """Every point forecaster compared in the notebook, on identical folds."""
    return {
        "Baseline (mean)": DummyRegressor(strategy="mean"),
        "Carry forward (last rating)": CarryForward(),
        "Linear Regression": LinearRegression(),
        "Gradient Boosting": HistGradientBoostingRegressor(learning_rate=0.05, max_iter=400, random_state=seed),
        CHOSEN: gradient_boosting(seed),
    }


class CarryForward:
    """The naive forecast: the next rating equals the latest one."""

    def fit(self, X, y):
        return self

    def predict(self, X) -> np.ndarray:
        return np.asarray(X, dtype=float)[:, 0]

    def get_params(self, deep: bool = True) -> dict:
        return {}

    def set_params(self, **params):
        return self


class MondrianConformal:
    """Forecast errors measured on held-out rows, kept per region of the forecast.

    ``bins`` regions are cut at quantiles of the held-out forecasts, so each holds
    about the same number of errors (thousands, on the reference)."""

    def __init__(self, bins: int = 20) -> None:
        self.bins = bins

    def fit(self, predicted, actual) -> MondrianConformal:
        predicted = np.asarray(predicted, dtype=float)
        actual = np.asarray(actual, dtype=float)
        cuts = np.quantile(predicted, np.linspace(0, 1, self.bins + 1)[1:-1])
        self.edges = np.unique(cuts)
        region = np.digitize(predicted, self.edges)
        self.residuals = [np.sort(actual[region == r] - predicted[region == r]) for r in range(len(self.edges) + 1)]
        if min(len(r) for r in self.residuals) < 100:
            raise ValueError("a conformal region holds fewer than 100 held-out errors")
        return self

    def errors(self, predicted: float) -> np.ndarray:
        return self.residuals[int(np.digitize([predicted], self.edges)[0])]

    def interval(self, predicted: float, coverage: float) -> tuple[float, float]:
        """The split-conformal interval from the region's errors: its ⌊(n+1)·α/2⌋-th and
        ⌈(n+1)·(1−α/2)⌉-th order statistics, which cover a new rating from the same
        population with probability at least ``coverage``."""
        r = self.errors(predicted)
        n = len(r)
        alpha = 1.0 - coverage
        low_rank = math.floor((n + 1) * alpha / 2)
        high_rank = math.ceil((n + 1) * (1 - alpha / 2))
        low = predicted + (r[low_rank - 1] if low_rank >= 1 else -np.inf)
        high = predicted + (r[high_rank - 1] if high_rank <= n else np.inf)
        return low, high

    def outcomes(self, predicted: float) -> np.ndarray:
        """Where the next rating could land: the forecast plus each measured error."""
        return np.clip(predicted + self.errors(predicted), *SCALE)


class PerformanceForecastModel:
    """What the inference service serves for ``/predict/performance``."""

    name = "performance"
    kind = "regressor"
    algorithm = "HistGradientBoostingRegressor (monotone) + Mondrian conformal"

    def __init__(self, seed: int = SEED, calibration_share: float = 0.25, bins: int = 20) -> None:
        self.seed = seed
        self.calibration_share = calibration_share
        self.bins = bins

    # ---- training --------------------------------------------------------------------

    def fit(self, X: pd.DataFrame, y: pd.Series) -> PerformanceForecastModel:
        """Fit the point forecaster on one part of ``X`` and measure its errors on the
        rest. The errors must come from rows it never saw, or the intervals would be
        too narrow."""
        X_fit, X_cal, y_fit, y_cal = train_test_split(
            X[features.REQUIRED], y, test_size=self.calibration_share, random_state=self.seed
        )
        self.point = gradient_boosting(self.seed).fit(X_fit, y_fit)
        self.conformal = MondrianConformal(self.bins).fit(self._predict(X_cal), y_cal)
        self.n_fit, self.n_calibration = len(X_fit), len(X_cal)
        return self

    def _predict(self, X) -> np.ndarray:
        return np.clip(self.point.predict(X), *SCALE)

    # ---- serving ---------------------------------------------------------------------

    @property
    def features(self) -> list[str]:
        return [spec.name for spec in features.INPUTS]

    def forecasts(self, ratings: Sequence[float]) -> list[dict[str, Any]]:
        """Forecasts for a batch of latest ratings, each with its interval, band and
        the chance that band is right."""
        if not len(ratings):
            return []
        points = self._predict(pd.DataFrame({"rating_latest": np.asarray(ratings, dtype=float)}))
        cuts = np.array([below for _, below in features.BANDS[:-1]])
        out = []
        for point in points:
            point = float(point)
            low, high = self.conformal.interval(point, features.COVERAGE)
            band = int(np.searchsorted(cuts, point, side="right"))
            landed = np.searchsorted(cuts, self.conformal.outcomes(point), side="right")
            out.append(
                {
                    "point": point,
                    "low": float(np.clip(low, *SCALE)),
                    "high": float(np.clip(high, *SCALE)),
                    "band": features.BANDS[band][0],
                    "confidence": float(np.mean(landed == band)),
                }
            )
        return out

    def forecast(self, rating_latest: float) -> dict[str, Any]:
        return self.forecasts([rating_latest])[0]

    def assess(self, records: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
        read = [contract_inputs.read(record, features.INPUTS) for record in records]
        scorable = [i for i, (values, _) in enumerate(read) if all(c in values for c in features.REQUIRED)]
        made = dict(zip(scorable, self.forecasts([read[i][0]["rating_latest"] for i in scorable]), strict=True))

        results = []
        for i, (values, notes) in enumerate(read):
            missing = [c for c in features.REQUIRED if c not in values]
            if missing:
                results.append(
                    {
                        "status": "insufficient",
                        "missing": missing,
                        "probability": None,
                        "score": None,
                        "tier": None,
                        "basis": None,
                        "factors": None,
                        "interval": None,
                        "band": None,
                        "confidence": None,
                        "warnings": notes,
                    }
                )
                continue
            f = made[i]
            results.append(
                {
                    "status": "scored",
                    "probability": None,
                    "score": round(f["point"], 1),
                    "tier": None,
                    "basis": "latest_appraisal",
                    "factors": None,
                    "interval": {"low": round(f["low"], 1), "high": round(f["high"], 1), "coverage": features.COVERAGE},
                    "band": f["band"],
                    "confidence": round(f["confidence"], 3),
                    "warnings": notes,
                }
            )
        return results

    def contract(self) -> dict[str, Any]:
        return {
            "model": self.name,
            "inputs": {spec.name: spec.describe() for spec in features.INPUTS},
            "required": features.REQUIRED,
            "optional": [],
            "rating_scale": "performance_evaluations.overall_percent (attainment 0–100) of the latest completed "
            "appraisal before the forecast period, read one-for-one as the reference's performance score",
            "bands": {name: below for name, below in features.BANDS if math.isfinite(below)},
            "interval_coverage": features.COVERAGE,
            "confidence": "probability the next rating lands in the forecast's band (conformal, by region)",
            "conformal_regions": [float(e) for e in self.conformal.edges],
            "n_fit": self.n_fit,
            "n_calibration": self.n_calibration,
        }
