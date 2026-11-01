"""What the attrition model's data shows, answer by answer — the evidence an HR reader
can check a reason against.

For every model input, the share of people in each band who left: "of the 15 people
surveyed who had been absent 6–10 days in their last three months, 8 left (53%),
against 28% overall". These are plain counts from the rows the model learned from,
not model output, so they say what the data holds even where the model reads an
answer in combination with others.

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
from .features import BAND_LABELS, CATEGORICAL_FEATURES, FEATURES, band_of

EVIDENCE_FILE = ARTIFACTS_DIR / "attrition" / "evidence.json"

# A band's leave rate must differ from the typical band's by this much before it is
# read as pointing one way or the other (below it, "on its own, about the same").
MEANINGFUL_GAP = 0.03


def band_label(feature: str, value: Any) -> str | None:
    """The band a value falls in, as the survey worded it (categories as they are)."""
    if value is None or (isinstance(value, float) and math.isnan(value)):
        return None
    if feature in CATEGORICAL_FEATURES:
        return str(value)
    return BAND_LABELS[feature][band_of(feature, float(value))]


def profile(X: pd.DataFrame, y, source: str) -> dict[str, Any]:
    """Leave rates by band for every model input. ``source`` names whose rows they
    are: ``"survey"`` (the reference) or ``"own_records"`` (an organisation's)."""
    left = np.asarray(y, dtype=int)
    table: dict[str, list[dict[str, Any]]] = {}
    for feature in FEATURES:
        if feature not in X.columns:
            continue
        labels = X[feature].map(lambda v, f=feature: band_label(f, v))
        order = (
            sorted(labels.dropna().unique())
            if feature in CATEGORICAL_FEATURES
            else [b for b in BAND_LABELS[feature] if b in set(labels.dropna())]
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
    }


def _band(evidence: Mapping[str, Any], feature: str, band: str | None) -> Mapping[str, Any] | None:
    if band is None:
        return None
    return next((row for row in evidence.get("features", {}).get(feature, []) if row["band"] == band), None)


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


def main() -> None:
    from . import survey

    tidy, _ = survey.load()
    table = profile(tidy[FEATURES], tidy[survey.TARGET], source="survey")
    EVIDENCE_FILE.parent.mkdir(parents=True, exist_ok=True)
    EVIDENCE_FILE.write_text(json.dumps(table, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"wrote {EVIDENCE_FILE} ({table['people']} people, {table['left']} left)")


if __name__ == "__main__":
    main()
