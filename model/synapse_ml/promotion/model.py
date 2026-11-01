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
  with a typical record (exact Shapley values, :mod:`synapse_ml.explain`), so only
  recorded facts are ever a reason and the typical record's score (``baseline``)
  plus every factor is the score;
- ``outlook`` — readiness after the next appraisal, for every rating it could give:
  the one thing an employee and their manager can work towards.
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
from ..appraisal.inputs import fitted_ranges
from ..appraisal.patterns import PatternRouter
from ..explain import shapley
from . import features

SEED = 42
CHOSEN = "Logistic Regression (calibrated)"

# Explanations: a factor must move the score by at least this many points.
FACTOR_FLOOR = 1.0

# The outlook: next ratings read on a half-point grid (for where each tier starts),
# and drawn every OUTLOOK_STEP points.
OUTLOOK_GRID = 0.5
OUTLOOK_STEP = 2.5


class MonotoneQuadraticPlatt:
    """Platt scaling with a curvature term: ``p = sigmoid(a·s + c·s² + b)`` on a
    logistic score ``s``, held flat beyond the turning point so it can never reverse.

    Plain logistic regression is already close to calibrated here; what it misses is a
    gentle bend (a little low around the tier cut-offs, a little high at the very top).
    Isotonic regression fixes that too, but as a staircase: a few hundred distinct
    probabilities, ties across thousands of people, and exact 0 % / 100 % at the
    tails. This keeps the correction smooth and monotone, which the readiness score's
    guarantees depend on ("a better record never lowers readiness").

    On a small sample (an organisation's own few hundred appraisals) the fitted bend
    can turn back inside the scores it was fitted on. Its curvature is then noise, not
    signal, so the step falls back to plain Platt scaling — ``c = 0``, monotone by
    construction. Only a score whose direction the outcomes contradict is refused.
    """

    def fit(self, scores, y) -> MonotoneQuadraticPlatt:
        s = np.asarray(scores, dtype=float)
        fit = LogisticRegression(C=1e6, max_iter=5000).fit(np.column_stack([s, s**2]), y)
        self.a, self.c = (float(v) for v in fit.coef_[0])
        self.b = float(fit.intercept_[0])
        # d/ds (a·s + c·s²) = a + 2c·s ≥ 0 holds on one side of the turning point;
        # scores beyond it are held there.
        if self.c != 0:
            turn = -self.a / (2 * self.c)
            self.low, self.high = (turn, np.inf) if self.c > 0 else (-np.inf, turn)
            # Holding the tail flat is a guard, not the model: nearly every score the
            # calibration was fitted on must lie where the curve rises.
            if np.mean((s >= self.low) & (s <= self.high)) >= 0.99:
                return self
            plain = LogisticRegression(C=1e6, max_iter=5000).fit(s.reshape(-1, 1), y)
            self.a, self.c, self.b = float(plain.coef_[0][0]), 0.0, float(plain.intercept_[0])
        if self.a <= 0:
            raise ValueError("calibration reverses the score's direction; refusing to fit")
        self.low, self.high = -np.inf, np.inf
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

    def fit(self, X: pd.DataFrame, y: pd.Series, complete: bool = True) -> PromotionReadinessModel:
        """Fit on the reference (``complete``: every row has both appraisals) or on an
        organisation's own records, where a first appraisal carries no change."""
        self.router.fit(X, y, complete=complete)
        self.base_rate = float(np.mean(y))
        self.tiers = {tier: lift * self.base_rate for tier, lift in features.TIER_LIFT.items()}
        self.typical = {c: float(X[c].median()) for c in X.columns}
        # The range an input is read in is the range the model saw: the reference's
        # own (``features.INPUTS``), or the span of the organisation's records.
        self.local_inputs = None if complete else fitted_ranges(features.INPUTS, X)
        if complete:
            # The score scale: every reference employee as the full-history model sees them.
            full = self.router.submodel(tuple(features.OPTIONAL))
            sample = full.predict_proba(X[self.router.columns(tuple(features.OPTIONAL))])[:, 1]
        else:
            # Every example as the submodel for its own history sees it.
            rows = [{c: v for c, v in row.items() if pd.notna(v)} for row in X.to_dict("records")]
            sample = self.probabilities(rows)
        self.percentile = Percentile(sample)
        return self

    # ---- serving ---------------------------------------------------------------------

    @property
    def features(self) -> list[str]:
        return [spec.name for spec in features.INPUTS]

    @property
    def inputs(self) -> list[contract_inputs.Input]:
        """What the model reads, each with the range it was trained on (a value beyond
        it is held at the edge, with a note)."""
        return getattr(self, "local_inputs", None) or features.INPUTS

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
        read = [contract_inputs.read(record, self.inputs) for record in records]
        rows = [values for values, _ in read]
        probabilities = self.probabilities(rows)
        explained = self._explain(rows, probabilities)
        outlooks = self._outlooks(rows, probabilities)

        results = []
        for (values, notes), p, (baseline, why), outlook in zip(read, probabilities, explained, outlooks, strict=True):
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
                    "baseline": baseline,
                    "outlook": outlook,
                    "warnings": notes,
                }
            )
        return results

    def _scores(self, rows: Sequence[Mapping[str, float]]) -> np.ndarray:
        """Readiness scores (0–100) for rows that each carry every required input."""
        return self.percentile(np.asarray(self.probabilities(rows), dtype=float))

    def _explain(self, rows, probabilities) -> list[tuple[float | None, list[dict[str, Any]] | None]]:
        """Exact Shapley values in score points: how much each recorded input moves this
        score compared with a typical record that has the same history. The typical
        record's score comes with them; together they add up to the score."""
        scored = [i for i, p in enumerate(probabilities) if p is not None]
        explained = shapley(
            [rows[i] for i in scored],
            [[name for name in self.features if name in rows[i]] for i in scored],
            self.typical,
            self._scores,
        )

        out: list[tuple[float | None, list[dict[str, Any]] | None]] = [(None, None)] * len(rows)
        for i, (baseline, contributions) in zip(scored, explained, strict=True):
            ranked = sorted(contributions.items(), key=lambda kv: abs(kv[1]), reverse=True)
            out[i] = (
                round(baseline, 1),
                [
                    {
                        "feature": name,
                        "label": features.LABELS[name],
                        "impact": round(effect, 1),
                        "direction": "up" if effect >= 0 else "down",
                        "value": round(float(rows[i][name]), 2),
                        "typical": round(float(self.typical[name]), 2),
                    }
                    for name, effect in ranked
                    if abs(effect) >= FACTOR_FLOOR
                ],
            )
        return out

    def _outlooks(self, rows, probabilities) -> list[dict[str, Any] | None]:
        """Readiness after the next appraisal, by the rating it gives: that rating
        becomes the latest, and its change on today's latest the change — so the
        record is read by the two-appraisal model, as it will be then. Ratings and
        changes are held to the ranges the model reads."""
        specs = {spec.name: spec for spec in self.inputs}
        latest_spec, change_spec = specs["rating_latest"], specs["rating_change"]
        grid = np.round(np.arange(latest_spec.low, latest_spec.high + OUTLOOK_GRID / 2, OUTLOOK_GRID), 2)
        every = max(1, round(OUTLOOK_STEP / OUTLOOK_GRID))

        scored = [i for i, p in enumerate(probabilities) if p is not None]
        out: list[dict[str, Any] | None] = [None] * len(rows)
        if not scored:
            return out

        def record(rating: float, latest: float) -> dict[str, float]:
            change = float(np.clip(rating - latest, change_spec.low, change_spec.high))
            return {"rating_latest": float(rating), "rating_change": change}

        batch = [record(r, rows[i]["rating_latest"]) for i in scored for r in grid]
        batch += [record(rows[i]["rating_latest"], rows[i]["rating_latest"]) for i in scored]
        p = np.asarray(self.probabilities(batch), dtype=float)
        curves = p[: len(scored) * len(grid)].reshape(len(scored), len(grid))
        same = p[len(scored) * len(grid) :]

        for k, i in enumerate(scored):
            scores = self.percentile(curves[k])

            def first(threshold: float, k: int = k) -> float | None:
                reached = np.flatnonzero(curves[k] >= threshold)
                return float(grid[reached[0]]) if len(reached) else None

            out[i] = {
                "points": [{"rating": float(grid[j]), "score": round(float(scores[j]), 1)} for j in range(0, len(grid), every)],
                "medium_from": first(self.tiers["medium"]),
                "high_from": first(self.tiers["high"]),
                "same_again": round(self.percentile.at(same[k]), 1),
            }
        return out

    def reference(self) -> dict[str, Any]:
        """What every score is read against: the typical record, the promotion rate the
        tiers are multiples of, and where each tier starts on the score scale."""
        return {
            "base_rate": round(self.base_rate, 4),
            "typical": {name: round(float(v), 2) for name, v in self.typical.items()},
            "tier_scores": {tier: round(self.percentile.at(p), 1) for tier, p in self.tiers.items()},
        }

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
        "baseline": None,
        "outlook": None,
        "warnings": notes,
    }
