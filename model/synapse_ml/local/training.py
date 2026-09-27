"""Train a model on one organisation's own records, and decide whether it may replace
the model that organisation is scored by today.

The ERP sends labelled examples it assembled from its own tables — for promotion, an
appraisal and whether a promotion followed before the next one; for performance, one
appraisal and the next; for attrition, a stored risk-score snapshot and whether the
person resigned within the year. Each example names the employee it belongs to
(``group``), because several examples can come from one person and none of them may
end up on both sides of a test.

**Same model, same contract.** The local model is the surface's own model class fitted
on the organisation's examples instead of the reference data, reading exactly the
inputs the reference model reads — so every page, factor and range renders unchanged,
and the comparison below is like for like.

**The check is the point.** A model fitted on a few hundred examples still returns
confident-looking numbers, so it is only offered as a replacement when, on the
organisation's *own* people, it is

1. more accurate than the model in use today (``reference``), and
2. more accurate than knowing nothing about the person (the organisation's usual
   promotion rate; simply repeating the last rating; a coin flip),

each measured out of fold — every example scored by a model fitted without that
employee — and each holding in at least ``MUST_WIN`` of ``BOOTSTRAP`` resamples of the
organisation's people, so a lucky split cannot carry it. A forecast must also keep its
own promise: its ranges hold about four in five of the ratings that followed.

The minimum volumes (``MINIMUMS``) are the ones the ERP's graduation panel shows; the
service enforces them too, rather than trusting the caller.
"""

from __future__ import annotations

import logging
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd
from sklearn.metrics import roc_auc_score
from sklearn.model_selection import GroupKFold, StratifiedGroupKFold

from ..appraisal.inputs import number
from ..attrition import features as attrition_features
from ..attrition import model as attrition_model
from ..performance import features as performance_features
from ..performance.model import PerformanceForecastModel
from ..promotion import features as promotion_features
from ..promotion.model import PromotionReadinessModel

log = logging.getLogger("synapse.local")

SEED = 42
FOLDS = 5
BOOTSTRAP = 1000
# A local model must come out ahead in at least this share of resamples.
MUST_WIN = 0.90
# A forecast's ranges promise four in five; held out they must land within this.
COVERAGE_BAND = (0.70, 0.90)

MODELS = ("promotion", "performance", "attrition")

# The volumes below which a model is not trained at all — mirrored by the ERP's
# graduation panel (App\Support\Ml\Graduation). Binary outcomes: 100 of each (Collins,
# Ogundimu & Altman, 2016: validating a prediction model needs at least 100 of the rarer
# outcome). A forecast: 234 + one per input (Riley et al., 2019: the residual spread —
# what the forecast's range is built from — pinned within 10 %), forecasting at least two
# different review cycles (``cycle`` is the cycle of the rating predicted), so no single
# year's drift is all it learns — three cycles in a row.
MINIMUMS: dict[str, dict[str, int]] = {
    "promotion": {"promoted": 100, "not_promoted": 100, "promoted_with_change": 50},
    "performance": {"comparisons": 235, "cycles": 2},
    "attrition": {"resigned": 100, "stayed": 100},
}

COLUMNS = {
    "promotion": [spec.name for spec in promotion_features.INPUTS],
    "performance": [spec.name for spec in performance_features.INPUTS],
    "attrition": attrition_features.FEATURES,
}

# A function scoring feature dicts with the model in use today: a probability
# (promotion, attrition) or a point forecast (performance) per record.
Reference = Callable[[list[dict[str, Any]]], np.ndarray]


class TrainingRefused(ValueError):
    """The examples cannot be trained on at all — malformed, or below the minimums."""


@dataclass
class Examples:
    model: str
    records: list[dict[str, Any]]  # each example's inputs, as a live record would send them
    X: pd.DataFrame
    y: pd.Series
    groups: np.ndarray
    cycles: np.ndarray

    @property
    def people(self) -> int:
        return len(np.unique(self.groups))


@dataclass
class Outcome:
    verdict: str  # "passed" | "failed"
    findings: list[str]  # plain-language sentences, one per check, for the HR reader
    comparison: dict[str, Any]
    fitted: Any = None
    counts: dict[str, int] = field(default_factory=dict)


# ---- the examples ---------------------------------------------------------------------


