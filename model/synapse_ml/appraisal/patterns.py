"""One submodel per history pattern — never a guessed value.

Live records are incomplete in a structured way: a new hire has one appraisal, not
three. Filling the gap with the reference median is not neutral — for promotion it
invents a year-on-year *change* nobody observed, and change is the strongest signal
in the data. So instead of imputing, a model is fitted for every combination of the
optional inputs, each on the complete reference data restricted to those columns,
and every record is scored by the submodel that uses exactly what it has. (The
"pattern submodel" approach: Fletcher Mercaldo & Blume, 2020, *Biostatistics* 21(2).)
A record missing a *required* input is not scored at all.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from itertools import combinations
from typing import Any

import numpy as np
import pandas as pd
from sklearn.base import clone

Pattern = tuple[str, ...]


def patterns(optional: Sequence[str]) -> list[Pattern]:
    """Every subset of ``optional``, in a stable order: fewest inputs first."""
    return [subset for k in range(len(optional) + 1) for subset in combinations(optional, k)]


class PatternRouter:
    """Fits ``estimator`` once per pattern of ``optional`` inputs, always with the
    ``required`` ones, and routes each row to the submodel for the inputs it has."""

    def __init__(self, estimator: Any, required: Sequence[str], optional: Sequence[str] = ()) -> None:
        self.estimator = estimator
        self.required = list(required)
        self.optional = list(optional)
        self.submodels: dict[Pattern, Any] = {}

    def fit(self, X: pd.DataFrame, y: Iterable) -> PatternRouter:
        missing = [c for c in [*self.required, *self.optional] if c not in X.columns]
        if missing:
            raise ValueError(f"training frame lacks {missing}")
        if X[[*self.required, *self.optional]].isna().any().any():
            raise ValueError("training frame must be complete; patterns are made by dropping columns")
        y = np.asarray(y)
        self.submodels = {p: clone(self.estimator).fit(X[self.columns(p)], y) for p in patterns(self.optional)}
        return self

    def columns(self, pattern: Pattern) -> list[str]:
        return [*self.required, *pattern]

    def pattern(self, values: Mapping[str, Any]) -> Pattern | None:
        """The pattern a record falls in, or ``None`` when a required input is absent."""
        if any(values.get(c) is None for c in self.required):
            return None
        return tuple(c for c in self.optional if values.get(c) is not None)

    def submodel(self, pattern: Pattern) -> Any:
        return self.submodels[pattern]

    def apply(self, method: str, rows: Sequence[Mapping[str, float]]) -> list[Any]:
        """Call ``method`` on each row's submodel, batched by pattern. A row with no
        pattern (a required input absent) gets ``None``."""
        out: list[Any] = [None] * len(rows)
        groups: dict[Pattern, list[int]] = {}
        for i, row in enumerate(rows):
            p = self.pattern(row)
            if p is not None:
                groups.setdefault(p, []).append(i)
        for p, idx in groups.items():
            cols = self.columns(p)
            frame = pd.DataFrame([[rows[i][c] for c in cols] for i in idx], columns=cols, dtype=float)
            result = getattr(self.submodels[p], method)(frame)
            for j, i in enumerate(idx):
                out[i] = result[j]
        return out
