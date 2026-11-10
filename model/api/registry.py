"""
Model registry for the Synapse inference service.

Loads the trained models from ``model/artifacts/<name>/`` once at start-up. Two kinds
of artifact are served:

* **served models** (promotion, performance) — objects from ``synapse_ml`` that own
  their whole contract: they read each record's inputs, decline a record that lacks a
  required one, and return the score, tier or band, interval and explanation
  themselves. The notebook, the tests and this service therefore run one code path.
* **pipelines** (attrition) — a fitted scikit-learn ``Pipeline``. Callers send
  whatever features they have; a full-width frame is built in the pipeline's column
  order and its own imputers fill the rest. Explanations are logit contributions for
  a linear model and exact Shapley values against the typical record
  (:mod:`synapse_ml.explain`) for any other classifier, each read against the leave
  rates of the data the model learned from (:mod:`synapse_ml.attrition.evidence`).
  Each attrition result also carries ``scenarios``: the score of the same record
  with a change HR can make (a promotion, regular employment, a raise into the next
  pay band), so the page can say what the model would weigh differently — and
  ``indicators``: the behavioural indicators of emotional states the record can be
  read on (:mod:`synapse_ml.attrition.indicators`), with the points the score gives
  each one it reads. An indicator the pipeline derives from several inputs
  (withdrawal) is one input of the explanation, not several.

Beside these **reference** models, an organisation that has graduated a surface has
its own model of the same class, fitted on its records (``synapse_ml.local``). It is
loaded on first use from ``artifacts/local/<tenant>/…`` and served through exactly the
same code path, only ever for the organisation that names it.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any

import joblib
import numpy as np
import pandas as pd

from synapse_ml.attrition import evidence as attrition_evidence
from synapse_ml.attrition import indicators as attrition_indicators
from synapse_ml.attrition.features import BAND_EDGES, FEATURE_GROUPS, INDICATORS, SIGNALS, TIER_CUTS
from synapse_ml.attrition.features import FEATURE_LABELS as ATTRITION_FEATURE_LABELS
from synapse_ml.explain import shapley
from synapse_ml.local import store as local_store
from synapse_ml.paths import ARTIFACTS_DIR

# Which artifacts to serve, and how each is scored.
_SPECS = {
    "promotion": "classifier",
    "performance": "regressor",
    "attrition": "classifier",
}

# Attrition's tier cut-offs on the probability scale (mirrors its notebook). The served
# models carry their own tiers and bands.
TIER_BINS = ((0.33, "low"), (0.66, "medium"), (1.01, "high"))

# Demographic / protected attributes are never surfaced as decision factors — both
# for fairness and because the HR app deliberately does not feed them (the model
# imputes a constant, which would otherwise show up as spurious "drivers").
PROTECTED_FEATURES = {"gender", "marital_status", "age", "education_level", "city_tier"}

# Contributions smaller than this (two risk points) are not offered as a reason.
# On the attrition model real drivers move a score by 10–35 points; below ~2 the
# effect is the model's own noise and reads as contradiction, not insight. (They are
# still part of the score: the page shows them together as "smaller effects".)
FACTOR_FLOOR = 0.02

FEATURE_LABELS = dict(ATTRITION_FEATURE_LABELS)


def _humanize(encoded_name: str) -> str:
    """Turn a ColumnTransformer output name (``num__years_at_company`` or
    ``cat__department_Sales``) into a friendly label."""
    raw = encoded_name.split("__", 1)[-1]
    for base, label in FEATURE_LABELS.items():
        if raw == base:
            return label
        if raw.startswith(f"{base}_"):
            return f"{label}: {raw[len(base) + 1:]}"
    return raw.replace("_", " ")


def tier_for(probability: float) -> str:
    for threshold, name in TIER_BINS:
        if probability < threshold:
            return name
    return "high"


@dataclass
class LoadedModel:
    name: str
    kind: str
    pipeline: Any
    numeric: list[str]
    categorical: list[str]
    features: list[str]
    metrics: dict[str, Any] = field(default_factory=dict)
    # A synapse_ml served model (has ``assess``), or None for a plain pipeline.
    served: Any = None
    # The organisation whose records it was fitted on; None for the reference model.
    tenant: str | None = None
    # Leave rates by band in the data it learned from (attrition; see
    # synapse_ml.attrition.evidence) — what each reason is read against.
    evidence: dict[str, Any] | None = None
    # What an explanation attributes the score to: each input, except where the
    # pipeline reads several only as one derived value (attrition's withdrawal).
    players: dict[str, list[str]] = field(default_factory=dict)

    @property
    def version(self) -> str | None:
        if self.tenant is not None:
            return f"local:{self.metrics.get('version')}"
        algo = self.metrics.get("algorithm")
        saved = self.metrics.get("saved_at")
        if algo and saved:
            return f"{algo}@{saved}"
        return algo or saved

    def frame(self, feature_dicts: list[dict[str, Any]]) -> pd.DataFrame:
        """Build a full-width, correctly-typed DataFrame from partial inputs."""
        rows = [{col: feats.get(col, np.nan) for col in self.features} for feats in feature_dicts]
        df = pd.DataFrame(rows, columns=self.features)

        for col in self.numeric:
            df[col] = pd.to_numeric(df[col], errors="coerce")
        for col in self.categorical:
            # Object dtype with real NaNs so the fitted most-frequent imputer fires.
            df[col] = df[col].astype(object).where(df[col].notna(), np.nan)

        return df


def _loaded(name: str, artifact: Any, metrics: dict[str, Any], tenant: str | None = None) -> LoadedModel:
    """Wrap a fitted artifact — served object or pipeline — for serving."""
    if hasattr(artifact, "assess"):
        return LoadedModel(
            name=name,
            kind=artifact.kind,
            pipeline=None,
            numeric=list(artifact.features),
            categorical=[],
            features=list(artifact.features),
            metrics=metrics,
            served=artifact,
            tenant=tenant,
        )

    prep = artifact.named_steps["prep"]
    # An organisation's model carries its own records' table; the reference reads the
    # survey's, written beside it.
    if tenant is not None:
        evidence = metrics.get("evidence")
    else:
        evidence = attrition_evidence.load() if name == "attrition" else None

    # Branches by name: an indicator's (withdrawal), whose inputs the explanation
    # reveals together as one, and every other — numbers read as they are ("num"),
    # an organisation's signals ("signals"), categories ("cat") — one input each.
    branches = [(branch, list(columns)) for branch, _, columns in prep.transformers_ if branch != "remainder" and len(columns)]
    categorical = [c for branch, columns in branches if branch == "cat" for c in columns]
    players: dict[str, list[str]] = {}
    for branch, columns in branches:
        if branch in INDICATORS:
            players[branch] = columns
        else:
            players.update({column: [column] for column in columns})
    features = list(prep.feature_names_in_)
    return LoadedModel(
        name=name,
        kind=_SPECS[name],
        pipeline=artifact,
        numeric=[f for f in features if f not in categorical],
        categorical=categorical,
        features=features,
        metrics=metrics,
        tenant=tenant,
        evidence=evidence,
        players={p: players[p] for p in sorted(players, key=lambda p: features.index(players[p][0]))},
    )


class Registry:
    def __init__(self, local_root=None) -> None:
        self.models: dict[str, LoadedModel] = {}
        # Organisation models, loaded on first use: (tenant, name, version) → model.
        self.local_models: dict[tuple[str, str, str], LoadedModel] = {}
        self.local_root = local_root

    def load(self) -> None:
        for name in _SPECS:
            path = ARTIFACTS_DIR / name / f"{name}_model.joblib"
            if not path.exists():
                continue

            metrics_path = ARTIFACTS_DIR / name / "metrics.json"
            metrics = json.loads(metrics_path.read_text(encoding="utf-8")) if metrics_path.exists() else {}
            self.models[name] = _loaded(name, joblib.load(path), metrics)

    def local(self, name: str, tenant: str, version: str) -> LoadedModel:
        """An organisation's own model for ``name``; raises ``UnknownLocalModel``."""
        key = (tenant, name, version)
        if key not in self.local_models:
            artifact, metrics = local_store.load(tenant, name, version, self.local_root)
            self.local_models[key] = _loaded(name, artifact, metrics, tenant=tenant)
        return self.local_models[key]

    def predict(self, name: str, feature_dicts: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """One result dict per instance from the reference model ``name``."""
        return self.predict_with(self.get(name), feature_dicts)

    def predict_with(self, model: LoadedModel, feature_dicts: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """One result dict per instance, whatever the kind of artifact."""
        if model.served is not None:
            return model.served.assess(feature_dicts)

        probabilities, scores = self.score(model, feature_dicts)
        explained = self._explained(model, feature_dicts)
        scenarios = self.scenarios(model, feature_dicts)
        return [
            {
                "status": "scored",
                "probability": proba,
                "score": score,
                "tier": tier_for(proba) if proba is not None else None,
                "factors": factors or None,
                "baseline": baseline,
                "scenarios": what_if or None,
                "indicators": readings,
            }
            for proba, score, (baseline, factors, readings), what_if in zip(
                probabilities, scores, explained, scenarios, strict=True
            )
        ]

    def _explained(self, model: LoadedModel, feature_dicts: list[dict[str, Any]]) -> list[tuple[Any, Any, Any]]:
        """Per instance: the typical record's score, the factors, and — for the
        attrition pipeline — its behavioural-indicator readings."""
        estimator = model.pipeline.steps[-1][1]
        if model.name == "attrition" and model.kind == "classifier" and not hasattr(estimator, "coef_"):
            return _shapley_factors(model, feature_dicts, with_readings=True)
        return [(baseline, factors, None) for baseline, factors in self.explain(model, feature_dicts)]

    def scenarios(self, model: LoadedModel, feature_dicts: list[dict[str, Any]]) -> list[list[dict[str, Any]]]:
        """Per instance, the attrition score the model gives the same record with one
        change HR can make — a promotion now, regular employment, a raise into the next
        pay band — and with all of them together. One batched ``predict`` for a run.

        Only changes that apply to the record are scored (a regular employee is not
        offered regular employment), and only on inputs the caller recorded, so a
        scenario never rests on an imputed value. Each scenario is what the model
        weighs, not a promise of what would happen: the page says so.
        """
        if model.name != "attrition" or model.kind != "classifier" or model.served is not None:
            return [[] for _ in feature_dicts]

        batch: list[dict[str, Any]] = []
        plans: list[list[tuple[str, dict[str, Any]]]] = []
        for feats in feature_dicts:
            plan = [(key, changes) for key, changes in _levers(model, feats) if changes]
            if len(plan) >= 2:
                plan.append(("together", {k: v for _, changes in plan for k, v in changes.items()}))
            plans.append(plan)
            batch.extend({**feats, **changes} for _, changes in plan)

        _, scores = self.score(model, batch) if batch else ([], [])

        out: list[list[dict[str, Any]]] = []
        cursor = 0
        for plan in plans:
            out.append(
                [
                    {"key": key, "changes": changes, "score": scores[cursor + i]}
                    for i, (key, changes) in enumerate(plan)
                ]
            )
            cursor += len(plan)
        return out

    def reference(self, model: LoadedModel) -> dict[str, Any] | None:
        """What every score in a response is read against: for a served model, its own
        account; for the attrition pipeline, the typical employee, the tier cuts and
        the leave rates of the data it learned from."""
        if model.served is not None:
            return model.served.reference() if hasattr(model.served, "reference") else None
        if model.kind != "classifier":
            return None
        typical = {f: v for f, v in _baseline(model).items() if not _is_protected(f)}
        baseline = float(model.pipeline.predict_proba(model.frame([typical]))[:, 1][0])
        reference = {
            "baseline": round(baseline * 100, 1),
            "typical": {
                f: {"value": _plain(v), "band": attrition_evidence.band_label(f, _plain(v))} for f, v in typical.items()
            },
            "tiers": {"medium_from": TIER_CUTS["low_below"] * 100, "high_from": TIER_CUTS["medium_below"] * 100},
            "evidence": model.evidence,
        }
        if model.name == "attrition":
            reference["indicators"] = attrition_indicators.definitions(model.players, local=model.tenant is not None)
        return reference

    def unknown_inputs(self, model: LoadedModel, feature_dicts: list[dict[str, Any]]) -> list[str]:
        """Input names the callers sent that the model does not read — less the
        attrition signals (ADR 0102), which the ERP sends on purpose: only an
        organisation's own model reads them, and every model reads them as readings."""
        known = set(model.features) | (set(SIGNALS) if model.name == "attrition" else set())
        return sorted({key for feats in feature_dicts for key in feats} - known)

    def get(self, name: str) -> LoadedModel:
        if name not in self.models:
            raise KeyError(name)
        return self.models[name]

    def score(self, model: LoadedModel, feature_dicts: list[dict[str, Any]]) -> tuple[list[float | None], list[float]]:
        """Return (probabilities, scores). For regressors probability is None and
        score is the predicted value; for classifiers score is probability×100."""
        df = model.frame(feature_dicts)

        if model.kind == "classifier":
            proba = model.pipeline.predict_proba(df)[:, 1]
            return [float(p) for p in proba], [round(float(p) * 100, 1) for p in proba]

        pred = model.pipeline.predict(df)
        return [None] * len(pred), [round(float(v), 1) for v in pred]

    def explain(self, model: LoadedModel, feature_dicts: list[dict[str, Any]], top: int = 6) -> list[tuple[float | None, list[dict[str, Any]] | None]]:
        """Per instance, the typical record's score (0–100; None when the explanation is
        not additive) and the factors: logit contributions for a linear model, exact
        Shapley values for any other classifier, nothing for the regressor."""
        # The final estimator, whatever its step is named ("clf" / "reg").
        estimator = model.pipeline.steps[-1][1]
        if hasattr(estimator, "coef_"):
            return [(None, f) for f in _linear_contributions(model, estimator, feature_dicts, top)]
        if model.kind == "classifier":
            return _shapley_factors(model, feature_dicts)
        return [(None, None)] * len(feature_dicts)

    def contributions(self, model: LoadedModel, feature_dicts: list[dict[str, Any]], top: int = 6) -> list[list[dict[str, Any]] | None]:
        """Per-instance factors only (see :meth:`explain`)."""
        return [factors for _, factors in self.explain(model, feature_dicts, top)]


def _factor(feature: str, impact: float) -> dict[str, Any]:
    raw = feature.split("__", 1)[-1]
    return {
        "feature": raw,
        "label": _humanize(feature),
        "impact": round(float(impact), 4),
        "direction": "up" if impact >= 0 else "down",
        "group": FEATURE_GROUPS.get(raw),
    }


def _is_protected(feature: str) -> bool:
    raw = feature.split("__", 1)[-1]
    return any(raw == p or raw.startswith(f"{p}_") for p in PROTECTED_FEATURES)


def _linear_contributions(
    model: LoadedModel, estimator: Any, feature_dicts: list[dict[str, Any]], top: int
) -> list[list[dict[str, Any]]]:
    """Each encoded feature's term in the logit (value × coefficient)."""
    prep = model.pipeline.named_steps["prep"]
    encoded = np.asarray(prep.transform(model.frame(feature_dicts)))
    names = prep.get_feature_names_out()
    allowed = [j for j, name in enumerate(names) if not _is_protected(name)]

    results = []
    for row in encoded:
        contrib = row * estimator.coef_[0]
        ranked = sorted(allowed, key=lambda j: abs(contrib[j]), reverse=True)
        results.append([_factor(names[j], contrib[j]) for j in ranked[:top] if abs(contrib[j]) > 1e-9])
    return results


def _shapley_factors(
    model: LoadedModel, feature_dicts: list[dict[str, Any]], with_readings: bool = False
) -> list[tuple[float, list[dict[str, Any]]]] | list[tuple[float, list[dict[str, Any]], list[dict[str, Any]]]]:
    """Model-agnostic explanation for a non-linear classifier, in risk points.

    Each recorded player's exact Shapley value against the typical employee (the
    medians / modes the pipeline's own imputers learned): the typical employee's score
    plus every contribution is this employee's score. A player is an input, or an
    indicator the pipeline builds from several (withdrawal: absences and lateness),
    whose inputs are revealed together. An input the caller did not send is imputed to
    exactly that typical value, so it can never appear as a reason — only recorded
    facts are. Each reason carries the employee's value and band, the typical one, and
    what the training data shows for both; an indicator's value is its level, with the
    inputs it was built from.

    ``with_readings`` adds, per record, its behavioural-indicator readings, each with
    the points of the players it rests on (below the reason floor too: a reading's
    points are its share of the score, however small).
    """
    typical = _baseline(model)
    df = model.frame(feature_dicts)
    rows = df.to_dict("records")
    players = {p: inputs for p, inputs in model.players.items() if all(i in typical for i in inputs)}
    explained = [
        [p for p, inputs in players.items() if not _is_protected(p) and any(_recorded(row.get(i)) for i in inputs)]
        for row in rows
    ]

    def predict(batch: list[dict[str, Any]]) -> np.ndarray:
        return model.pipeline.predict_proba(model.frame(batch))[:, 1] * 100

    results = []
    computed = shapley(rows, explained, typical, predict, groups=players)
    for row, sent, (baseline, contributions) in zip(rows, feature_dicts, computed, strict=True):
        ranked = sorted(contributions.items(), key=lambda kv: abs(kv[1]), reverse=True)
        factors = []
        for player, points in ranked:
            if abs(points) < FACTOR_FLOOR * 100:
                continue
            inputs = players[player]
            if inputs == [player]:
                value, usual = _plain(row[player]), _plain(typical[player])
                detail: dict[str, Any] = {}
            else:
                value = attrition_indicators.level_label(player, row)
                usual = attrition_indicators.level_label(player, typical)
                detail = {
                    "components": {i: _plain(row[i]) for i in inputs if _recorded(row.get(i))},
                    "typical_components": {i: _plain(typical[i]) for i in inputs},
                }
            factors.append(
                {
                    **_factor(player, points / 100),
                    "value": value,
                    "typical": usual,
                    "band": attrition_evidence.band_label(player, value),
                    "typical_band": attrition_evidence.band_label(player, usual),
                    "evidence": attrition_evidence.describe(model.evidence, player, value, usual, points),
                    **detail,
                }
            )
        if with_readings:
            # The record as the caller sent it: overtime is not a model input, but it is
            # what workload strain and effort–reward imbalance are read from.
            record = {**{k: _number(v) for k, v in sent.items() if k in BAND_EDGES or k in SIGNALS}, **row}
            readings = attrition_indicators.readings(record, players, contributions, model.evidence) or None
            results.append((round(baseline, 1), factors, readings))
        else:
            results.append((round(baseline, 1), factors))
    return results


# Employment types that can be moved to regular employment. Part-time is a working
# arrangement, not a step on the way to regular, so it is not offered the change.
_REGULARISABLE = {"probationary", "contractual"}


def _levers(model: LoadedModel, feats: dict[str, Any]) -> list[tuple[str, dict[str, Any]]]:
    """The changes HR could make to one record, as input overrides (empty: not
    applicable). Every lever needs the inputs it changes to be recorded."""
    known = set(model.features)

    def has(*names: str) -> bool:
        return all(name in known and _recorded(feats.get(name)) for name in names)

    levers: list[tuple[str, dict[str, Any]]] = []

    # A promotion now: promoted here, and no wait since.
    if has("years_since_promotion") and not (
        float(feats["years_since_promotion"]) == 0 and float(feats.get("ever_promoted") or 0) > 0
    ):
        changes: dict[str, Any] = {"years_since_promotion": 0.0}
        if "ever_promoted" in known:
            changes["ever_promoted"] = 1
        levers.append(("promotion", changes))

    if has("employment_type") and str(feats["employment_type"]) in _REGULARISABLE:
        levers.append(("regular", {"employment_type": "regular"}))

    # A raise into the next band the model reads pay in (no lever from the top band).
    if has("monthly_salary"):
        edges = BAND_EDGES.get("monthly_salary", [])
        above = [edge for edge in edges if edge > float(feats["monthly_salary"])]
        if above:
            levers.append(("pay_band", {"monthly_salary": float(above[0])}))

    return levers


def _recorded(value: Any) -> bool:
    return value is not None and not (isinstance(value, float) and np.isnan(value))


def _number(value: Any) -> float:
    """A sent value as a number, NaN when it is not one (as the model's frame reads it)."""
    return float(pd.to_numeric(value, errors="coerce")) if value is not None else np.nan


def _plain(value: Any) -> Any:
    """A NumPy scalar as the Python value JSON carries."""
    return value.item() if isinstance(value, np.generic) else value


def _baseline(model: LoadedModel) -> dict[str, Any]:
    """The "typical" value of every input: the medians / modes the pipeline's own
    imputers learned from the training data."""
    baseline: dict[str, Any] = {}
    for _, transformer, columns in model.pipeline.named_steps["prep"].transformers_:
        steps = getattr(transformer, "named_steps", {})
        if "impute" in steps:
            baseline.update(zip(columns, steps["impute"].statistics_, strict=True))
    return baseline
