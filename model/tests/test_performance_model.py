"""The performance-forecast model's guarantees, on a synthetic workforce whose next
rating is the latest plus drift and noise — so the true coverage of an honest interval
is known."""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from synapse_ml.performance import evaluation, features
from synapse_ml.performance.model import MondrianConformal, PerformanceForecastModel


def synthetic_workforce(n: int = 40_000, seed: int = 0) -> tuple[pd.DataFrame, pd.Series]:
    rng = np.random.default_rng(seed)
    latest = np.clip(rng.normal(68, 14, n), 40, 100)
    noise = rng.normal(0, 6, n)
    nxt = np.clip(latest + 2 + noise, 40, 100)
    return pd.DataFrame({"rating_latest": latest}), pd.Series(nxt, name="next_rating")


@pytest.fixture(scope="module")
def trained():
    X, y = synthetic_workforce()
    X_test, y_test = synthetic_workforce(n=20_000, seed=99)
    return PerformanceForecastModel().fit(X, y), X_test, y_test


def test_a_record_without_a_prior_appraisal_is_declined(trained):
    model, _, _ = trained
    [result] = model.assess([{}])
    assert result["status"] == "insufficient"
    assert result["missing"] == ["rating_latest"]
    assert result["score"] is None and result["interval"] is None and result["band"] is None


def test_every_forecast_carries_an_interval_that_holds_it(trained):
    model, _, _ = trained
    for result in model.assess([{"rating_latest": r} for r in range(40, 101, 3)]):
        interval = result["interval"]
        assert interval["low"] <= result["score"] <= interval["high"]
        assert 0 <= interval["low"] and interval["high"] <= 100
        assert interval["coverage"] == features.COVERAGE


def test_the_interval_holds_four_in_five_on_unseen_rows_everywhere(trained):
    model, X_test, y_test = trained
    held = evaluation.held_out(model, X_test, y_test)
    table = evaluation.coverage(held)
    assert table.loc["all", "coverage"] == pytest.approx(features.COVERAGE, abs=0.02)
    assert (table["coverage"].iloc[:-1] > features.COVERAGE - 0.05).all(), table


def test_the_stated_confidence_is_how_often_the_band_is_right(trained):
    model, X_test, y_test = trained
    held = evaluation.held_out(model, X_test, y_test)
    rel = evaluation.confidence_reliability(held)
    assert np.abs(rel["stated"] - rel["observed"]).max() < 0.05


def test_the_band_is_the_band_of_the_forecast(trained):
    model, _, _ = trained
    for result in model.assess([{"rating_latest": r} for r in range(40, 101, 2)]):
        assert result["band"] == features.band_of(result["score"])
        assert 0 <= result["confidence"] <= 1


def test_confidence_is_lowest_at_a_band_edge(trained):
    model, _, _ = trained
    mid = model.forecast(68.0)
    # find the latest rating whose forecast sits right on the 60/80 edge
    edge = min((model.forecast(r) for r in np.arange(50, 70, 0.25)), key=lambda f: abs(f["point"] - 60))
    assert edge["confidence"] < mid["confidence"]
    assert 0.4 < edge["confidence"] < 0.7


def test_a_better_latest_appraisal_never_forecasts_a_worse_next(trained):
    model, _, _ = trained
    result = evaluation.monotonicity(model)
    assert result["point_decreases"] == 0
    assert result["interval_never_excludes_point"]


def test_a_rating_outside_the_reference_is_held_and_said_so(trained):
    model, _, _ = trained
    [low, held] = model.assess([{"rating_latest": 25}, {"rating_latest": 40}])
    assert low["score"] == held["score"]
    assert low["warnings"] and not held["warnings"]


def test_batch_and_single_forecasts_agree(trained):
    model, _, _ = trained
    records = [{"rating_latest": r} for r in (41, 55.5, 65.17, 79.9, 100)] + [{}]
    assert model.assess(records) == [model.assess([r])[0] for r in records]


def test_conformal_refuses_too_few_errors_per_region():
    with pytest.raises(ValueError, match="fewer than 100"):
        MondrianConformal(bins=20).fit(np.linspace(0, 1, 500), np.linspace(0, 1, 500))


def test_the_contract_states_bands_and_coverage(trained):
    model, _, _ = trained
    contract = model.contract()
    assert contract["bands"] == {"below": 60.0, "on_track": 80.0}
    assert contract["interval_coverage"] == features.COVERAGE
    assert contract["required"] == ["rating_latest"]
