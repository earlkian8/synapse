"""Small, model-agnostic helpers for tabular data."""

from __future__ import annotations


def split_feature_types(df, target: str, drop: list[str] | None = None) -> tuple[list[str], list[str]]:
    """Return ``(numeric_cols, categorical_cols)`` for every column except the target and
    any explicitly dropped identifiers."""
    excluded = set(drop or []) | {target}
    numeric, categorical = [], []
    for col in df.columns:
        if col in excluded:
            continue
        if str(df[col].dtype) in ("object", "category", "bool"):
            categorical.append(col)
        else:
            numeric.append(col)
    return numeric, categorical
