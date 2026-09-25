"""The promotion-readiness model, the candidates it was chosen against, and the
object the inference service serves.

**Estimator.** Logistic regression on standardised inputs, recalibrated by a
monotone quadratic Platt step fitted on cross-fitted scores
(:class:`CalibratedLogistic`). The logistic ranking is what the data supports — the
log-odds of promotion are close to linear in both level and change — and the
calibration step corrects the small curvature it misses, so a probability means
what it says.

**Served object.** :class:`PromotionReadinessModel` wraps one such estimator per
history pattern (:class:`~synapse_ml.appraisal.patterns.PatternRouter`) and turns a
probability into what HR reads:

- ``probability`` — the share of reference employees with this record who were
  promoted within the year;
- ``score`` (0–100) — where that probability sits among the whole reference
  workforce (mid-rank percentile), so the same score means the same thing however
  much history a record has;
- ``tier`` — by lift over the reference promotion rate (see ``TIER_LIFT``);
- ``factors`` — for each input, how many score points it moves the result compared
  with a typical value (occlusion), so only recorded facts are ever a reason.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

import numpy as np
import pandas as pd
from sklearn.base import BaseEstimator, ClassifierMixin
from sklearn.dummy import DummyClassifier
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold, cross_val_predict
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler

from ..appraisal import inputs as contract_inputs
from ..appraisal.patterns import PatternRouter
from . import features

SEED = 42
CHOSEN = "Logistic Regression (calibrated)"

# Explanations: a factor must move the score by at least this many points.
FACTOR_FLOOR = 1.0


class MonotoneQuadraticPlatt:
    """Platt scaling with a curvature term: ``p = sigmoid(a·s + c·s² + b)`` on a
    logistic score ``s``, held flat beyond the turning point so it can never reverse.

    Plain logistic regression is already close to calibrated here; what it misses is a
    gentle bend (a little low around the tier cut-offs, a little high at the very top).
    Isotonic regression fixes that too, but as a staircase: a few hundred distinct
    probabilities, ties across thousands of people, and exact 0 % / 100 % at the
    tails. This keeps the correction smooth and monotone, which the readiness score's
    guarantees depend on ("a better record never lowers readiness").
    """

    def fit(self, scores, y) -> MonotoneQuadraticPlatt:
        s = np.asarray(scores, dtype=float)
        fit = LogisticRegression(C=1e6, max_iter=5000).fit(np.column_stack([s, s**2]), y)
        self.a, self.c = (float(v) for v in fit.coef_[0])
        self.b = float(fit.intercept_[0])
        # d/ds (a·s + c·s²) = a + 2c·s ≥ 0 holds on one side of the turning point;
        # scores beyond it are held there.
        if self.c == 0:
            if self.a <= 0:
                raise ValueError("calibration reverses the score's direction; refusing to fit")
            self.low, self.high = -np.inf, np.inf
        else:
            turn = -self.a / (2 * self.c)
            self.low, self.high = (turn, np.inf) if self.c > 0 else (-np.inf, turn)
        # Holding the tail flat is a guard, not the model: nearly every score the
        # calibration was fitted on must lie where the curve rises.
        rising = np.mean((s >= self.low) & (s <= self.high))
        if rising < 0.99:
            raise ValueError(f"calibration rises over only {rising:.1%} of the scores; refusing to fit")
        return self

    def predict(self, scores) -> np.ndarray:
        s = np.clip(np.asarray(scores, dtype=float), self.low, self.high)
        return 1.0 / (1.0 + np.exp(-(self.a * s + self.c * s**2 + self.b)))


class CalibratedLogistic(ClassifierMixin, BaseEstimator):
    """Standardised logistic regression whose probabilities are recalibrated by
    :class:`MonotoneQuadraticPlatt`, fitted on out-of-fold scores (never on scores of
    rows the regression trained on)."""

    def __init__(self, C: float = 1.0, cv: int = 5, random_state: int = SEED) -> None:
        self.C = C
        self.cv = cv
        self.random_state = random_state

    def _base(self) -> Pipeline:
        return Pipeline([("scale", StandardScaler()), ("clf", LogisticRegression(C=self.C, max_iter=5000))])

    def fit(self, X, y):
        y = np.asarray(y)
        folds = StratifiedKFold(self.cv, shuffle=True, random_state=self.random_state)
        scores = cross_val_predict(self._base(), X, y, cv=folds, method="decision_function")
        self.calibrator_ = MonotoneQuadraticPlatt().fit(scores, y)
        self.model_ = self._base().fit(X, y)
        self.classes_ = np.array([0, 1])
        self.feature_names_in_ = np.asarray(getattr(X, "columns", range(np.shape(X)[1])))
        return self

    def decision_function(self, X) -> np.ndarray:
        return self.model_.decision_function(X)

    def predict_proba(self, X) -> np.ndarray:
        p = self.calibrator_.predict(self.decision_function(X))
        return np.column_stack([1 - p, p])

    def predict(self, X) -> np.ndarray:
        return (self.predict_proba(X)[:, 1] >= 0.5).astype(int)

    def odds_ratios(self) -> dict[str, float]:
        """exp(coefficient) per one standard deviation of each input."""
        coef = self.model_.named_steps["clf"].coef_[0]
        return {str(name): float(np.exp(c)) for name, c in zip(self.feature_names_in_, coef, strict=True)}


def candidates(seed: int = SEED) -> dict[str, Any]:
    """Every estimator compared in the notebook, on identical folds, prior-only first."""
    return {
        "Baseline (prior)": DummyClassifier(strategy="prior"),
        "Logistic Regression": Pipeline(
            [("scale", StandardScaler()), ("clf", LogisticRegression(max_iter=5000))]
        ),
        CHOSEN: CalibratedLogistic(random_state=seed),
        "Gradient Boosting": HistGradientBoostingClassifier(
            max_iter=300, learning_rate=0.05, max_leaf_nodes=15, random_state=seed
        ),
    }


class Percentile:
    """Mid-rank percentile (0–100) of a value among a fixed reference sample."""

    def __init__(self, sample: Sequence[float]) -> None:
        self.sorted = np.sort(np.asarray(sample, dtype=float))

    def __call__(self, values) -> np.ndarray:
        values = np.asarray(values, dtype=float)
        below = np.searchsorted(self.sorted, values, side="left")
        through = np.searchsorted(self.sorted, values, side="right")
        return 100.0 * (below + through) / (2 * len(self.sorted))

    def at(self, value: float) -> float:
        return float(self(np.array([value]))[0])


class PromotionReadinessModel:
    """What the inference service serves for ``/predict/promotion``."""

    name = "promotion"
    kind = "classifier"
    algorithm = "LogisticRegression+QuadraticPlatt (pattern submodels)"

    def __init__(self, seed: int = SEED) -> None:
        self.seed = seed
        self.router = PatternRouter(CalibratedLogistic(random_state=seed), features.REQUIRED, features.OPTIONAL)

    # ---- training --------------------------------------------------------------------

    def fit(self, X: pd.DataFrame, y: pd.Series) -> PromotionReadinessModel:
        self.router.fit(X, y)
        self.base_rate = float(np.mean(y))
        self.tiers = {tier: lift * self.base_rate for tier, lift in features.TIER_LIFT.items()}
        self.typical = {c: float(X[c].median()) for c in X.columns}
        # The score scale: every reference employee as the full-history model sees them.
        full = self.router.submodel(tuple(features.OPTIONAL))
        self.percentile = Percentile(full.predict_proba(X[self.router.columns(tuple(features.OPTIONAL))])[:, 1])
        return self

    # ---- serving ---------------------------------------------------------------------

    @property
    def features(self) -> list[str]:
        return [spec.name for spec in features.INPUTS]

    def tier(self, probability: float) -> str:
        if probability >= self.tiers["high"]:
            return "high"
        if probability >= self.tiers["medium"]:
            return "medium"
        return "low"

    def probabilities(self, rows: Sequence[Mapping[str, float]]) -> list[float | None]:
        out = self.router.apply("predict_proba", rows)
        return [None if p is None else float(p[1]) for p in out]

    def assess(self, records: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
        read = [contract_inputs.read(record, features.INPUTS) for record in records]
        rows = [values for values, _ in read]
        probabilities = self.probabilities(rows)
        factors = self._factors(rows, probabilities)

        results = []
        for (values, notes), p, why in zip(read, probabilities, factors, strict=True):
            if p is None:
                results.append(_insufficient([c for c in features.REQUIRED if c not in values], notes))
                continue
            pattern = self.router.pattern(values)
            results.append(
                {
                    "status": "scored",
                    "probability": round(p, 5),
                    "score": round(self.percentile.at(p), 1),
                    "tier": self.tier(p),
                    "basis": features.BASIS[pattern],
                    "factors": why,
                    "warnings": notes,
                }
            )
        return results

    def _factors(self, rows, probabilities) -> list[list[dict[str, Any]] | None]:
        """Occlusion in score points: how much each recorded input moves this score
        compared with the reference's typical value for it."""
        base = [None if p is None else self.percentile.at(p) for p in probabilities]
        effects: list[dict[str, float]] = [{} for _ in rows]
        for name in self.features:
            swapped = [
                {**row, name: self.typical[name]} if (b is not None and name in row) else None
                for row, b in zip(rows, base, strict=True)
            ]
            present = [i for i, row in enumerate(swapped) if row is not None]
            if not present:
                continue
            typical_p = self.probabilities([swapped[i] for i in present])
            for i, q in zip(present, typical_p, strict=True):
                effects[i][name] = base[i] - self.percentile.at(q)

        out: list[list[dict[str, Any]] | None] = []
        for b, row_effects in zip(base, effects, strict=True):
            if b is None:
                out.append(None)
                continue
            ranked = sorted(row_effects.items(), key=lambda kv: abs(kv[1]), reverse=True)
            out.append(
                [
                    {
                        "feature": name,
                        "label": features.LABELS[name],
                        "impact": round(effect, 1),
                        "direction": "up" if effect >= 0 else "down",
                    }
                    for name, effect in ranked
                    if abs(effect) >= FACTOR_FLOOR
                ]
            )
        return out

    def contract(self) -> dict[str, Any]:
        """The serving contract, written next to the model as ``feature_contract.json``."""
        return {
            "model": self.name,
            "inputs": {spec.name: spec.describe() for spec in features.INPUTS},
            "required": features.REQUIRED,
            "optional": features.OPTIONAL,
            "rating_scale": "performance_evaluations.overall_percent (attainment 0–100), read one-for-one "
            "as the reference's performance_score",
            "basis": {
                "two_appraisals": "latest appraisal and its change on the previous one",
                "latest_appraisal": "latest appraisal only — no previous completed appraisal, so no change is known",
            },
            "base_rate": self.base_rate,
            "tiers": {"high_from": self.tiers["high"], "medium_from": self.tiers["medium"], "lift": features.TIER_LIFT},
            "tier_scores": {tier: round(self.percentile.at(p), 1) for tier, p in self.tiers.items()},
            "typical": self.typical,
            "score": "mid-rank percentile of the probability among the reference workforce",
            "factor_floor_points": FACTOR_FLOOR,
        }


def _insufficient(missing: list[str], notes: list[str]) -> dict[str, Any]:
    return {
        "status": "insufficient",
        "missing": missing,
        "probability": None,
        "score": None,
        "tier": None,
        "basis": None,
        "factors": None,
        "warnings": notes,
    }
