"""Why a model scored a record the way it did, in the units its score is read in.

Every served model explains a score the same way: by **exact Shapley values** against a
*typical* record (the reference's medians / modes — what the pipeline's own imputers
learned, or the served model's ``typical``). Each recorded input gets the share of
the gap between the typical record's score and this record's score that it is
responsible for, averaged over every order the inputs could be revealed in (Lundberg
& Lee, 2017; with a single reference point this is "baseline Shapley", Sundararajan &
Najmi, 2020). Two properties make it the right tool for an HR screen:

- **They add up.** ``typical score + Σ contributions = this score`` exactly, so the
  page can draw the score as a walk from the typical record to this one and every
  step is the model's own arithmetic, not an approximation.
- **Only recorded facts move it.** An input the record does not carry is imputed to
  the typical value, and an input equal to the typical value contributes exactly
  zero, so nothing that was not observed is ever offered as a reason.

The models here read at most seven inputs, so the exact sum over every coalition
(2^7 = 128 rows per record) is cheap — one batched ``predict`` call for a whole run.

A **player** can stand for several inputs (``groups``): when a model reads two inputs
only as one derived value — attrition's withdrawal, built from absences and lateness —
they are revealed together, so the reason is the derived value's and the two are never
split into halves of a whole that the model never reads apart.
"""

from __future__ import annotations

from collections.abc import Callable, Mapping, Sequence
from math import factorial
from typing import Any

import numpy as np

# The exact computation evaluates 2^n coalitions per record; past this many inputs
# it would need sampling, which no model here does.
MAX_INPUTS = 10


def _weights(n: int) -> list[float]:
    """Shapley weight of a coalition of size k (excluding the input itself) among n."""
    return [factorial(k) * factorial(n - k - 1) / factorial(n) for k in range(n)]


def shapley(
    rows: Sequence[Mapping[str, Any]],
    explained: Sequence[Sequence[str]],
    typical: Mapping[str, Any],
    predict: Callable[[list[dict[str, Any]]], Sequence[float]],
    groups: Mapping[str, Sequence[str]] | None = None,
) -> list[tuple[float, dict[str, float]]]:
    """Exact baseline Shapley values for each row.

    ``explained[i]`` names the players to attribute for ``rows[i]`` (its recorded
    ones); every other key is passed through unchanged. A player is an input, or a name
    in ``groups`` standing for the inputs it lists, which are set to their typical
    values together. ``predict`` maps a list of records to scores on the scale the
    explanation is read in. Returns, per row, the typical record's score and each
    explained player's contribution; they sum to the row's own score.
    """
    groups = groups or {}
    batch: list[dict[str, Any]] = []
    starts: list[int] = []
    for row, names in zip(rows, explained, strict=True):
        if len(names) > MAX_INPUTS:
            raise ValueError(f"exact Shapley values need at most {MAX_INPUTS} inputs, got {len(names)}")
        starts.append(len(batch))
        for mask in range(1 << len(names)):
            hybrid = dict(row)
            for j, name in enumerate(names):
                if not (mask >> j) & 1:
                    for field in groups.get(name, (name,)):
                        hybrid[field] = typical[field]
            batch.append(hybrid)

    values = np.asarray(predict(batch), dtype=float) if batch else np.array([])

    out: list[tuple[float, dict[str, float]]] = []
    for names, start in zip(explained, starts, strict=True):
        n = len(names)
        v = values[start : start + (1 << n)]
        weights = _weights(n) if n else []
        contributions: dict[str, float] = {}
        for j, name in enumerate(names):
            bit = 1 << j
            total = 0.0
            for mask in range(1 << n):
                if mask & bit:
                    continue
                total += weights[mask.bit_count()] * (v[mask | bit] - v[mask])
            contributions[name] = float(total)
        out.append((float(v[0]), contributions))
    return out
