"""Reading one live record against a model's input contract.

The ERP sends a dict per employee. Every value is read the same way, whatever the
model: a number is a number; a missing, blank, non-finite or non-numeric value is
*absent* (never zero, never a guess); a number outside what the reference workforce
covers is held at the nearest edge and the record says so, because a model says
nothing trustworthy about a region it never saw.
"""

from __future__ import annotations

import math
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class Input:
    """One model input, in the unit the ERP records it."""

    name: str
    label: str
    unit: str
    low: float   # the reference workforce's range; values beyond it are held at the edge
    high: float

    def describe(self) -> dict[str, Any]:
        return {"label": self.label, "unit": self.unit, "range": [self.low, self.high]}


def number(value: Any) -> float | None:
    """``value`` as a finite float, or ``None`` when it is not one."""
    if value is None or isinstance(value, bool):
        return None
    try:
        result = float(value)
    except (TypeError, ValueError):
        return None
    return result if math.isfinite(result) else None


def read(record: Mapping[str, Any], inputs: Sequence[Input]) -> tuple[dict[str, float], list[str]]:
    """The record's usable values, and a note for each value that had to be adjusted."""
    values: dict[str, float] = {}
    notes: list[str] = []
    for spec in inputs:
        raw = record.get(spec.name)
        value = number(raw)
        if value is None:
            if raw is not None and not (isinstance(raw, str) and raw.strip() == ""):
                notes.append(f"{spec.label} was not a number and was left out.")
            continue
        if value < spec.low or value > spec.high:
            held = min(max(value, spec.low), spec.high)
            notes.append(
                f"{spec.label} of {_fmt(value)}{spec.unit} is outside the range the model was "
                f"trained on ({_fmt(spec.low)}–{_fmt(spec.high)}{spec.unit}); read as {_fmt(held)}{spec.unit}."
            )
            value = held
        values[spec.name] = value
    return values, notes


def fitted_ranges(inputs: Sequence[Input], X) -> list[Input]:
    """``inputs`` with each range replaced by the span of the values in ``X`` — the
    range a model fitted on those rows has actually seen."""
    return [
        Input(spec.name, spec.label, spec.unit, float(X[spec.name].min()), float(X[spec.name].max()))
        for spec in inputs
    ]


def unknown(record: Mapping[str, Any], inputs: Sequence[Input]) -> list[str]:
    """Keys the record carries that the contract does not know — a sign the caller and
    the model disagree about the contract."""
    known = {spec.name for spec in inputs}
    return sorted(key for key in record if key not in known)


def _fmt(value: float) -> str:
    return f"{value:.1f}".rstrip("0").rstrip(".")
