"""The shared appraisal pieces: reading a live record, routing it to the submodel for
the history it has, and refusing a reference file the models were not built for."""

from __future__ import annotations

import math

import numpy as np
import pandas as pd
import pytest
from sklearn.linear_model import LinearRegression

from synapse_ml.appraisal import inputs, reference
from synapse_ml.appraisal.inputs import Input
from synapse_ml.appraisal.patterns import PatternRouter, patterns

RATING = Input("rating", "Latest appraisal", "%", 40.0, 100.0)
CHANGE = Input("change", "Change", " pts", -20.0, 20.0)


# ---- reading a record -----------------------------------------------------------------


@pytest.mark.parametrize(
    ("raw", "expected"),
    [(72.5, 72.5), ("72.5", 72.5), (0, 0.0), (None, None), ("", None), ("n/a", None), (True, None),
     (float("nan"), None), (float("inf"), None)],
)
def test_a_value_is_a_finite_number_or_absent(raw, expected):
    assert inputs.number(raw) == expected


def test_absent_values_are_left_out_never_zeroed():
    values, notes = inputs.read({"rating": None, "change": ""}, [RATING, CHANGE])
    assert values == {}
    assert notes == []


def test_a_non_number_is_left_out_and_said_so():
    values, notes = inputs.read({"rating": "excellent"}, [RATING])
    assert values == {}
    assert notes == ["Latest appraisal was not a number and was left out."]


def test_a_value_beyond_the_reference_is_held_at_the_edge_and_said_so():
    values, notes = inputs.read({"rating": 30, "change": 25}, [RATING, CHANGE])
    assert values == {"rating": 40.0, "change": 20.0}
    assert len(notes) == 2
    assert "30%" in notes[0] and "read as 40%" in notes[0]


def test_values_in_range_pass_untouched():
    values, notes = inputs.read({"rating": 65.17, "change": -3.5}, [RATING, CHANGE])
    assert values == {"rating": 65.17, "change": -3.5}
    assert notes == []


def test_unknown_keys_are_reported():
    assert inputs.unknown({"rating": 70, "salary": 50_000, "department": "IT"}, [RATING]) == ["department", "salary"]


# ---- routing by history pattern ------------------------------------------------------


def test_patterns_are_every_subset_fewest_first():
    assert patterns(["a", "b"]) == [(), ("a",), ("b",), ("a", "b")]


@pytest.fixture
def router():
    rng = np.random.default_rng(0)
    X = pd.DataFrame({"r": rng.uniform(40, 100, 500), "a": rng.normal(0, 1, 500), "b": rng.normal(0, 1, 500)})
    y = 0.5 * X["r"] + 3 * X["a"] - 2 * X["b"]
    return PatternRouter(LinearRegression(), required=["r"], optional=["a", "b"]).fit(X, y)


def test_one_submodel_per_pattern_on_exactly_its_columns(router):
    assert set(router.submodels) == {(), ("a",), ("b",), ("a", "b")}
    for pattern, sub in router.submodels.items():
        assert list(sub.feature_names_in_) == ["r", *pattern]


def test_a_row_is_scored_by_the_submodel_for_what_it_has(router):
    row = {"r": 70.0, "b": 1.0}
    [routed] = router.apply("predict", [row])
    direct = router.submodel(("b",)).predict(pd.DataFrame([row])[["r", "b"]])[0]
    assert routed == pytest.approx(direct)


def test_a_row_without_a_required_input_is_not_scored(router):
    assert router.pattern({"a": 1.0}) is None
    assert router.apply("predict", [{"a": 1.0}, {"r": 60.0}])[0] is None


def test_batching_by_pattern_keeps_each_row_in_place(router):
    rows = [{"r": 50.0}, {"r": 60.0, "a": 1.0}, {"r": 70.0}, {"r": 80.0, "a": 1.0, "b": 2.0}]
    batched = router.apply("predict", rows)
    single = [router.apply("predict", [row])[0] for row in rows]
    assert batched == pytest.approx(single)


def test_a_router_refuses_an_incomplete_training_frame():
    X = pd.DataFrame({"r": [50.0, np.nan], "a": [1.0, 2.0]})
    with pytest.raises(ValueError, match="complete"):
        PatternRouter(LinearRegression(), ["r"], ["a"]).fit(X, [1.0, 2.0])


# ---- the reference file ----------------------------------------------------------------


def _reference_rows(n: int = 5) -> pd.DataFrame:
    return pd.DataFrame(
        {
            "employee_id": range(1, n + 1),
            "performance_score": [70.0] * n,
            "performance_last_year": [68.0] * n,
            "performance_two_years_ago": [65.0] * n,
            "years_at_company": [4] * n,
            "years_since_last_promotion": [2] * n,
            "promoted": [0, 1, 0, 0, 1][:n],
        }
    )


def test_a_well_formed_reference_loads(tmp_path):
    path = tmp_path / "ref.csv"
    _reference_rows().to_csv(path, index=False)
    assert len(reference.load(path)) == 5


@pytest.mark.parametrize(
    ("mutate", "message"),
    [
        (lambda df: df.drop(columns="performance_last_year"), "missing columns"),
        (lambda df: df.assign(performance_score=[70, 70, 170, 70, 70]), "outside"),
        (lambda df: df.assign(promoted=[0, 1, 2, 0, 1]), "outside"),
        (lambda df: df.assign(years_at_company=[4, None, 4, 4, 4]), "missing or non-numeric"),
        (lambda df: df.assign(employee_id=[1, 1, 2, 3, 4]), "repeats"),
    ],
)
def test_a_malformed_reference_is_refused(tmp_path, mutate, message):
    path = tmp_path / "ref.csv"
    mutate(_reference_rows()).to_csv(path, index=False)
    with pytest.raises(reference.ReferenceDataError, match=message):
        reference.load(path)


def test_the_real_reference_passes_validation_when_present():
    if not reference.SOURCE.exists():
        pytest.skip("reference dataset not placed — see data/README.md")
    df = reference.load()
    assert len(df) == 100_000
    assert math.isclose(df["promoted"].mean(), 0.1, abs_tol=0.01)