def examples(model: str, rows: Sequence[Mapping[str, Any]]) -> Examples:
    """Read the ERP's rows (``{group, features, outcome, cycle?}``) into a frame."""
    if model not in MODELS:
        raise TrainingRefused(f"no local training for '{model}'")

    columns = COLUMNS[model]
    records, outcomes, groups, cycles = [], [], [], []
    for i, row in enumerate(rows):
        feats = {k: v for k, v in dict(row.get("features") or {}).items() if k in columns and v is not None}
        outcome = number(row.get("outcome"))
        if outcome is None:
            raise TrainingRefused(f"row {i} has no numeric outcome")
        if model == "performance":
            if not 0 <= outcome <= 100:
                raise TrainingRefused(f"row {i}: a rating outside 0–100")
        elif outcome not in (0.0, 1.0):
            raise TrainingRefused(f"row {i}: the outcome must be 0 or 1")
        if model != "attrition" and number(feats.get("rating_latest")) is None:
            raise TrainingRefused(f"row {i} has no latest appraisal")
        if row.get("group") in (None, ""):
            raise TrainingRefused(f"row {i} names no employee")
        records.append(feats)
        outcomes.append(outcome)
        groups.append(str(row["group"]))
        cycles.append(str(row.get("cycle") or ""))

    X = pd.DataFrame([{c: r.get(c, np.nan) for c in columns} for r in records], columns=columns)
    categorical = attrition_features.CATEGORICAL_FEATURES if model == "attrition" else []
    for column in columns:
        if column in categorical:
            X[column] = X[column].astype(object).where(X[column].notna(), np.nan)
        else:
            X[column] = pd.to_numeric(X[column], errors="coerce")

    y = pd.Series(outcomes, dtype=float if model == "performance" else int)
    return Examples(model, records, X, y, np.asarray(groups), np.asarray(cycles))


def counts(ex: Examples) -> dict[str, int]:
    """The volumes the minimums are stated in."""
    n = len(ex.y)
    if ex.model == "performance":
        return {"comparisons": n, "cycles": len({c for c in ex.cycles if c}), "people": ex.people}
    positive = int(ex.y.sum())
    if ex.model == "promotion":
        with_change = int(((ex.y == 1) & ex.X["rating_change"].notna()).sum())
        return {"promoted": positive, "not_promoted": n - positive, "promoted_with_change": with_change,
                "people": ex.people}
    return {"resigned": positive, "stayed": n - positive, "people": ex.people}


def shortfalls(ex: Examples) -> list[str]:
    """Every minimum the examples fall short of, or nothing."""
    have = counts(ex)
    short = [f"{key}: {have[key]} of {need}" for key, need in MINIMUMS[ex.model].items() if have[key] < need]
    if ex.people < FOLDS:
        short.append(f"people: {ex.people} of {FOLDS}")
    return short


# ---- fitting and scoring --------------------------------------------------------------


def fit(ex_model: str, X: pd.DataFrame, y: pd.Series, groups: np.ndarray) -> Any:
    """The surface's own model class, fitted on these examples."""
    if ex_model == "promotion":
        return PromotionReadinessModel(SEED).fit(X, y, complete=False)
    if ex_model == "performance":
        return PerformanceForecastModel.for_sample(len(X), SEED).fit(X, y, groups=groups)
    return attrition_model.random_forest(SEED).fit(X, y)


def predict(ex_model: str, fitted: Any, records: list[dict[str, Any]], X: pd.DataFrame) -> dict[str, np.ndarray]:
    """What the fitted model would serve for these records: ``value`` (probability or
    point forecast), plus ``low`` / ``high`` for a forecast."""
    if ex_model == "attrition":
        return {"value": fitted.predict_proba(X)[:, 1]}
    results = fitted.assess(records)
    if ex_model == "promotion":
        return {"value": np.array([r["probability"] for r in results], dtype=float)}
    return {
        "value": np.array([r["score"] for r in results], dtype=float),
        "low": np.array([r["interval"]["low"] for r in results], dtype=float),
        "high": np.array([r["interval"]["high"] for r in results], dtype=float),
    }


def out_of_fold(ex: Examples) -> dict[str, np.ndarray]:
    """Every example scored by a local model fitted without that employee."""
    if ex.model == "performance":
        folds = GroupKFold(FOLDS, shuffle=True, random_state=SEED).split(ex.X, ex.y, ex.groups)
    else:
        folds = StratifiedGroupKFold(FOLDS, shuffle=True, random_state=SEED).split(ex.X, ex.y, ex.groups)

    out: dict[str, np.ndarray] = {}
    for train, test in folds:
        fitted = fit(ex.model, ex.X.iloc[train], ex.y.iloc[train], ex.groups[train])
        made = predict(ex.model, fitted, [ex.records[i] for i in test], ex.X.iloc[test])
        for key, values in made.items():
            out.setdefault(key, np.full(len(ex.y), np.nan))[test] = values
    return out


