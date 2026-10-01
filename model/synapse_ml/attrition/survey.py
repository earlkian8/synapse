"""Load, merge and clean the attrition surveys into the model's training table.

Two Google-Forms exports (``data/raw/attrition-survey-{1,2}.csv``) ask the same nine
questions of people about an employer they worked for. This module is the single
source of truth for every decision made on the way to a tidy dataset:

* **Who is kept.** Consenting respondents, and only the two outcomes that answer
  "did this person *choose* to leave?": resigned voluntarily (``left = 1``) and still
  employed (``left = 0``). Retirement, contract end and dismissal are a different event,
  so they are excluded rather than counted as attrition — or as staying.
* **Resubmissions.** Identical answers from the same survey within
  ``RESUBMIT_WINDOW`` are one person pressing Submit twice. Identical answers further
  apart are kept — co-workers legitimately tick the same bands — but share a
  ``pattern`` id so evaluation keeps them on one side of every split.
* **Encoding.** Each banded answer becomes a representative value in the unit the ERP
  records; :mod:`.features` bands it back inside the pipeline.

The department answer (free text, unmatchable to an ERP department list) and the
timestamp / reference number are kept for audit but are not model inputs.

Run ``python -m synapse_ml.attrition.survey`` to (re)write the merged dataset.
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass, field
from datetime import timedelta
from pathlib import Path

import numpy as np
import pandas as pd

from ..paths import PROCESSED_DATA_DIR, RAW_DATA_DIR
from .features import FEATURES

SURVEY_FILES = ("attrition-survey-1.csv", "attrition-survey-2.csv")
MERGED_PATH = PROCESSED_DATA_DIR / "attrition-survey-merged.csv"

TARGET = "left"
RESUBMIT_WINDOW = timedelta(minutes=2)

# Each export's header, by the stable prefix every question starts with.
QUESTIONS = {
    "Timestamp": "submitted_at",
    "Reference Number": "reference",
    "A. Do you consent": "consent",
    "1. What was your employment type": "employment_type_answer",
    "2. How long did you work": "tenure_answer",
    "3. What department": "department_answer",
    "4. What was your monthly gross salary": "salary_answer",
    "5. How long had it been since your last promotion": "promotion_answer",
    "6. In your last 3 months at that company, about how many hours of overtime": "overtime_answer",
    "7. In your last 3 months at that company, about how many days were you absent": "absences_answer",
    "8. In your last 3 months at that company, about how many times did you report late": "lates_answer",
    "9. How did your employment with that company end": "outcome_answer",
}

ANSWER_COLUMNS = [
    "employment_type_answer", "tenure_answer", "department_answer", "salary_answer",
    "promotion_answer", "overtime_answer", "absences_answer", "lates_answer", "outcome_answer",
]

CONSENTED = "Yes, I have read the notice and I consent to participate."

OUTCOMES = {"I resigned voluntarily": 1, "I am still employed there": 0}
EXCLUDED_OUTCOMES = {"I retired", "My contract or project ended", "I was terminated or dismissed"}

# --------------------------------------------------------------------------------------
# Answer vocabularies -> representative values in the ERP's own units
# --------------------------------------------------------------------------------------

NEVER_PROMOTED = "I was never promoted"

# Survey employment types -> the ERP's four. Project-based, fixed-term and
# casual/seasonal work are all fixed engagements the ERP records as `contractual`.
EMPLOYMENT_TYPES = {
    "Regular / Permanent": "regular",
    "Probationary": "probationary",
    "Part-time": "part_time",
    "Fixed-term / Contractual": "contractual",
    "Project-based": "contractual",
    "Casual / Seasonal": "contractual",
}

TENURE_YEARS = {
    "Less than 1 year": 0.5,
    "1 to 2 years": 2.0,
    "3 to 5 years": 4.0,
    "6 to 10 years": 8.0,
    "More than 10 years": 12.0,
}

MONTHLY_SALARY = {
    "Below ₱15,000": 10_000.0,
    "₱15,000 to ₱24,999": 20_000.0,
    "₱25,000 to ₱39,999": 32_500.0,
    "₱40,000 to ₱59,999": 50_000.0,
    "₱60,000 or more": 70_000.0,
    "Prefer not to say": np.nan,
}

YEARS_SINCE_PROMOTION = {
    "Less than 1 year": 0.5,
    "1 to 2 years": 2.0,
    "3 to 5 years": 4.0,
    "More than 5 years": 7.0,
}

OVERTIME_HOURS = {
    "None": 0.0,
    "1 to 10 hours": 5.0,
    "11 to 25 hours": 18.0,
    "26 to 50 hours": 38.0,
    "51 to 100 hours": 75.0,
    "More than 100 hours": 120.0,
}

ABSENCE_DAYS = {
    "None, I was never absent": 0.0,
    "1 to 2 days": 1.5,
    "3 to 5 days": 4.0,
    "6 to 10 days": 8.0,
    "More than 10 days": 12.0,
}

LATE_TIMES = {
    "None, I was never late": 0.0,
    "1 to 2 times": 1.5,
    "3 to 5 times": 4.0,
    "6 to 10 times": 8.0,
    "More than 10 times": 12.0,
}

# answer column -> (vocabulary, answers that are valid but not in it)
_VOCABULARIES = {
    "employment_type_answer": (EMPLOYMENT_TYPES, set()),
    "tenure_answer": (TENURE_YEARS, set()),
    "salary_answer": (MONTHLY_SALARY, set()),
    "promotion_answer": (YEARS_SINCE_PROMOTION, {NEVER_PROMOTED}),
    "overtime_answer": (OVERTIME_HOURS, set()),
    "absences_answer": (ABSENCE_DAYS, set()),
    "lates_answer": (LATE_TIMES, set()),
}


@dataclass
class CleaningReport:
    """What happened to every raw row, so the notebook can log and assert on it."""

    raw_rows: dict[str, int] = field(default_factory=dict)
    dropped: dict[str, int] = field(default_factory=dict)
    kept: int = 0
    positives: int = 0

    def drop(self, reason: str, count: int) -> None:
        if count:
            self.dropped[reason] = self.dropped.get(reason, 0) + int(count)

    def lines(self) -> list[str]:
        out = [f"raw rows · {', '.join(f'{k}={v}' for k, v in self.raw_rows.items())}"]
        out += [f"dropped · {reason}: {n}" for reason, n in self.dropped.items()]
        out.append(f"kept · {self.kept} rows ({self.positives} left voluntarily, {self.kept - self.positives} stayed)")
        return out


# --------------------------------------------------------------------------------------
# Loading & cleaning
# --------------------------------------------------------------------------------------


def load_raw(directory: Path = RAW_DATA_DIR, files: tuple[str, ...] = SURVEY_FILES) -> pd.DataFrame:
    """Read and stack the survey exports, verbatim but for whitespace.

    ``keep_default_na=False`` is load-bearing: pandas otherwise reads the answer "None"
    (no overtime) as a missing value, silently turning a real answer into an imputed one.
    """
    frames = []
    for filename in files:
        raw = pd.read_csv(directory / filename, dtype=str, keep_default_na=False, encoding="utf-8")
        df = _rename_questions(raw, filename)
        df.insert(0, "source", Path(filename).stem)
        if "reference" not in df:
            df.insert(1, "reference", "")
        frames.append(df)

    df = pd.concat(frames, ignore_index=True)
    for column in df.columns:
        df[column] = df[column].astype(str).str.strip()
    df["submitted_at"] = pd.to_datetime(df["submitted_at"], format="%m/%d/%Y %H:%M:%S")

    # Survey 1 has no reference numbers; give every row a stable one.
    blank = df["reference"] == ""
    df.loc[blank, "reference"] = df[blank].groupby("source").cumcount().add(1).map(lambda n: f"S1-{n:04d}")
    return df


def clean(raw: pd.DataFrame) -> tuple[pd.DataFrame, CleaningReport]:
    """Apply every inclusion rule and return ``(tidy dataset, report)``."""
    report = CleaningReport(raw_rows=raw.groupby("source").size().to_dict())
    df = raw

    consented = df["consent"] == CONSENTED
    report.drop("did not consent", (~consented).sum())
    df = df[consented]

    no_outcome = ~df["outcome_answer"].isin(set(OUTCOMES) | EXCLUDED_OUTCOMES)
    report.drop("no outcome recorded", no_outcome.sum())
    df = df[~no_outcome]

    for outcome in sorted(EXCLUDED_OUTCOMES):
        report.drop(f"involuntary / non-attrition exit: {outcome.lower()}", (df["outcome_answer"] == outcome).sum())
    df = df[df["outcome_answer"].isin(OUTCOMES)]

    df = df.sort_values(["source", "submitted_at"]).copy()
    df["pattern"] = _answer_pattern(df)
    gap = df.groupby(["source", "pattern"])["submitted_at"].diff()
    resubmit = gap.notna() & (gap <= RESUBMIT_WINDOW)
    report.drop(f"resubmission (identical answers within {int(RESUBMIT_WINDOW.total_seconds())}s)", resubmit.sum())
    df = df[~resubmit]

    audit = df[["source", "reference", "submitted_at", "pattern", "department_answer"]].rename(
        columns={"department_answer": "department_reported"}
    )
    target = df["outcome_answer"].map(OUTCOMES).rename(TARGET).astype(int)
    tidy = pd.concat([audit, encode(df), target], axis=1).reset_index(drop=True)

    report.kept = len(tidy)
    report.positives = int(tidy[TARGET].sum())
    return tidy, report


def encode(df: pd.DataFrame) -> pd.DataFrame:
    """Turn answer columns into model features, in the ERP's units."""
    unknown = {
        column: sorted(set(df[column]) - set(vocabulary) - also_valid)
        for column, (vocabulary, also_valid) in _VOCABULARIES.items()
    }
    unknown = {column: answers for column, answers in unknown.items() if answers}
    if unknown:
        raise ValueError(f"unrecognised survey answers: {unknown}")

    out = pd.DataFrame(index=df.index)
    out["tenure_years"] = df["tenure_answer"].map(TENURE_YEARS)
    out["ever_promoted"] = (df["promotion_answer"] != NEVER_PROMOTED).astype(float)
    # Never promoted: the wait is the whole tenure — the same substitution the ERP
    # mapper makes for an employee with no promotion on record.
    out["years_since_promotion"] = df["promotion_answer"].map(YEARS_SINCE_PROMOTION).fillna(out["tenure_years"])
    out["monthly_salary"] = df["salary_answer"].map(MONTHLY_SALARY)
    out["overtime_hours_90d"] = df["overtime_answer"].map(OVERTIME_HOURS)
    out["absences_90d"] = df["absences_answer"].map(ABSENCE_DAYS)
    out["lates_90d"] = df["lates_answer"].map(LATE_TIMES)
    out["employment_type"] = df["employment_type_answer"].map(EMPLOYMENT_TYPES)
    return out[FEATURES]


