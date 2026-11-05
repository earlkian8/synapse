"""Behavioural indicators of emotional states (synapse_ml.attrition.indicators) and the
withdrawal input the model reads: each level must follow from the recorded inputs alone,
never be guessed from a missing one, and every candidate must learn only from the rows
it is fitted on."""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from synapse_ml.attrition import features, indicators, survey
from synapse_ml.attrition import model as models
from synapse_ml.explain import shapley

# ---- levels -------------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("absences", "lates", "level"),
    [
        (0, 0, "None"),
        (1, 0, "Low"),  # 1–2 days absent, never late: bands 1 + 0
        (2, 2, "Low"),  # bands 1 + 1
        (4, 4, "Moderate"),  # bands 2 + 2
        (8, 0, "Moderate"),  # band 3 alone
        (8, 4, "High"),  # bands 3 + 2
        (40, 40, "High"),  # beyond the top band is still the top band
    ],
)
def test_withdrawal_adds_the_two_attendance_bands(absences, lates, level):
    assert indicators.level_label("withdrawal", {"absences_90d": absences, "lates_90d": lates}) == level


def test_withdrawal_needs_both_counts():
    assert indicators.level_label("withdrawal", {"absences_90d": 12}) is None
    assert indicators.level_label("withdrawal", {"absences_90d": 12, "lates_90d": np.nan}) is None


@pytest.mark.parametrize(
    ("ever", "wait", "level"),
    [
        (1, 0.5, "Progressing"),
        (0, 0.5, "Not yet promoted"),
        (1, 4.0, "Plateaued"),
        (0, 4.0, "Stalled"),
        (1, 2.99, "Progressing"),  # whole years completed: 2.99 is still "1 to 2 years"
    ],
)
def test_career_stagnation_reads_promotion_and_the_wait(ever, wait, level):
    assert indicators.level_label("career_stagnation", {"ever_promoted": ever, "years_since_promotion": wait}) == level


@pytest.mark.parametrize(("overtime", "level"), [(0, "None"), (10, "Normal"), (25, "Normal"), (30, "High"), (80, "Very high")])
def test_workload_strain_reads_overtime(overtime, level):
    assert indicators.level_label("workload_strain", {"overtime_hours_90d": overtime}) == level


@pytest.mark.parametrize(
    ("record", "level"),
    [
        ({"overtime_hours_90d": 5}, "Balanced"),  # little effort: no reward input needed
        ({"overtime_hours_90d": 40, "monthly_salary": 18_000, "years_since_promotion": 0.5}, "Imbalanced"),
        ({"overtime_hours_90d": 15, "monthly_salary": 50_000, "years_since_promotion": 4}, "Some strain"),
        ({"overtime_hours_90d": 40, "monthly_salary": 50_000, "years_since_promotion": 1}, "Balanced"),
        ({"overtime_hours_90d": 40, "years_since_promotion": 5}, "Imbalanced"),  # one shortfall is enough
        ({"overtime_hours_90d": 40, "years_since_promotion": 1}, None),  # pay unknown: not "balanced"
        ({"monthly_salary": 18_000}, None),
    ],
)
def test_effort_reward_weighs_effort_against_reward(record, level):
    assert indicators.level_label("effort_reward", record) == level


# ---- evidence and readings ------------------------------------------------------------


def test_the_profile_counts_who_left_at_each_level():
    records = pd.DataFrame({"absences_90d": [0, 0, 12, 12], "lates_90d": [0, 0, 12, 12]})
    table = indicators.profile(records, [0, 1, 1, 1])

    assert set(table) == {"withdrawal"}  # only the indicators the columns can place people on
    assert table["withdrawal"] == [
        {"band": "None", "people": 2, "left": 1, "rate": 0.5},
        {"band": "High", "people": 2, "left": 2, "rate": 1.0},
    ]