# ---- the comparison -------------------------------------------------------------------


def brier(y, p) -> float:
    return float(np.mean((np.asarray(y, dtype=float) - p) ** 2))


def mae(y, p) -> float:
    return float(np.mean(np.abs(np.asarray(y, dtype=float) - p)))


def auc(y, p) -> float:
    return float(roc_auc_score(y, p))


# Per surface: the measure a replacement is judged on, and which way is better.
MEASURES: dict[str, tuple[str, Callable, str]] = {
    "promotion": ("brier", brier, "lower"),
    "performance": ("mae", mae, "lower"),
    "attrition": ("roc_auc", auc, "higher"),
}


def wins(ex: Examples, measure: Callable, better: str, local: np.ndarray, other: np.ndarray,
         resamples: int = BOOTSTRAP, seed: int = SEED) -> float:
    """The share of resamples of the organisation's *people* (each drawn with all of
    their examples) in which ``local`` does better than ``other``."""
    rng = np.random.default_rng(seed)
    _, inverse = np.unique(ex.groups, return_inverse=True)
    order = np.argsort(inverse, kind="stable")
    members = np.split(order, np.flatnonzero(np.diff(inverse[order])) + 1)
    y = ex.y.to_numpy()
    binary = ex.model != "performance"

    won = valid = 0
    for _ in range(resamples):
        idx = np.concatenate([members[i] for i in rng.integers(0, len(members), len(members))])
        ys = y[idx]
        if binary and len(np.unique(ys)) < 2:
            continue
        a, b = measure(ys, local[idx]), measure(ys, other[idx])
        valid += 1
        won += (a < b) if better == "lower" else (a > b)
    return won / valid if valid else 0.0


def baseline(ex: Examples) -> np.ndarray:
    """Knowing nothing about the person: the promotion rate of the rest of the
    organisation, each person's last rating repeated, or a coin flip."""
    if ex.model == "performance":
        return ex.X["rating_latest"].to_numpy(dtype=float)
    if ex.model == "attrition":
        return np.full(len(ex.y), 0.5)
    rate = np.zeros(len(ex.y))
    for train, test in StratifiedGroupKFold(FOLDS, shuffle=True, random_state=SEED).split(ex.X, ex.y, ex.groups):
        rate[test] = ex.y.iloc[train].mean()
    return rate


def train(model: str, rows: Sequence[Mapping[str, Any]], reference: Reference) -> Outcome:
    """Fit a model on the organisation's examples and judge it against ``reference``.

    Raises :class:`TrainingRefused` when the examples are malformed or below the
    minimums. A model that cannot be fitted on part of the records, or does not pass
    the check, comes back ``failed`` with the reason — it is never offered.
    """
    ex = examples(model, rows)
    short = shortfalls(ex)
    if short:
        raise TrainingRefused("below the minimums — " + "; ".join(short))

    name, measure, better = MEASURES[model]
    have = counts(ex)
    comparison: dict[str, Any] = {"metric": name, "better": better, "required_share": MUST_WIN,
                                  "examples": len(ex.y), "people": ex.people}

    try:
        local = out_of_fold(ex)
    except (ValueError, np.linalg.LinAlgError) as exc:
        log.warning("'%s' local model could not be fitted out of fold: %s", model, exc)
        return Outcome("failed", [UNSTABLE[model]], comparison, counts=have)

    today = np.asarray(reference(ex.records), dtype=float)
    naive = baseline(ex)
    y = ex.y.to_numpy()

    comparison |= {
        "local": round(measure(y, local["value"]), 4),
        "reference": round(measure(y, today), 4),
        "baseline": round(measure(y, naive), 4),
        "wins_over_reference": round(wins(ex, measure, better, local["value"], today), 3),
        "wins_over_baseline": round(wins(ex, measure, better, local["value"], naive), 3),
    }

    findings: list[str] = []
    passed = True
    if comparison["wins_over_reference"] >= MUST_WIN:
        findings.append(WORDING[model]["beats_reference"].format_map(_words(comparison)))
    else:
        passed = False
        findings.append(WORDING[model]["not_reference"].format_map(_words(comparison)))
    if comparison["wins_over_baseline"] < MUST_WIN:
        passed = False
        findings.append(WORDING[model]["not_baseline"].format_map(_words(comparison)))

    if model == "performance":
        inside = (y >= local["low"]) & (y <= local["high"])
        comparison["coverage"] = round(float(np.mean(inside)), 3)
        comparison["promised_coverage"] = performance_features.COVERAGE
        low, high = COVERAGE_BAND
        if not low <= comparison["coverage"] <= high:
            passed = False
            findings.append(
                f"Its likely ranges held {comparison['coverage']:.0%} of the ratings that followed, against the "
                f"four in five they promise — {'too narrow' if comparison['coverage'] < low else 'too wide'} to trust."
            )

    if not passed:
        return Outcome("failed", findings, comparison, counts=have)

    try:
        fitted = fit(model, ex.X, ex.y, ex.groups)
    except (ValueError, np.linalg.LinAlgError) as exc:
        log.warning("'%s' local model could not be fitted on every example: %s", model, exc)
        return Outcome("failed", [UNSTABLE[model]], comparison, counts=have)
    return Outcome("passed", findings, comparison, fitted, counts=have)