def load(directory: Path = RAW_DATA_DIR) -> tuple[pd.DataFrame, CleaningReport]:
    """Load, merge and clean both surveys."""
    return clean(load_raw(directory))


def write_merged(tidy: pd.DataFrame, path: Path = MERGED_PATH) -> Path:
    """Write the cleaned, merged dataset (``data/processed/``)."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tidy.to_csv(path, index=False, encoding="utf-8")
    return path


def _rename_questions(df: pd.DataFrame, source: str) -> pd.DataFrame:
    mapping = {}
    for column in df.columns:
        for prefix, name in QUESTIONS.items():
            if column.strip().startswith(prefix):
                mapping[column] = name
                break
    missing = set(QUESTIONS.values()) - set(mapping.values()) - {"reference"}
    if missing:
        raise ValueError(f"{source}: missing survey questions {sorted(missing)}")
    return df.rename(columns=mapping)[list(mapping.values())]


def _answer_pattern(df: pd.DataFrame) -> pd.Series:
    """A short, stable id per answer set (department compared case-insensitively)."""
    key = df[ANSWER_COLUMNS].assign(department_answer=df["department_answer"].str.lower())
    return key.astype(str).agg("|".join, axis=1).map(lambda s: hashlib.sha1(s.encode("utf-8")).hexdigest()[:10])


if __name__ == "__main__":
    import sys

    sys.stdout.reconfigure(encoding="utf-8")
    dataset, cleaning = load()
    print("\n".join(cleaning.lines()))
    print(f"wrote {write_merged(dataset)} ({len(dataset)} rows)")
