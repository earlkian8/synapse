"""Training on an organisation's own records (``synapse_ml.local``), on synthetic
organisations whose truth is known: one whose outcomes follow a pattern the model in use
today misreads — its model must pass — and one whose outcomes are noise — its model must
never be offered, whatever it scores."""

from __future__ import annotations

import numpy as np
import pytest

from synapse_ml.local import store, training


def promotion_rows(people: int = 250, cycles: int = 4, signal: bool = True, seed: int = 1) -> list[dict]:
    """Appraisals and whether a promotion followed: in this organisation improvement
    and level drive promotion, at a far higher rate than the reference's 10 %."""
    rng = np.random.default_rng(seed)
    rows = []
    for person in range(people):
        rating, previous = rng.normal(70, 10), None
        for _ in range(cycles):
            rating = float(np.clip(rating + rng.normal(1, 6), 30, 100))
            features = {"rating_latest": round(rating, 1)}
            if previous is not None:
                features["rating_change"] = round(rating - previous, 1)
            logit = -1.2 + ((0.12 * (rating - 70) + 0.15 * (rating - (previous or rating))) if signal else 0)
            rows.append({"group": f"e{person}", "features": features, "outcome": int(rng.random() < 1 / (1 + np.exp(-logit)))})
            previous = rating
    return rows


def performance_rows(people: int = 120, cycles: int = 4, seed: int = 2) -> list[dict]:
    """Each rating and the next: this organisation's ratings drift up five points a
    cycle, not the reference's two."""
    rng = np.random.default_rng(seed)
    rows = []
    for person in range(people):
        rating = rng.normal(68, 12)
        for cycle in range(1, cycles):
            following = float(np.clip(rating + 5 + rng.normal(0, 5), 0, 100))
            rows.append({"group": f"e{person}", "features": {"rating_latest": round(rating, 1)},
                         "outcome": round(following, 1), "cycle": f"c{cycle}"})
            rating = following
    return rows


def attrition_rows(people: int = 600, signal: bool = True, seed: int = 3) -> list[dict]:
    """Risk-score snapshots and whether the person resigned within the year: here
    new hires leave, and attendance was never tracked."""
    rng = np.random.default_rng(seed)
    rows = []
    for person in range(people):
        tenure = float(rng.uniform(0, 15))
        p = (0.75 if tenure < 1 else 0.12) if signal else 0.2
        rows.append({
            "group": f"e{person}",
            "features": {"tenure_years": tenure, "monthly_salary": float(rng.uniform(12_000, 70_000)),
                         "employment_type": "regular", "ever_promoted": 0, "years_since_promotion": tenure},
            "outcome": int(rng.random() < p),
        })
    return rows


def constant(value: float):
    return lambda records: np.full(len(records), value)


# ---- the gate ---------------------------------------------------------------------------


def test_below_the_minimums_nothing_is_trained():
    rows = promotion_rows(people=40)
    with pytest.raises(training.TrainingRefused, match="promoted"):
        training.train("promotion", rows, constant(0.1))


def test_the_minimums_are_counted_as_the_panel_states_them():
    ex = training.examples("promotion", promotion_rows())
    counts = training.counts(ex)
    assert counts["promoted"] + counts["not_promoted"] == 1000
    # A first appraisal has no change to report, so not every promotion carries one.
    assert counts["promoted_with_change"] < counts["promoted"]
    assert counts["people"] == 250


def test_malformed_examples_are_refused():
    with pytest.raises(training.TrainingRefused, match="0 or 1"):
        training.examples("promotion", [{"group": "1", "features": {"rating_latest": 70}, "outcome": 2}])
    with pytest.raises(training.TrainingRefused, match="no latest appraisal"):
        training.examples("performance", [{"group": "1", "features": {}, "outcome": 70}])
    with pytest.raises(training.TrainingRefused, match="names no employee"):
        training.examples("attrition", [{"group": "", "features": {}, "outcome": 1}])


# ---- the check --------------------------------------------------------------------------


