"""What the attrition model's data shows, answer by answer — the evidence an HR reader
can check a reason against.

For every model input, the share of people in each band who left: "of the 15 people
surveyed who had been absent 6–10 days in their last three months, 8 left (53%),
against 28% overall". These are plain counts from the rows the model learned from,
not model output, so they say what the data holds even where the model reads an
answer in combination with others.

The same is counted for each behavioural indicator, by level
(:mod:`synapse_ml.attrition.indicators`): "of the 27 people surveyed whose absences and
lateness together read as high withdrawal, 14 left (52%)".

``profile`` builds the table from any training frame — the survey for the reference
model, an organisation's own records for its local one (``synapse_ml.local``) — and
``describe`` reads one employee's value against it. ``python -m
synapse_ml.attrition.evidence`` writes the reference table next to the served model
(``artifacts/attrition/evidence.json``). Only aggregates are written: no row, answer
pattern or reference number leaves the training data.
"""

from __future__ import annotations

import json
import math
from collections.abc import Mapping
from typing import Any

import numpy as np
import pandas as pd

from ..paths import ARTIFACTS_DIR
from . import indicators as behavioural
from .features import (
    BAND_LABELS,
    CATEGORICAL_FEATURES,
    FEATURES,
    INDICATORS,
    RECORDED_FEATURES,
    SIGNAL_EDGES,
    SIGNAL_LABELS,
    SIGNALS,
    band_of,
)

EVIDENCE_FILE = ARTIFACTS_DIR / "attrition" / "evidence.json"

# A band's leave rate must differ from the typical band's by this much before it is
# read as pointing one way or the other (below it, "on its own, about the same").
MEANINGFUL_GAP = 0.03


def band_label(feature: str, value: Any) -> str | None:
    """The band a value falls in, as the survey worded it (categories as they are)."""
    if value is None or (isinstance(value, float) and math.isnan(value)):
        return None
    if feature in CATEGORICAL_FEATURES or feature in INDICATORS:  # an indicator's value is its level
        return str(value)
    if feature in SIGNAL_EDGES:
        return SIGNAL_LABELS[feature][int(np.digitize([float(value)], SIGNAL_EDGES[feature])[0])]
    return BAND_LABELS[feature][band_of(feature, float(value))]


def profile(X: pd.DataFrame, y, source: str) -> dict[str, Any]:
    """Leave rates by band for every model input, and by level for every behavioural
    indicator ``X`` holds the inputs of (overtime is not a model input, so pass it in
    for workload strain and effort–reward imbalance). ``source`` names whose rows they
    are: ``"survey"`` (the reference) or ``"own_records"`` (an organisation's)."""
    left = np.asarray(y, dtype=int)
    table: dict[str, list[dict[str, Any]]] = {}
    # The signals only an organisation's own records hold (ADR 0102), where they do.
    for feature in FEATURES + [s for s in SIGNALS if s in X.columns and X[s].notna().any()]:
        if feature not in X.columns:
            continue
        labels = X[feature].map(lambda v, f=feature: band_label(f, v))
        order = (
            sorted(labels.dropna().unique())
            if feature in CATEGORICAL_FEATURES
            else [b for b in (SIGNAL_LABELS.get(feature) or BAND_LABELS[feature]) if b in set(labels.dropna())]
        )
        rows = []
        for band in order:
            mask = (labels == band).to_numpy()
            people = int(mask.sum())
            leavers = int(left[mask].sum())
            rows.append({"band": band, "people": people, "left": leavers, "rate": round(leavers / people, 4)})
        table[feature] = rows
    return {
        "source": source,
        "people": int(len(left)),
        "left": int(left.sum()),
        "overall_rate": round(float(left.mean()), 4) if len(left) else None,
        "features": table,
        "indicators": behavioural.profile(X, left),
    }


def _band(evidence: Mapping[str, Any], feature: str, band: str | None) -> Mapping[str, Any] | None:
    if band is None:
        return None
    rows = evidence.get("features", {}).get(feature) or (evidence.get("indicators") or {}).get(feature, [])
    return next((row for row in rows if row["band"] == band), None)


def describe(evidence: Mapping[str, Any] | None, feature: str, value: Any, typical: Any, contribution: float) -> dict[str, Any] | None:
    """An employee's band against the typical one, in the data's own leave rates, and
    whether the data on its own points the way the model's contribution does
    (``consistent``: True, False, or None when the two bands leave at about the same
    rate — the model then reads the answer together with others)."""
    if not evidence:
        return None
    own = _band(evidence, feature, band_label(feature, value))
    usual = _band(evidence, feature, band_label(feature, typical))
    if own is None:
        return None

    consistent = None
    if usual is not None and abs(own["rate"] - usual["rate"]) >= MEANINGFUL_GAP and contribution != 0:
        consistent = (own["rate"] > usual["rate"]) == (contribution > 0)

    return {
        "rate": own["rate"],
        "people": own["people"],
        "typical_rate": usual["rate"] if usual else None,
        "typical_people": usual["people"] if usual else None,
        "overall_rate": evidence.get("overall_rate"),
        "consistent": consistent,
    }


def load(path=EVIDENCE_FILE) -> dict[str, Any] | None:
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else None


def write(tidy: pd.DataFrame, target: str, path=EVIDENCE_FILE) -> dict[str, Any]:
    """Write the reference table for the survey rows in ``tidy`` (the notebook does,
    beside the model it fits on the same rows)."""
    table = profile(tidy[RECORDED_FEATURES], tidy[target], source="survey")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(table, indent=2, ensure_ascii=False), encoding="utf-8")
    return table


def main() -> None:
    from . import survey

    tidy, _ = survey.load()
    table = write(tidy, survey.TARGET)
    print(f"wrote {EVIDENCE_FILE} ({table['people']} people, {table['left']} left)")


if __name__ == "__main__":
    main()
