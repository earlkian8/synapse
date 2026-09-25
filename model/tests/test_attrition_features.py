"""The attrition feature contract (synapse_ml.attrition.features): exact ERP values must
land in the band a respondent would have ticked, and every survey answer in its own."""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from synapse_ml.attrition import features, survey


@pytest.mark.parametrize(
    ("feature", "value", "band"),
    [
        ("tenure_years", 0.99, 0),   # less than a year
        ("tenure_years", 2.9, 1),    # "1 to 2 years" — whole years completed
        ("tenure_years", 3.0, 2),
        ("tenure_years", 10.9, 3),
        ("tenure_years", 11.0, 4),
        ("monthly_salary", 14_999.99, 0),
        ("monthly_salary", 15_000, 1),
        ("monthly_salary", 60_000, 4),
        ("overtime_hours_90d", 0.3, 0),  # a stray 20 minutes is "None"
        ("overtime_hours_90d", 10, 1),
        ("overtime_hours_90d", 101, 5),
        ("absences_90d", 0, 0),
        ("absences_90d", 2, 1),
        ("absences_90d", 3, 2),
        ("lates_90d", 11, 4),
    ],
)
def test_erp_values_band_the_way_a_respondent_would_answer(feature, value, band):
    assert features.band_of(feature, value) == band


@pytest.mark.parametrize(
    ("feature", "vocabulary"),
    [
        ("tenure_years", survey.TENURE_YEARS),
        ("years_since_promotion", survey.YEARS_SINCE_PROMOTION),
        ("monthly_salary", survey.MONTHLY_SALARY),
        ("overtime_hours_90d", survey.OVERTIME_HOURS),
        ("absences_90d", survey.ABSENCE_DAYS),
        ("lates_90d", survey.LATE_TIMES),
    ],
)
def test_every_answer_lands_in_its_own_band_in_order(feature, vocabulary):
    values = [v for v in vocabulary.values() if not np.isnan(v)]
    bands = [features.band_of(feature, v) for v in values]

    assert bands == list(range(len(values)))
    assert len(features.BAND_LABELS[feature]) == len(features.BAND_EDGES[feature]) + 1


def test_exact_erp_values_encode_exactly_like_the_matching_survey_answers():
    prep = features.preprocessor().fit(survey.load()[0][features.FEATURES])
    erp = pd.DataFrame([{"tenure_years": 4.2, "years_since_promotion": 4.2, "ever_promoted": 0, "monthly_salary": 18_500,
                         "overtime_hours_90d": 7.25, "absences_90d": 3, "lates_90d": 2, "employment_type": "regular"}])
    answered = pd.DataFrame([{"tenure_years": 4.0, "years_since_promotion": 4.0, "ever_promoted": 0, "monthly_salary": 20_000,
                              "overtime_hours_90d": 5.0, "absences_90d": 4.0, "lates_90d": 1.5, "employment_type": "regular"}])

    assert (prep.transform(erp[features.FEATURES]) == prep.transform(answered[features.FEATURES])).all()
