"""The reference workforce: ``employee_promotion_prediction.csv``.

A general 100,000-row workforce dataset with three years of performance history per
person and whether they were promoted. It is the only labelled source either model
has until an organisation records its own outcomes, so it is loaded through one
function that refuses a file it does not recognise rather than training on it.

Most of its 40 columns have no ERP counterpart (peer feedback, innovation scores,
stock options, ...). The models use only the few that do; see each model's
``features`` module for the contract.
"""

from __future__ import annotations

from pathlib import Path

import pandas as pd

from ..paths import RAW_DATA_DIR

SOURCE = RAW_DATA_DIR / "employee_promotion_prediction.csv"

# The columns either model reads, and the range each must lie in. Anything else in the
# file is ignored.
COLUMNS: dict[str, tuple[float, float]] = {
    "employee_id": (1, float("inf")),
    "performance_score": (0, 100),
    "performance_last_year": (0, 100),
    "performance_two_years_ago": (0, 100),
    "years_at_company": (0, 60),
    "years_since_last_promotion": (0, 60),
    "promoted": (0, 1),
}


class ReferenceDataError(ValueError):
    """The reference file is not the dataset the models were designed around."""


def load(path: Path | str = SOURCE) -> pd.DataFrame:
    """Read and validate the reference dataset.

    Raises :class:`ReferenceDataError` when a column is missing, holds a value outside
    its range or a missing value, when ``promoted`` is not 0/1, or when an employee id
    repeats — each of which would silently change what the models learn.
    """
    df = pd.read_csv(path)

    missing = [column for column in COLUMNS if column not in df.columns]
    if missing:
        raise ReferenceDataError(f"{Path(path).name} is missing columns: {', '.join(missing)}")

    problems = []
    for column, (low, high) in COLUMNS.items():
        values = pd.to_numeric(df[column], errors="coerce")
        if values.isna().any():
            problems.append(f"{column} has {int(values.isna().sum())} missing or non-numeric values")
        elif ((values < low) | (values > high)).any():
            problems.append(f"{column} has values outside {low:g}–{high:g}")

    if not set(df["promoted"].unique()) <= {0, 1}:
        problems.append("promoted is not 0/1")
    if not df["employee_id"].is_unique:
        problems.append("employee_id repeats")
    if problems:
        raise ReferenceDataError(f"{Path(path).name}: " + "; ".join(problems))

    return df