def test_a_promotion_model_that_reads_the_organisation_better_passes():
    outcome = training.train("promotion", promotion_rows(), constant(0.10))
    assert outcome.verdict == "passed", outcome.findings
    c = outcome.comparison
    assert c["local"] < c["reference"] and c["local"] < c["baseline"]
    assert c["wins_over_reference"] >= training.MUST_WIN
    assert outcome.fitted is not None
    # It serves exactly like the reference model, from the organisation's own scale.
    two, one = outcome.fitted.assess([{"rating_latest": 80, "rating_change": 8}, {"rating_latest": 80}])
    assert (two["basis"], one["basis"]) == ("two_appraisals", "latest_appraisal")
    assert two["probability"] > outcome.fitted.base_rate


def test_noise_is_never_offered_as_a_model():
    outcome = training.train("promotion", promotion_rows(signal=False), constant(0.23))
    assert outcome.verdict == "failed"
    assert outcome.fitted is None
    assert outcome.findings


def test_a_forecast_that_reads_the_organisation_better_passes_and_keeps_its_promise():
    reference = lambda records: np.array([r["rating_latest"] + 2 for r in records])
    outcome = training.train("performance", performance_rows(), reference)
    assert outcome.verdict == "passed", outcome.findings
    low, high = training.COVERAGE_BAND
    assert low <= outcome.comparison["coverage"] <= high
    [forecast] = outcome.fitted.assess([{"rating_latest": 70}])
    assert forecast["interval"]["low"] <= forecast["score"] <= forecast["interval"]["high"]
    # Its inputs are read in the range the organisation's records span.
    assert outcome.fitted.inputs[0].low < 40


def test_the_local_forecast_is_a_monotone_line():
    from synapse_ml.performance.model import MonotoneLine

    rows = performance_rows()
    ex = training.examples("performance", rows)
    fitted = training.fit("performance", ex.X, ex.y, ex.groups)
    assert isinstance(fitted.point, MonotoneLine) and fitted.point.slope > 0
    # A rating that runs backwards is flattened, never reversed.
    backwards = MonotoneLine().fit(ex.X, 150 - ex.y)
    assert backwards.slope == 0 and np.ptp(backwards.predict(ex.X)) == 0


def test_a_forecast_no_better_than_repeating_the_last_rating_fails():
    rows, rng = performance_rows(), np.random.default_rng(7)
    for row in rows:  # next rating = last rating + noise: nothing to learn
        row["outcome"] = float(np.clip(row["features"]["rating_latest"] + rng.normal(0, 5), 0, 100))
    outcome = training.train("performance", rows, lambda records: np.array([r["rating_latest"] + 6 for r in records]))
    assert outcome.verdict == "failed"
    assert any("last rating" in f for f in outcome.findings)


def test_an_attrition_model_passes_even_with_no_attendance_tracked():
    rng = np.random.default_rng(0)
    outcome = training.train("attrition", attrition_rows(), lambda records: rng.random(len(records)))
    assert outcome.verdict == "passed", outcome.findings
    assert outcome.comparison["local"] > outcome.comparison["reference"]
    assert outcome.fitted.predict_proba(training.examples("attrition", attrition_rows(people=5)).X).shape == (5, 2)


def test_every_example_of_one_person_stays_on_one_side_of_the_test():
    ex = training.examples("promotion", promotion_rows())
    from sklearn.model_selection import StratifiedGroupKFold

    for train, test in StratifiedGroupKFold(training.FOLDS, shuffle=True, random_state=training.SEED).split(ex.X, ex.y, ex.groups):
        assert not set(ex.groups[train]) & set(ex.groups[test])


# ---- the store --------------------------------------------------------------------------


def test_the_store_round_trips_and_keeps_organisations_apart(tmp_path):
    version = store.save("org-1", "performance", {"fitted": True}, {"comparison": {}}, root=tmp_path)
    fitted, metrics = store.load("org-1", "performance", version, root=tmp_path)
    assert fitted == {"fitted": True} and metrics["version"] == version
    with pytest.raises(store.UnknownLocalModel):
        store.load("org-2", "performance", version, root=tmp_path)


@pytest.mark.parametrize("tenant,version", [("../org-1", "20260927120000-abcdef"), ("org-1", "../../promotion"),
                                            ("ORG", "20260927120000-abcdef"), ("org-1", "")])
def test_the_store_refuses_paths_it_did_not_make(tmp_path, tenant, version):
    with pytest.raises(store.UnknownLocalModel):
        store.load(tenant, "promotion", version, root=tmp_path)
