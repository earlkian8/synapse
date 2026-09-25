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
  a linear model and what-if-typical deltas for any other classifier.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any

import joblib
import numpy as np
import pandas as pd

from synapse_ml.attrition.features import FEATURE_LABELS as ATTRITION_FEATURE_LABELS
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

# Occlusion effects smaller than this (two risk points) are not offered as a reason.
# On the attrition forest real drivers move a score by 10–35 points; below ~2 the
# effect is the forest's own noise and reads as contradiction, not insight.
OCCLUSION_FLOOR = 0.02

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

    @property
    def version(self) -> str | None:
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


class Registry:
    def __init__(self) -> None:
        self.models: dict[str, LoadedModel] = {}

    def load(self) -> None:
        for name, kind in _SPECS.items():
            path = ARTIFACTS_DIR / name / f"{name}_model.joblib"
            if not path.exists():
                continue

            artifact = joblib.load(path)
            metrics_path = ARTIFACTS_DIR / name / "metrics.json"
            metrics = json.loads(metrics_path.read_text(encoding="utf-8")) if metrics_path.exists() else {}

            if hasattr(artifact, "assess"):
                self.models[name] = LoadedModel(
                    name=name,
                    kind=artifact.kind,
                    pipeline=None,
                    numeric=list(artifact.features),
                    categorical=[],
                    features=list(artifact.features),
                    metrics=metrics,
                    served=artifact,
                )
                continue

            prep = artifact.named_steps["prep"]
            self.models[name] = LoadedModel(
                name=name,
                kind=kind,
                pipeline=artifact,
                numeric=list(prep.transformers_[0][2]),
                categorical=list(prep.transformers_[1][2]),
                features=list(prep.feature_names_in_),
                metrics=metrics,
            )

    def predict(self, name: str, feature_dicts: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """One result dict per instance, whatever the kind of artifact."""
        model = self.get(name)
        if model.served is not None:
            return model.served.assess(feature_dicts)

        probabilities, scores = self.score(name, feature_dicts)
        factor_sets = self.contributions(name, feature_dicts)
        return [
            {
                "status": "scored",
                "probability": proba,
                "score": score,
                "tier": tier_for(proba) if proba is not None else None,
                "factors": factors or None,
            }
            for proba, score, factors in zip(probabilities, scores, factor_sets, strict=True)
        ]

    def unknown_inputs(self, name: str, feature_dicts: list[dict[str, Any]]) -> list[str]:
        """Input names the callers sent that the model does not read."""
        known = set(self.get(name).features)
        return sorted({key for feats in feature_dicts for key in feats} - known)

    def get(self, name: str) -> LoadedModel:
        if name not in self.models:
            raise KeyError(name)
        return self.models[name]

    def score(self, name: str, feature_dicts: list[dict[str, Any]]) -> tuple[list[float | None], list[float]]:
        """Return (probabilities, scores). For regressors probability is None and
        score is the predicted value; for classifiers score is probability×100."""
        model = self.get(name)
        df = model.frame(feature_dicts)

        if model.kind == "classifier":
            proba = model.pipeline.predict_proba(df)[:, 1]
            return [float(p) for p in proba], [round(float(p) * 100, 1) for p in proba]

        pred = model.pipeline.predict(df)
        return [None] * len(pred), [round(float(v), 1) for v in pred]

    def contributions(self, name: str, feature_dicts: list[dict[str, Any]], top: int = 6) -> list[list[dict[str, Any]] | None]:
        """Per-instance explanations: logit contributions for a linear model, what-if-
        typical deltas for any other classifier, None for the regressor."""
        model = self.get(name)
        # The final estimator, whatever its step is named ("clf" / "reg").
        estimator = model.pipeline.steps[-1][1]
        if hasattr(estimator, "coef_"):
            return _linear_contributions(model, estimator, feature_dicts, top)
        if model.kind == "classifier":
            return _occlusion(model, feature_dicts, top)
        return [None] * len(feature_dicts)


def _factor(feature: str, impact: float) -> dict[str, Any]:
    return {
        "feature": feature.split("__", 1)[-1],
        "label": _humanize(feature),
        "impact": round(float(impact), 4),
        "direction": "up" if impact >= 0 else "down",
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


def _occlusion(model: LoadedModel, feature_dicts: list[dict[str, Any]], top: int) -> list[list[dict[str, Any]]]:
    """Model-agnostic explanation for a non-linear classifier.

    For each input, the change in probability if that one input were *typical* (the
    median / mode the pipeline's own imputers learned) instead of this employee's
    value: positive means the employee's value pushes the probability up. An input
    the caller did not send is imputed to exactly that typical value, so it can
    never appear as a driver — only recorded facts are ever offered as a reason.
    """
    baseline = _baseline(model)
    df = model.frame(feature_dicts)
    base = model.pipeline.predict_proba(df)[:, 1]

    explained = [f for f in model.features if f in baseline and not _is_protected(f)]
    deltas = np.zeros((len(df), len(explained)))
    for j, feature in enumerate(explained):
        typical = df.copy()
        typical[feature] = baseline[feature]
        deltas[:, j] = base - model.pipeline.predict_proba(typical)[:, 1]

    results = []
    for row in deltas:
        ranked = sorted(range(len(explained)), key=lambda j: abs(row[j]), reverse=True)
        results.append([_factor(explained[j], row[j]) for j in ranked[:top] if abs(row[j]) >= OCCLUSION_FLOOR])
    return results


def _baseline(model: LoadedModel) -> dict[str, Any]:
    """The "typical" value of every input: the medians / modes the pipeline's own
    imputers learned from the training data."""
    baseline: dict[str, Any] = {}
    for _, transformer, columns in model.pipeline.named_steps["prep"].transformers_:
        steps = getattr(transformer, "named_steps", {})
        if "impute" in steps:
            baseline.update(zip(columns, steps["impute"].statistics_, strict=True))
    return baseline
