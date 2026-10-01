"""The attrition model's feature contract — what it takes, in what units, and how each
value is banded.

Inputs arrive in the units the ERP records (years, pesos per month, hours, days,
times). The pipeline bands them with the survey's own edges, so an employee's exact
figures land in exactly the band they would have ticked on the survey. That is what
makes a model trained on survey answers servable from live HR data.
"""

from __future__ import annotations

import numpy as np

# Band edges in each feature's own unit. ``np.digitize(value, edges)`` returns the band:
# ``edges[i-1] <= value < edges[i]``. Durations follow how people state them (whole
# years completed: 2.9 years is "1 to 2 years"); counts put their edge half-way between
# whole numbers, so 10 overtime hours is still "1 to 10" and a stray 20 minutes is "None".
BAND_EDGES: dict[str, list[float]] = {
    "tenure_years": [1.0, 3.0, 6.0, 11.0],
    "years_since_promotion": [1.0, 3.0, 6.0],
    "ever_promoted": [0.5],
    "monthly_salary": [15_000.0, 25_000.0, 40_000.0, 60_000.0],
    "overtime_hours_90d": [0.5, 10.5, 25.5, 50.5, 100.5],
    "absences_90d": [0.5, 2.5, 5.5, 10.5],
    "lates_90d": [0.5, 2.5, 5.5, 10.5],
}

# A human label for each band, in order.
BAND_LABELS: dict[str, list[str]] = {
    "tenure_years": ["< 1 yr", "1–2 yrs", "3–5 yrs", "6–10 yrs", "> 10 yrs"],
    "years_since_promotion": ["< 1 yr", "1–2 yrs", "3–5 yrs", "> 5 yrs"],
    "ever_promoted": ["never", "yes"],
    "monthly_salary": ["< ₱15k", "₱15k–25k", "₱25k–40k", "₱40k–60k", "₱60k+"],
    "overtime_hours_90d": ["none", "1–10 h", "11–25 h", "26–50 h", "51–100 h", "> 100 h"],
    "absences_90d": ["none", "1–2 days", "3–5 days", "6–10 days", "> 10 days"],
    "lates_90d": ["none", "1–2 times", "3–5 times", "6–10 times", "> 10 times"],
}

FEATURE_LABELS = {
    "tenure_years": "Tenure",
    "years_since_promotion": "Time since last promotion",
    "ever_promoted": "Ever promoted",
    "monthly_salary": "Monthly salary",
    "overtime_hours_90d": "Overtime (last 90 days)",
    "absences_90d": "Absences (last 90 days)",
    "lates_90d": "Late arrivals (last 90 days)",
    "employment_type": "Employment type",
}

NUMERIC_FEATURES = list(BAND_EDGES)
CATEGORICAL_FEATURES = ["employment_type"]
FEATURES = NUMERIC_FEATURES + CATEGORICAL_FEATURES

# Tier cut-points on the probability scale (the inference service applies the same).
TIER_CUTS = {"low_below": 0.33, "medium_below": 0.66}


def band_of(feature: str, value: float) -> int:
    """The band a raw value falls in — what the pipeline's banding step computes."""
    return int(np.digitize([value], BAND_EDGES[feature])[0])


def preprocessor(scale: bool = False):
    """The preprocessing every candidate model shares.

    Numeric: impute the median (in the survey only salary is ever missing — "prefer not
    to say"; in an organisation's own records, attendance that was never tracked),
    band each column with its own edges, optionally standardise for linear models.
    Categorical: one-hot, ignoring values not seen in training. Built from stock
    scikit-learn/NumPy callables only, so a fitted pipeline unpickles anywhere without
    importing this package.
    """
    from sklearn.compose import ColumnTransformer
    from sklearn.impute import SimpleImputer
    from sklearn.pipeline import Pipeline
    from sklearn.preprocessing import FunctionTransformer, OneHotEncoder, StandardScaler

    banding = ColumnTransformer(
        [
            (name, FunctionTransformer(np.digitize, kw_args={"bins": np.asarray(edges)}, feature_names_out="one-to-one"), [i])
            for i, (name, edges) in enumerate(BAND_EDGES.items())
        ],
        verbose_feature_names_out=False,
    )
    # ``keep_empty_features``: a column nobody recorded (an organisation that tracks no
    # attendance) stays in place as a constant rather than being dropped, which would
    # shift every column after it out from under its band edges.
    numeric_steps = [("impute", SimpleImputer(strategy="median", keep_empty_features=True)), ("band", banding)]
    if scale:
        numeric_steps.append(("scale", StandardScaler()))

    categorical_steps = [
        ("impute", SimpleImputer(strategy="most_frequent", keep_empty_features=True)),
        ("ohe", OneHotEncoder(handle_unknown="ignore", sparse_output=False)),
    ]
    return ColumnTransformer(
        [
            ("num", Pipeline(numeric_steps), NUMERIC_FEATURES),
            ("cat", Pipeline(categorical_steps), CATEGORICAL_FEATURES),
        ]
    )


def feature_contract(X) -> dict:
    """The serving contract written next to the model (``feature_contract.json``)."""
    return {
        "features": FEATURES,
        "numeric": NUMERIC_FEATURES,
        "categorical": CATEGORICAL_FEATURES,
        "categorical_levels": {col: sorted(X[col].dropna().unique().tolist()) for col in CATEGORICAL_FEATURES},
        "band_edges": BAND_EDGES,
        "band_labels": BAND_LABELS,
        "labels": FEATURE_LABELS,
        "tiers": TIER_CUTS,
        "attendance_window_days": 90,
    }
