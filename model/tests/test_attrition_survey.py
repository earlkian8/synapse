"""Survey cleaning and encoding (synapse_ml.attrition.survey)."""

from __future__ import annotations

from datetime import timedelta

import numpy as np
import pandas as pd
import pytest

from synapse_ml.attrition import survey
from synapse_ml.attrition.features import RECORDED_FEATURES


@pytest.fixture(scope="module")
def cleaned():
    return survey.load()


def _raw_row(**overrides) -> dict:
    row = {
        "source": "aldrich_gathered",
        "reference": "",
        "submitted_at": pd.Timestamp("2026-09-15 20:00:00"),
        "consent": survey.CONSENTED,
        "employment_type_answer": "Regular / Permanent",
        "tenure_answer": "3 to 5 years",
        "department_answer": "Production",
        "salary_answer": "Below ₱15,000",
        "promotion_answer": survey.NEVER_PROMOTED,
        "overtime_answer": "None",
        "absences_answer": "1 to 2 days",
        "lates_answer": "None, I was never late",
        "outcome_answer": "I resigned voluntarily",
    }
    row.update(overrides)
    return row


# ── The real data ────────────────────────────────────────────────────────────


def test_every_source_is_kept_and_every_dropped_row_is_accounted_for(cleaned):
    tidy, report = cleaned

    assert set(tidy["source"]) == {"aldrich_gathered", "attrition-survey-2"}
    assert sum(report.raw_rows.values()) == report.kept + sum(report.dropped.values())
    assert report.kept == len(tidy)
    assert report.positives == int(tidy[survey.TARGET].sum())


def test_only_voluntary_leavers_and_stayers_are_kept(cleaned):
    tidy, report = cleaned

    assert set(tidy[survey.TARGET]) == {0, 1}
    assert report.dropped["did not consent"] == 1
    assert any("retired" in reason for reason in report.dropped)
    assert any("contract" in reason for reason in report.dropped)


def test_references_are_unique(cleaned):
    tidy, _ = cleaned

    assert tidy["reference"].is_unique


def test_every_answer_is_present_and_only_salary_is_ever_missing(cleaned):
    tidy, _ = cleaned

    assert list(tidy[RECORDED_FEATURES].columns) == RECORDED_FEATURES
    missing = tidy[RECORDED_FEATURES].isna().sum()
    assert set(missing[missing > 0].index) <= {"monthly_salary"}


def test_the_answer_none_is_zero_overtime_not_a_missing_value(cleaned):
    # pandas reads "None" as NaN unless told otherwise; that would silently turn
    # a real answer into an imputed one.
    tidy, _ = cleaned

    assert tidy["overtime_hours_90d"].notna().all()
    assert (tidy["overtime_hours_90d"] == 0).sum() > 0


def test_a_blank_overtime_answer_is_the_none_the_merge_lost(tmp_path):
    # Re-saving the exports blanked "None"; read back, it is zero overtime again.
    raw = pd.read_csv(survey.RAW_DATA_DIR / survey.DATASET_FILE, dtype=str, keep_default_na=False,
                      encoding="utf-8-sig", nrows=2)
    overtime = next(c for c in raw.columns if c.startswith("6."))
    raw[overtime] = ["", "1 to 10 hours"]
    raw.to_csv(tmp_path / survey.DATASET_FILE, index=False, encoding="utf-8-sig")

    loaded = survey.load_raw(tmp_path)

    assert loaded["overtime_answer"].tolist() == [survey.NO_OVERTIME, "1 to 10 hours"]


def test_an_export_without_its_source_column_fails_loudly(tmp_path):
    raw = pd.read_csv(survey.RAW_DATA_DIR / survey.DATASET_FILE, dtype=str, keep_default_na=False,
                      encoding="utf-8-sig", nrows=1)
    raw.drop(columns=[survey.SOURCE_COLUMN]).to_csv(tmp_path / survey.DATASET_FILE, index=False)

    with pytest.raises(ValueError, match="Dataset Source"):
        survey.load_raw(tmp_path)


# ── The rules, on constructed rows ───────────────────────────────────────────


def test_never_promoted_waits_the_whole_tenure():
    tidy, _ = survey.clean(pd.DataFrame([_raw_row()]))

    assert tidy.loc[0, "ever_promoted"] == 0
    assert tidy.loc[0, "years_since_promotion"] == tidy.loc[0, "tenure_years"] == 4.0


def test_prefer_not_to_say_is_missing_salary_not_zero():
    tidy, _ = survey.clean(pd.DataFrame([_raw_row(salary_answer="Prefer not to say")]))

    assert np.isnan(tidy.loc[0, "monthly_salary"])


def test_non_regular_engagements_map_to_the_erp_contractual_type():
    rows = [_raw_row(employment_type_answer=a, submitted_at=pd.Timestamp("2026-09-15") + timedelta(hours=i))
            for i, a in enumerate(["Project-based", "Casual / Seasonal", "Fixed-term / Contractual"])]
    tidy, _ = survey.clean(pd.DataFrame(rows))

    assert set(tidy["employment_type"]) == {"contractual"}


def test_an_identical_answer_set_within_two_minutes_is_one_resubmission():
    first = _raw_row()
    again = _raw_row(submitted_at=first["submitted_at"] + timedelta(seconds=40))
    tidy, report = survey.clean(pd.DataFrame([first, again]))

    assert len(tidy) == 1
    assert any("resubmission" in reason for reason in report.dropped)


def test_identical_answers_further_apart_are_two_people_sharing_a_pattern():
    first = _raw_row()
    later = _raw_row(submitted_at=first["submitted_at"] + timedelta(minutes=10))
    tidy, _ = survey.clean(pd.DataFrame([first, later]))

    assert len(tidy) == 2
    assert tidy["pattern"].nunique() == 1


def test_involuntary_exits_are_not_counted_as_attrition():
    rows = [
        _raw_row(outcome_answer="I was terminated or dismissed"),
        _raw_row(outcome_answer="My contract or project ended", submitted_at=pd.Timestamp("2026-09-16")),
        _raw_row(outcome_answer="I am still employed there", submitted_at=pd.Timestamp("2026-09-17")),
    ]
    tidy, _ = survey.clean(pd.DataFrame(rows))

    assert tidy[survey.TARGET].tolist() == [0]


def test_an_unrecognised_answer_fails_loudly():
    with pytest.raises(ValueError, match="unrecognised survey answers"):
        survey.clean(pd.DataFrame([_raw_row(tenure_answer="Forever")]))
