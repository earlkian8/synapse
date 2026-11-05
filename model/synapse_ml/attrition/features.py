"""The attrition model's feature contract — what it takes, in what units, and how each
value is banded.

Inputs arrive in the units the ERP records (years, pesos per month, hours, days,
times). The pipeline bands them with the survey's own edges, so an employee's exact
figures land in exactly the band they would have ticked on the survey. That is what
makes a model trained on survey answers servable from live HR data.

Two of the inputs are not read on their own. Absences and late arrivals are combined
into **withdrawal**, a behavioural indicator of emotional disengagement (``INDICATORS``):
people who are pulling away from a job tend to show it first in lateness and
absence, before they quit (the withdrawal progression; Rosse, 1988; Hanisch & Hulin,
1990, 1991; Berry, Lelchook & Clark, 2012). The pipeline builds it from the same
bands, so the ERP still sends the raw counts and never computes an indicator itself.
Notebook §5 compares it with the other indicators the survey can support.
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
    "withdrawal": "Withdrawal (absences and lateness)",
}

# Behavioural indicators the pipeline derives from the inputs: indicator -> its inputs.
# Each is read as one input, so it is also one reason in an explanation. Withdrawal
# averages the standardised absence and lateness bands with equal weights: the two
# move together (Spearman 0.63 in the survey), and their most frequent bands are where
# the share who left climbs (notebook §2). Read on their own, as two inputs, they gave
# the model about 0.01 less ROC-AUC on unseen splits and in nested CV (notebook §5).
INDICATORS: dict[str, list[str]] = {"withdrawal": ["absences_90d", "lates_90d"]}

# What kind of fact each reason is, for the screens that group them.
FEATURE_GROUPS = {
    "employment_type": "structural",
    "tenure_years": "structural",
    "monthly_salary": "compensation",
    "years_since_promotion": "career",
    "ever_promoted": "career",
    "withdrawal": "emotional",
    "absences_90d": "emotional",
    "lates_90d": "emotional",
}
GROUP_LABELS = {
    "structural": "Structural",
    "compensation": "Compensation",
    "career": "Career",
    "emotional": "Emotional (behavioural indicator)",
}

# Recorded by the survey and sent by the ERP, but not a model input. Overtime's leave
# rate is flat across its bands, and as an input it only adds noise: the chosen model
# loses about 0.02 ROC-AUC with it (notebook §5). The ERP may keep sending it — the
# service reads only ``FEATURES``.
EXCLUDED = {"overtime_hours_90d": "no signal: the share who left is flat across its bands"}

CATEGORICAL_FEATURES = ["employment_type"]
RECORDED_FEATURES = list(BAND_EDGES) + CATEGORICAL_FEATURES  # every encoded survey answer
NUMERIC_FEATURES = [f for f in BAND_EDGES if f not in EXCLUDED]
FEATURES = NUMERIC_FEATURES + CATEGORICAL_FEATURES  # the model's inputs, as the ERP sends them
INDICATOR_INPUTS = [f for inputs in INDICATORS.values() for f in inputs]
DIRECT_FEATURES = [f for f in NUMERIC_FEATURES if f not in INDICATOR_INPUTS]  # numbers read as they are

# Tier cut-points on the probability scale (the inference service applies the same).
TIER_CUTS = {"low_below": 0.33, "medium_below": 0.66}


def band_of(feature: str, value: float) -> int:
    """The band a raw value falls in — what the pipeline's banding step computes."""
    return int(np.digitize([value], BAND_EDGES[feature])[0])


def preprocessor(scale: bool = False, numeric: list[str] | None = None, indicators: bool = True,
                 extra: list[str] | None = None):
    """The preprocessing every candidate model shares.

    Numeric: impute the median (in the survey only salary is ever missing — "prefer not
    to say"; in an organisation's own records, attendance that was never tracked),
    band each column with its own edges, optionally standardise for linear models.
    Indicators (``INDICATORS``): impute and band their inputs the same way, standardise
    the bands and average them into one column. Categorical: one-hot, ignoring values
    not seen in training.

    ``numeric`` overrides the numeric inputs (the notebook's ablation puts overtime
    back); ``indicators=False`` reads every numeric input on its own, as the model did
    before withdrawal; ``extra`` names already-numeric columns a candidate adds
    (notebook §5), imputed and passed through. Built from stock scikit-learn/NumPy
    callables only, so a fitted pipeline unpickles anywhere without importing this
    package. The branch names (``num``, one per indicator, ``cat``) are part of the
    serving contract: the inference service reads an indicator's branch as one input.
    """
    from sklearn.compose import ColumnTransformer
    from sklearn.impute import SimpleImputer
    from sklearn.pipeline import Pipeline
    from sklearn.preprocessing import FunctionTransformer, OneHotEncoder, StandardScaler

    numeric = NUMERIC_FEATURES if numeric is None else numeric
    derived = {name: inputs for name, inputs in INDICATORS.items() if indicators and set(inputs) <= set(numeric)}
    direct = [f for f in numeric if not any(f in inputs for inputs in derived.values())]

    def banding(names: list[str]):
        return ColumnTransformer(
            [
                (name, FunctionTransformer(np.digitize, kw_args={"bins": np.asarray(BAND_EDGES[name])}, feature_names_out="one-to-one"), [i])
                for i, name in enumerate(names)
            ],
            verbose_feature_names_out=False,
        )

    # ``keep_empty_features``: a column nobody recorded (an organisation that tracks no
    # attendance) stays in place as a constant rather than being dropped, which would
    # shift every column after it out from under its band edges.
    def impute(strategy: str = "median"):
        return SimpleImputer(strategy=strategy, keep_empty_features=True)

    numeric_steps = [("impute", impute()), ("band", banding(direct))]
    if scale:
        numeric_steps.append(("scale", StandardScaler()))
    branches = [("num", Pipeline(numeric_steps), direct)]

    for name, inputs in derived.items():
        steps = [
            ("impute", impute()),
            ("band", banding(inputs)),
            ("standardise", StandardScaler()),
            ("combine", FunctionTransformer(np.mean, kw_args={"axis": 1, "keepdims": True})),
        ]
        if scale:
            steps.append(("scale", StandardScaler()))
        branches.append((name, Pipeline(steps), inputs))

    if extra:
        extra_steps = [("impute", impute())] + ([("scale", StandardScaler())] if scale else [])
        branches.append(("extra", Pipeline(extra_steps), extra))

    categorical_steps = [("impute", impute("most_frequent")), ("ohe", OneHotEncoder(handle_unknown="ignore", sparse_output=False))]
    branches.append(("cat", Pipeline(categorical_steps), CATEGORICAL_FEATURES))
    return ColumnTransformer(branches)


def feature_contract(X) -> dict:
    """The serving contract written next to the model (``feature_contract.json``)."""
    return {
        "features": FEATURES,
        "numeric": NUMERIC_FEATURES,
        "categorical": CATEGORICAL_FEATURES,
        "categorical_levels": {col: sorted(X[col].dropna().unique().tolist()) for col in CATEGORICAL_FEATURES},
        "excluded": EXCLUDED,
        "indicators": {name: {"inputs": inputs, "combined": "mean of the standardised bands, equal weights"}
                       for name, inputs in INDICATORS.items()},
        "groups": FEATURE_GROUPS,
        "band_edges": BAND_EDGES,
        "band_labels": BAND_LABELS,
        "labels": FEATURE_LABELS,
        "tiers": TIER_CUTS,
        "attendance_window_days": 90,
    }
