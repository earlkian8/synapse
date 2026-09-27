"""The promotion-readiness model's guarantees.

Trained here on a synthetic workforce with a known structure (promotion driven by
level and, more, by improvement), so the properties are tested without the git-ignored
reference dataset and in seconds. The trained artifact, when present, is held to the
same guarantees in ``test_api_served.py``.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from synapse_ml.promotion import evaluation, features
from synapse_ml.promotion.model import (
    FACTOR_FLOOR,
    CalibratedLogistic,
    MonotoneQuadraticPlatt,
    Percentile,
    PromotionReadinessModel,
)


def synthetic_workforce(n: int = 20_000, seed: int = 0) -> tuple[pd.DataFrame, pd.Series]:
    rng = np.random.default_rng(seed)
    latest = np.clip(rng.normal(70, 14, n), 40, 100)
    change = np.clip(rng.normal(2, 6, n), -22, 25)
    logit = -3.0 + 0.035 * (latest - 70) + 0.25 * (change - 2)
    promoted = rng.random(n) < 1 / (1 + np.exp(-logit))
    X = pd.DataFrame({"rating_latest": latest, "rating_change": change})
    return X, pd.Series(promoted.astype(int))


@pytest.fixture(scope="module")
def trained():
    X, y = synthetic_workforce()
    return PromotionReadinessModel().fit(X, y), X, y


# ---- declining, never guessing ---------------------------------------------------------


def test_a_record_without_a_completed_appraisal_is_declined(trained):
    model, _, _ = trained
    [result] = model.assess([{"rating_change": 4.0}])
    assert result["status"] == "insufficient"
    assert result["missing"] == ["rating_latest"]
    assert result["score"] is None and result["tier"] is None and result["probability"] is None


def test_one_appraisal_is_scored_by_the_submodel_that_never_needed_a_change(trained):
    model, _, _ = trained
    [result] = model.assess([{"rating_latest": 75.0}])
    assert result["status"] == "scored"
    assert result["basis"] == "latest_appraisal"
    sub = model.router.submodel(())
    expected = sub.predict_proba(pd.DataFrame({"rating_latest": [75.0]}))[0, 1]
    assert result["probability"] == pytest.approx(expected, abs=1e-5)


def test_two_appraisals_are_scored_on_their_change(trained):
    model, _, _ = trained
    [result] = model.assess([{"rating_latest": 75.0, "rating_change": 6.0}])
    assert result["basis"] == "two_appraisals"


def test_nothing_the_record_lacks_is_offered_as_a_reason(trained):
    model, _, _ = trained
    [result] = model.assess([{"rating_latest": 92.0}])
    assert {f["feature"] for f in result["factors"]} <= {"rating_latest"}


# ---- the score, the tiers ---------------------------------------------------------------


def test_tiers_are_lifts_over_the_base_rate(trained):
    model, _, y = trained
    assert model.tiers["high"] == pytest.approx(2 * y.mean())
    assert model.tiers["medium"] == pytest.approx(y.mean())
    assert model.tier(model.tiers["high"]) == "high"
    assert model.tier(model.tiers["high"] - 1e-9) == "medium"
    assert model.tier(model.tiers["medium"] - 1e-9) == "low"


def test_the_score_is_a_percentile_and_rises_with_the_probability(trained):
    model, _, _ = trained
    records = [{"rating_latest": r, "rating_change": c} for r in (45, 70, 95) for c in (-10, 2, 15)]
    results = model.assess(records)
    probabilities = [r["probability"] for r in results]
    scores = [r["score"] for r in results]
    assert all(0 <= s <= 100 for s in scores)
    order = np.argsort(probabilities)
    assert np.all(np.diff(np.array(scores)[order]) >= 0)


def test_the_tier_and_the_score_agree(trained):
    model, _, _ = trained
    results = model.assess([{"rating_latest": r, "rating_change": c} for r in range(40, 101, 5) for c in (-10, 0, 10)])
    high_scores = [r["score"] for r in results if r["tier"] == "high"]
    low_scores = [r["score"] for r in results if r["tier"] == "low"]
    assert min(high_scores) > max(low_scores)


def test_percentile_is_mid_rank():
    pct = Percentile([1, 2, 2, 3])
    assert pct.at(0) == 0 and pct.at(4) == 100
    assert pct.at(2) == pytest.approx(50.0)   # half the ties below, half above


# ---- guarantees ------------------------------------------------------------------------------


def test_a_better_record_never_lowers_readiness(trained):
    model, _, _ = trained
    result = evaluation.monotonicity(model, steps=31)
    assert result["checks"] > 1000
    assert result["violations"] == {"rating_latest": 0, "rating_change": 0}


def test_probabilities_are_calibrated_out_of_fold(trained):
    _, X, y = trained
    oof = evaluation.oof(CalibratedLogistic(), X, y)
    assert evaluation.expected_calibration_error(y, oof) < 0.015
    assert 0 < oof.min() and oof.max() < 1


def test_the_score_is_deterministic(trained):
    model, _, _ = trained
    record = {"rating_latest": 81.3, "rating_change": 4.2}
    assert model.assess([record]) == model.assess([record])


def test_batch_and_single_scoring_agree(trained):
    model, _, _ = trained
    records = [{"rating_latest": 60 + i, "rating_change": i - 5} for i in range(10)] + [{"rating_latest": 77}, {}]
    batch = model.assess(records)
    single = [model.assess([r])[0] for r in records]
    assert batch == single


def test_effects_below_the_floor_are_not_offered_as_reasons(trained):
    model, _, _ = trained
    for result in model.assess([{"rating_latest": r, "rating_change": c} for r in (50, 70, 90) for c in (-5, 2, 9)]):
        assert all(abs(f["impact"]) >= FACTOR_FLOOR for f in result["factors"])


def test_explanations_point_the_right_way(trained):
    model, _, _ = trained
    [improver, slider] = model.assess([
        {"rating_latest": 75.0, "rating_change": 12.0},
        {"rating_latest": 75.0, "rating_change": -12.0},
    ])
    change = {f["feature"]: f for f in improver["factors"]}["rating_change"]
    assert change["direction"] == "up" and change["impact"] > 0
    change = {f["feature"]: f for f in slider["factors"]}["rating_change"]
    assert change["direction"] == "down" and change["impact"] < 0


# ---- the calibration step ---------------------------------------------------------------------


def test_calibration_is_monotone_and_never_certain():
    rng = np.random.default_rng(1)
    s = rng.normal(-2, 1.5, 20_000)
    y = rng.random(len(s)) < 1 / (1 + np.exp(-(0.9 * s - 0.08 * s**2)))
    cal = MonotoneQuadraticPlatt().fit(s, y)
    grid = np.linspace(-15, 15, 3001)
    p = cal.predict(grid)
    assert np.all(np.diff(p) >= 0)
    assert 0 < p.min() and p.max() < 1


def test_a_bend_that_turns_back_inside_the_scores_falls_back_to_plain_platt():
    # A few hundred rows whose outcomes bend back at the top: the curvature is noise
    # at this size, so the calibration stays a plain, monotone Platt step.
    rng = np.random.default_rng(3)
    s = rng.normal(0, 1, 400)
    y = rng.random(len(s)) < 1 / (1 + np.exp(-(s - 0.9 * s**2)))
    cal = MonotoneQuadraticPlatt().fit(s, y)
    assert cal.c == 0 and cal.a > 0
    assert np.all(np.diff(cal.predict(np.linspace(-4, 4, 801))) >= 0)


def test_calibration_refuses_a_score_that_runs_backwards():
    rng = np.random.default_rng(2)
    s = rng.normal(0, 1, 5000)
    y = rng.random(len(s)) < 1 / (1 + np.exp(2 * s))   # higher score, less likely
    with pytest.raises(ValueError, match="refusing"):
        MonotoneQuadraticPlatt().fit(s, y)


def test_the_contract_names_every_input_and_tier(trained):
    model, _, _ = trained
    contract = model.contract()
    assert list(contract["inputs"]) == [spec.name for spec in features.INPUTS]
    assert contract["required"] == ["rating_latest"]
    assert contract["tiers"]["high_from"] > contract["tiers"]["medium_from"]
