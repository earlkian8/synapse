"""The performance-forecast model's contract.

A forecast of the *next* appraisal can only use what is known before it: the latest
completed appraisal that closed before the period being forecast. In the reference
that is ``performance_last_year`` predicting ``performance_score`` — so the one input
is shifted by a cycle relative to the promotion model's.

- ``rating_latest`` — attainment (0–100) of the most recent completed appraisal
  before the forecast period: ``performance_evaluations.overall_percent``, read one-for-
  one as the reference's percentage score.

Measured and left out (they add nothing held-out once the latest rating is known):
the rating before it, tenure, time since promotion and certifications. Everything
the old model read from the same appraisal it was forecasting (manager rating, KPI
attainment) is gone: a forecast cannot know them yet.
"""

from __future__ import annotations

import pandas as pd

from ..appraisal.inputs import Input

REQUIRED = ["rating_latest"]

INPUTS = [Input("rating_latest", "Latest appraisal", "%", 40.0, 100.0)]
LABELS = {spec.name: spec.label for spec in INPUTS}

# Bands on the 0–100 scale: the reference's lower and upper quartiles, rounded.
BANDS = (("below", 60.0), ("on_track", 80.0), ("exceeds", float("inf")))

# The forecast interval's coverage: four in five next ratings land inside it.
COVERAGE = 0.80

TARGET = "next_rating"


def band_of(rating: float) -> str:
    for name, below in BANDS:
        if rating < below:
            return name
    return BANDS[-1][0]


def reference_frame(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.Series]:
    """The reference in the contract's units: last year's rating → this year's."""
    X = pd.DataFrame({"rating_latest": df["performance_last_year"].astype(float)})
    return X, df["performance_score"].astype(float).rename(TARGET)