def test_how_a_model_reads_each_indicator_follows_its_inputs():
    players = {"tenure_years": ["tenure_years"], "years_since_promotion": ["years_since_promotion"],
               "ever_promoted": ["ever_promoted"], "monthly_salary": ["monthly_salary"],
               "withdrawal": ["absences_90d", "lates_90d"], "employment_type": ["employment_type"]}
    read = {d["key"]: d["read"] for d in indicators.definitions(players)}

    assert read == {"withdrawal": "direct", "career_stagnation": "inputs", "workload_strain": "none", "effort_reward": "none"}
    # A model reading absences and lateness apart still reads withdrawal, through them.
    apart = {**{k: v for k, v in players.items() if k != "withdrawal"}, "absences_90d": ["absences_90d"], "lates_90d": ["lates_90d"]}
    assert indicators.status(indicators.BY_KEY["withdrawal"], apart) == "inputs"
    unread = [d for d in indicators.definitions(players) if d["read"] == "none"]
    assert all(d["not_read"] for d in unread)


def test_a_reading_carries_its_points_and_the_survey_s_rate():
    players = {"withdrawal": ["absences_90d", "lates_90d"], "years_since_promotion": ["years_since_promotion"],
               "ever_promoted": ["ever_promoted"]}
    evidence = {"overall_rate": 0.28, "indicators": {"withdrawal": [{"band": "High", "people": 27, "left": 14, "rate": 0.5185}]}}
    record = {"absences_90d": 8, "lates_90d": 6, "years_since_promotion": 4, "ever_promoted": 0, "overtime_hours_90d": 0}
    contributions = {"withdrawal": 12.34, "years_since_promotion": -2.0, "ever_promoted": 0.5}

    by_key = {r["key"]: r for r in indicators.readings(record, players, contributions, evidence)}

    assert by_key["withdrawal"] == {
        "key": "withdrawal", "level": "High", "rank": 3, "read": "direct", "points": 12.3,
        "inputs": {"absences_90d": 8, "lates_90d": 6},
        "evidence": {"rate": 0.5185, "people": 27, "overall_rate": 0.28},
    }
    assert by_key["career_stagnation"]["points"] == -1.5  # its two inputs' points together
    assert by_key["workload_strain"]["points"] is None and by_key["workload_strain"]["read"] == "none"
    assert "effort_reward" in by_key  # no overtime is "balanced" whatever the reward


# ---- the withdrawal input --------------------------------------------------------------


@pytest.fixture(scope="module")
def tidy():
    return survey.load()[0]


def test_the_served_pipeline_reads_absences_and_lateness_as_one_withdrawal_input(tidy):
    prep = features.preprocessor(scale=True).fit(tidy[features.FEATURES])
    branches = {name: list(columns) for name, _, columns in prep.transformers_ if len(columns)}

    assert branches["withdrawal"] == ["absences_90d", "lates_90d"]
    assert branches["num"] == features.DIRECT_FEATURES
    # One column for the two counts: 4 direct + withdrawal + 4 employment types.
    assert prep.transform(tidy[features.FEATURES]).shape[1] == len(features.DIRECT_FEATURES) + 1 + 4


def test_withdrawal_is_the_equal_weight_mean_of_the_standardised_bands(tidy):
    prep = features.preprocessor(scale=False).fit(tidy[features.FEATURES])
    column = len(features.DIRECT_FEATURES)
    got = prep.transform(tidy[features.FEATURES])[:, column]

    bands = pd.DataFrame({f: tidy[f].map(lambda v, f=f: features.band_of(f, v)) for f in ("absences_90d", "lates_90d")})
    expected = ((bands - bands.mean()) / bands.std(ddof=0)).mean(axis=1)
    assert got == pytest.approx(expected.to_numpy())


def test_reading_the_counts_apart_restores_the_previous_layout(tidy):
    prep = features.preprocessor(indicators=False).fit(tidy[features.FEATURES])
    assert [name for name, _, columns in prep.transformers_ if len(columns)] == ["num", "cat"]


