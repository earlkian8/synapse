"""The promotion-readiness model's contract: what it reads, in which unit, and how the
reference workforce is expressed in those units.

Every input is something the ERP records:

- ``rating_latest`` — attainment (0–100) of the employee's most recent *completed*
  appraisal: ``performance_evaluations.overall_percent``, the figure ADR 0028 makes
  comparable across appraisal frameworks. It is read as the reference's
  ``performance_score`` one-for-one: both are percentage attainment.
- ``rating_change`` — that attainment minus the previous completed appraisal's. In the
  reference, change is the strongest signal of all: with no improvement almost nobody
  is promoted at any level, with ten points or more a quarter to a half are.

Deliberately absent: department (its effect in the reference is large — +0.08
ROC-AUC — but it is a property of that dataset's departments, whose names are not the
tenant's, not of anyone's readiness), salary (a different currency and period),
employment type (no effect, and no part-time category), and every demographic
attribute. Approved overtime adds 0.008, the most of any recorded field, and is left
out too: overtime depends on role and policy (exempt staff record none), and a
readiness score that rises with hours worked penalises part-time staff and anyone
with caring responsibilities.

Measured and left out: time since the last promotion. It adds 0.002 ROC-AUC, and
only because in the reference the *recently* promoted are promoted again more often
— backwards from any real time-in-grade practice, so an artefact of that dataset
rather than something to carry into a tenant's decisions, and an explanation
("promoted eight months ago: +2 readiness") HR would rightly distrust.
"""

from __future__ import annotations

import pandas as pd

from ..appraisal.inputs import Input

REQUIRED = ["rating_latest"]
OPTIONAL = ["rating_change"]

INPUTS = [
    Input("rating_latest", "Latest appraisal", "%", 40.0, 100.0),
    Input("rating_change", "Change since previous appraisal", " pts", -22.0, 25.0),
]
LABELS = {spec.name: spec.label for spec in INPUTS}

# Which history a score rests on, by the optional inputs it had.
BASIS = {
    (): "latest_appraisal",
    ("rating_change",): "two_appraisals",
}

# Tiers by lift over the reference's promotion rate: "high" is at least twice as
# likely as the average person to be promoted, "medium" at least as likely.
TIER_LIFT = {"high": 2.0, "medium": 1.0}

TARGET = "promoted"


def reference_frame(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.Series]:
    """The reference workforce in the contract's units, and whether each was promoted."""
    X = pd.DataFrame(
        {
            "rating_latest": df["performance_score"].astype(float),
            "rating_change": (df["performance_score"] - df["performance_last_year"]).astype(float),
        }
    )
    return X, df[TARGET].astype(int)