def _words(c: Mapping[str, Any]) -> dict[str, Any]:
    """The comparison as the wording reads it: shares as whole percentages."""
    out = dict(c) | {k: f"{c[k]:.0%}" for k in ("wins_over_reference", "wins_over_baseline", "required_share")}
    if c["metric"] == "roc_auc":
        out |= {f"{k}_pct": f"{c[k]:.0%}" for k in ("local", "reference")}
    return out


# What the HR reader is told. Every sentence says what was compared, on whose records,
# and — when it failed — by how much it fell short of what switching needs.
WORDING: dict[str, dict[str, str]] = {
    "promotion": {
        "beats_reference": "On your own records, your model’s predicted chances of promotion were closer to what "
        "happened than the general model’s (prediction error {local:.3f} against {reference:.3f}), and it came out "
        "ahead in {wins_over_reference} of re-checks.",
        "not_reference": "On your own records, your model was not reliably more accurate than the general model "
        "(prediction error {local:.3f} against {reference:.3f}): it came out ahead in {wins_over_reference} of "
        "re-checks, and switching needs {required_share}.",
        "not_baseline": "It did not reliably beat simply assuming everyone has your organisation’s usual promotion "
        "rate (ahead in {wins_over_baseline} of re-checks, {required_share} needed) — your records do not yet show "
        "what sets the people who were promoted apart.",
    },
    "performance": {
        "beats_reference": "On your own records, your model’s forecasts missed by {local:.1f} points on average, "
        "against {reference:.1f} for the general model, and it came out ahead in {wins_over_reference} of re-checks.",
        "not_reference": "On your own records, your model’s forecasts missed by {local:.1f} points on average, "
        "against {reference:.1f} for the general model — not reliably better: it came out ahead in "
        "{wins_over_reference} of re-checks, and switching needs {required_share}.",
        "not_baseline": "It did not reliably beat simply repeating each person’s last rating ({baseline:.1f} points "
        "off on average; ahead in {wins_over_baseline} of re-checks, {required_share} needed).",
    },
    "attrition": {
        "beats_reference": "On your own records, your model ranked a person who resigned above one who stayed "
        "{local_pct} of the time, against {reference_pct} for the general model, and came out ahead in "
        "{wins_over_reference} of re-checks.",
        "not_reference": "On your own records, your model ranked a person who resigned above one who stayed "
        "{local_pct} of the time, against {reference_pct} for the general model — not reliably better: it came out "
        "ahead in {wins_over_reference} of re-checks, and switching needs {required_share}.",
        "not_baseline": "It did not reliably tell people who resigned from people who stayed better than a coin "
        "flip (ahead of chance in {wins_over_baseline} of re-checks, {required_share} needed).",
    },
}

UNSTABLE = {
    "promotion": "The pattern in your records is not yet stable enough to learn: with part of them set aside, a "
    "model could not be fitted on the rest.",
    "performance": "The pattern in your records is not yet stable enough to learn: with part of them set aside, "
    "a forecast and its likely range could not be fitted on the rest.",
    "attrition": "The pattern in your records is not yet stable enough to learn: with part of them set aside, a "
    "model could not be fitted on the rest.",
}