def test_an_organisation_that_tracks_no_attendance_still_fits(tidy):
    X = tidy[features.FEATURES].copy()
    X[["absences_90d", "lates_90d"]] = np.nan
    fitted = models.fit(models.svm(), X, tidy[survey.TARGET])
    assert np.isfinite(fitted.predict_proba(X.head(3))).all()


def test_grouped_players_reveal_their_inputs_together():
    # The model reads a and b only through their mean: as one player they carry the
    # whole effect, and the decomposition still adds up.
    def predict(rows):
        return [(row["a"] + row["b"]) / 2 * 10 + row["c"] for row in rows]

    row, typical = {"a": 4.0, "b": 2.0, "c": 1.0}, {"a": 0.0, "b": 0.0, "c": 0.0}
    [(base, contributions)] = shapley([row], [["ab", "c"]], typical, predict, groups={"ab": ["a", "b"]})

    assert contributions == pytest.approx({"ab": 30.0, "c": 1.0})
    assert base + sum(contributions.values()) == pytest.approx(predict([row])[0])


# ---- candidates (notebook §5) ----------------------------------------------------------


def test_candidates_learn_only_from_the_rows_they_are_fitted_on():
    train = pd.DataFrame({"overtime_hours_90d": [0, 5, 18, 38], "monthly_salary": [10_000, 20_000, 20_000, 50_000],
                          "years_since_promotion": [0.5, 2, 4, 7], "tenure_years": [1, 2, 4, 8],
                          "department_group": ["sales", "sales", "production", "production"]})
    other = train.assign(overtime_hours_90d=[120, 120, 120, 120], monthly_salary=[70_000] * 4)
    names = tuple(indicators.CANDIDATES)
    fitted = indicators.CandidateIndicators(names).fit(train)

    added = fitted.transform(train)
    assert set(names) <= set(added.columns)
    assert added["workload_strain"].mean() == pytest.approx(0.0)  # standardised on these rows
    # Pay against the group's own median: 10k and 20k against sales' 15k.
    assert added["pay_inequity"].tolist()[:2] == pytest.approx([np.log(10 / 15), np.log(20 / 15)])
    # New rows are placed on the training rows' scale, not re-standardised on their own.
    assert fitted.transform(other)["workload_strain"].mean() > 1
    with pytest.raises(ValueError):
        indicators.CandidateIndicators(("mood",)).fit(train)


def test_stagnation_is_the_share_of_tenure_spent_waiting():
    frame = pd.DataFrame({"tenure_years": [8.0, 4.0, 0.1], "years_since_promotion": [2.0, 4.0, 0.1],
                          "overtime_hours_90d": 0, "monthly_salary": 20_000})
    added = indicators.CandidateIndicators(("career_stagnation",)).fit(frame).transform(frame)
    assert added["career_stagnation"].tolist() == pytest.approx([0.25, 1.0, 0.4])


# ---- the department answer (Q3) --------------------------------------------------------


@pytest.mark.parametrize(
    ("answer", "group"),
    [
        ("Production", "production"),
        ("Assistant leader of filling line", "production"),
        ("Sales.", "sales"),
        ("Secretary to the Mayor's Office", "public_sector"),  # not administration
        ("City Accountant", "public_sector"),  # not finance
        ("PAF, AFP", "uniformed"),
        ("Human resourse", "hr_admin"),
        ("IT support personnel", "it"),
        ("Operations Dept", "operations"),
        ("okay", "other"),
        ("", "other"),
    ],
)
def test_department_answers_are_grouped(answer, group):
    assert survey.department_group(answer) == group


def test_small_department_groups_join_other(tidy):
    counts = tidy["department_group"].value_counts()
    assert (counts.drop(survey.OTHER_DEPARTMENT) >= survey.MIN_DEPARTMENT_GROUP).all()
    merged = survey.merge_small_groups(pd.Series(["sales"] * 10 + ["it"] * 3))
    assert merged.value_counts().to_dict() == {"sales": 10, "other": 3}
