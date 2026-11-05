"""Why a score is what it is: the guarantees the HR screens' explanations rest on.

- Exact Shapley contributions add up: the typical record's score plus every
  contribution is the score, so the page's walk from "a typical employee" to this
  one is the model's own arithmetic.
- Only recorded facts are reasons; an input at its typical value contributes nothing.
- Attrition reasons carry what the survey shows for the employee's band and the
  typical one; promotion carries what the next appraisal would do; a forecast carries
  the chance of every band.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest
from fastapi.testclient import TestClient

from api.main import app
from api.main import registry as service_registry
from api.registry import FACTOR_FLOOR, _baseline
from synapse_ml.attrition import evidence, indicators
from synapse_ml.attrition.features import FEATURES as ATTRITION_FEATURES
from synapse_ml.attrition.features import INDICATORS
from synapse_ml.explain import shapley
from synapse_ml.paths import ARTIFACTS_DIR
from synapse_ml.performance.model import PerformanceForecastModel
from synapse_ml.promotion.model import PromotionReadinessModel

ATTRITION = ARTIFACTS_DIR / "attrition" / "attrition_model.joblib"
needs_attrition = pytest.mark.skipif(
    not ATTRITION.exists(), reason="no trained attrition model — execute notebooks/01_attrition_model.ipynb first"
)

ERP_EMPLOYEE = {
    "employment_type": "regular",
    "tenure_years": 1.9,
    "monthly_salary": 22_076.0,
    "ever_promoted": 1,
    "years_since_promotion": 0.67,
    "absences_90d": 7,
    "lates_90d": 0,
}


# ---- the Shapley computation itself ------------------------------------------------------


def test_shapley_values_of_a_linear_score_are_its_terms():
    weights = {"a": 2.0, "b": -3.0, "c": 0.5}
    typical = {"a": 1.0, "b": 1.0, "c": 1.0}

    def predict(rows):
        return [sum(weights[k] * row[k] for k in weights) for row in rows]

    [(base, contributions)] = shapley([{"a": 4.0, "b": 2.0, "c": 1.0}], [["a", "b", "c"]], typical, predict)

    assert base == pytest.approx(-0.5)
    assert contributions == pytest.approx({"a": 6.0, "b": -3.0, "c": 0.0})


def test_shapley_values_add_up_and_split_an_interaction_evenly():
    def predict(rows):
        return [row["a"] * row["b"] + row["c"] ** 2 for row in rows]

    row = {"a": 3.0, "b": 2.0, "c": 4.0}
    typical = {"a": 0.0, "b": 0.0, "c": 1.0}
    [(base, contributions)] = shapley([row], [["a", "b", "c"]], typical, predict)

    assert base + sum(contributions.values()) == pytest.approx(predict([row])[0])
    # a·b is zero unless both are revealed: the two share it equally.
    assert contributions["a"] == pytest.approx(contributions["b"]) == pytest.approx(3.0)


def test_an_input_left_out_is_never_a_reason_and_keeps_its_value():
    def predict(rows):
        return [row["a"] + row["b"] for row in rows]

    [(base, contributions)] = shapley([{"a": 5.0, "b": 9.0}], [["a"]], {"a": 1.0, "b": 0.0}, predict)

    assert set(contributions) == {"a"}
    assert base == pytest.approx(10.0)  # b stays the row's own


# ---- attrition: survey evidence ---------------------------------------------------------


def test_the_evidence_table_counts_who_left_per_band():
    X = pd.DataFrame(
        {
            "absences_90d": [0, 0, 1, 2, 7, 8],
            "employment_type": ["regular", "regular", "regular", "part_time", "part_time", "regular"],
        }
    )
    table = evidence.profile(X, [0, 1, 0, 0, 1, 1], source="survey")

    assert table["people"] == 6 and table["left"] == 3 and table["overall_rate"] == 0.5
    assert table["features"]["absences_90d"] == [
        {"band": "none", "people": 2, "left": 1, "rate": 0.5},
        {"band": "1–2 days", "people": 2, "left": 0, "rate": 0.0},
        {"band": "6–10 days", "people": 2, "left": 2, "rate": 1.0},
    ]
    assert {row["band"] for row in table["features"]["employment_type"]} == {"part_time", "regular"}


def test_evidence_says_whether_the_data_points_the_way_the_model_does():
    table = evidence.profile(pd.DataFrame({"absences_90d": [0, 0, 7, 7]}), [0, 0, 1, 1], source="survey")

    raises = evidence.describe(table, "absences_90d", 7, 0, contribution=12.0)
    assert raises["rate"] == 1.0 and raises["typical_rate"] == 0.0 and raises["consistent"] is True
    assert evidence.describe(table, "absences_90d", 7, 0, contribution=-4.0)["consistent"] is False
    assert evidence.describe(None, "absences_90d", 7, 0, contribution=1.0) is None


@pytest.fixture(scope="module")
def client():
    with TestClient(app) as test_client:
        yield test_client


@needs_attrition
def test_attrition_contributions_add_up_to_the_score():
    model = service_registry.get("attrition")
    typical = _baseline(model)
    rows = model.frame([ERP_EMPLOYEE]).to_dict("records")
    names = [[f for f in model.features if f in typical]]

    [(base, contributions)] = shapley(
        rows, names, typical, lambda batch: model.pipeline.predict_proba(model.frame(batch))[:, 1] * 100
    )
    score = model.pipeline.predict_proba(model.frame([ERP_EMPLOYEE]))[0, 1] * 100

    assert base + sum(contributions.values()) == pytest.approx(score, abs=1e-9)


@needs_attrition
def test_attrition_reasons_carry_values_bands_and_survey_evidence(client):
    response = client.post("/predict/attrition", json={"instances": [{"ref": "1", "features": ERP_EMPLOYEE}]})
    body = response.json()
    [result] = body["results"]

    assert result["baseline"] == body["reference"]["baseline"]
    shown = sum(f["impact"] for f in result["factors"]) * 100
    # Only effects under the floor are left out of the list.
    hidden = len(ATTRITION_FEATURES) * FACTOR_FLOOR * 100
    assert abs(result["baseline"] + shown - result["score"]) <= hidden

    for factor in result["factors"]:
        if factor["feature"] in INDICATORS:  # an indicator's value is its level, from its inputs
            assert factor["value"] == indicators.level_label(factor["feature"], ERP_EMPLOYEE)
            assert factor["components"] == {k: ERP_EMPLOYEE[k] for k in INDICATORS[factor["feature"]]}
        else:
            assert factor["value"] == pytest.approx(ERP_EMPLOYEE[factor["feature"]]) or factor["value"] == ERP_EMPLOYEE[factor["feature"]]
        assert factor["band"] == evidence.band_label(factor["feature"], factor["value"])
        assert factor["band"] != factor["typical_band"]  # the typical band moves nothing
        assert 0 <= factor["evidence"]["rate"] <= 1 and factor["evidence"]["people"] > 0


@needs_attrition
def test_withdrawal_is_one_reason_with_the_survey_s_rate_at_its_level(client):
    withdrawn = {**ERP_EMPLOYEE, "absences_90d": 8, "lates_90d": 6}
    [result] = client.post("/predict/attrition", json={"instances": [{"ref": "1", "features": withdrawn}]}).json()["results"]
    [reason] = [f for f in result["factors"] if f["feature"] == "withdrawal"]

    assert not {"absences_90d", "lates_90d"} & {f["feature"] for f in result["factors"]}
    assert reason["value"] == reason["band"] == "High" and reason["direction"] == "up"
    assert reason["group"] == "emotional"
    assert reason["components"] == {"absences_90d": 8, "lates_90d": 6}
    assert reason["evidence"]["people"] > 0


@needs_attrition
def test_each_result_reads_the_behavioural_indicators_it_can(client):
    record = {**ERP_EMPLOYEE, "overtime_hours_90d": 40}
    [result] = client.post("/predict/attrition", json={"instances": [{"ref": "1", "features": record}]}).json()["results"]
    by_key = {r["key"]: r for r in result["indicators"]}

    assert set(by_key) == {"withdrawal", "career_stagnation", "workload_strain", "effort_reward"}
    assert by_key["withdrawal"]["read"] == "direct"
    reason = next((f for f in result["factors"] if f["feature"] == "withdrawal"), None)
    if reason is not None:
        assert by_key["withdrawal"]["points"] == pytest.approx(reason["impact"] * 100, abs=0.06)
    assert by_key["career_stagnation"]["read"] == "inputs"
    # Not read by the score: a reading with no points, and the overtime that places it.
    assert by_key["workload_strain"]["read"] == "none" and by_key["workload_strain"]["points"] is None
    assert by_key["workload_strain"]["level"] == "High" and by_key["workload_strain"]["inputs"] == {"overtime_hours_90d": 40}


@needs_attrition
def test_nothing_on_record_reads_no_indicator(client):
    [result] = client.post("/predict/attrition", json={"instances": [{"ref": "1", "features": {}}]}).json()["results"]
    assert result["indicators"] is None


@needs_attrition
def test_the_attrition_reference_states_the_typical_employee_and_the_survey(client):
    response = client.post("/predict/attrition", json={"instances": [{"ref": "1", "features": {}}]})
    body = response.json()
    reference = body["reference"]

    assert body["results"][0]["score"] == pytest.approx(reference["baseline"], abs=0.1)
    assert reference["tiers"] == {"medium_from": 33.0, "high_from": 66.0}
    assert set(reference["typical"]) <= set(ATTRITION_FEATURES)
    if reference["evidence"] is not None:  # written by `python -m synapse_ml.attrition.evidence`
        assert reference["evidence"]["source"] == "survey"
        assert set(reference["evidence"]["features"]) == set(ATTRITION_FEATURES)
        assert set(reference["evidence"]["indicators"]) == {i.key for i in indicators.CATALOGUE}
    assert [d["key"] for d in reference["indicators"]] == [i.key for i in indicators.CATALOGUE]
    assert {d["key"]: d["read"] for d in reference["indicators"]}["withdrawal"] == "direct"


# ---- attrition: what the model would score with a change HR can make ---------------------


PROBATIONER = {
    "employment_type": "probationary",
    "tenure_years": 0.5,
    "monthly_salary": 14_000.0,
    "ever_promoted": 0,
    "years_since_promotion": 0.5,
    "absences_90d": 0,
    "lates_90d": 3,
}


@needs_attrition
def test_each_scenario_is_the_model_s_own_score_for_the_changed_record():
    model = service_registry.get("attrition")
    [scenarios] = service_registry.scenarios(model, [PROBATIONER])

    assert [s["key"] for s in scenarios] == ["promotion", "regular", "pay_band", "together"]
    for scenario in scenarios:
        _, [direct] = service_registry.score(model, [{**PROBATIONER, **scenario["changes"]}])
        assert scenario["score"] == pytest.approx(direct)

    by_key = {s["key"]: s["changes"] for s in scenarios}
    assert by_key["promotion"] == {"years_since_promotion": 0.0, "ever_promoted": 1}
    assert by_key["regular"] == {"employment_type": "regular"}
    # The lowest figure of the next band the model reads pay in (₱15k–25k).
    assert by_key["pay_band"] == {"monthly_salary": 15_000.0}
    assert by_key["together"] == {**by_key["promotion"], **by_key["regular"], **by_key["pay_band"]}


@needs_attrition
def test_only_changes_that_apply_to_the_record_are_scored():
    model = service_registry.get("attrition")
    settled = {"employment_type": "regular", "monthly_salary": 75_000.0, "years_since_promotion": 2.0, "ever_promoted": 1}
    part_timer = {"employment_type": "part_time"}

    [settled_scenarios, part_timer_scenarios, nothing_recorded] = service_registry.scenarios(
        model, [settled, part_timer, {}]
    )

    # Already regular, already in the top pay band: only a promotion is left, so
    # there is no "together" either.
    assert [s["key"] for s in settled_scenarios] == ["promotion"]
    # Part-time is an arrangement, not a step to regular; nothing else is recorded.
    assert part_timer_scenarios == []
    # A scenario never rests on an imputed value.
    assert nothing_recorded == []


@needs_attrition
def test_the_service_returns_the_scenarios_with_each_result(client):
    response = client.post("/predict/attrition", json={"instances": [{"ref": "1", "features": PROBATIONER}, {"ref": "2", "features": {}}]})
    [probationer, empty] = response.json()["results"]

    assert {s["key"] for s in probationer["scenarios"]} == {"promotion", "regular", "pay_band", "together"}
    assert all(0 <= s["score"] <= 100 for s in probationer["scenarios"])
    assert empty["scenarios"] is None


# ---- promotion: additive reasons and the next appraisal ----------------------------------


def promotion_workforce(n: int = 20_000, seed: int = 0) -> tuple[pd.DataFrame, pd.Series]:
    rng = np.random.default_rng(seed)
    latest = np.clip(rng.normal(70, 14, n), 40, 100)
    change = np.clip(rng.normal(2, 6, n), -22, 25)
    logit = -3.0 + 0.035 * (latest - 70) + 0.25 * (change - 2)
    promoted = rng.random(n) < 1 / (1 + np.exp(-logit))
    return pd.DataFrame({"rating_latest": latest, "rating_change": change}), pd.Series(promoted.astype(int))


@pytest.fixture(scope="module")
def promotion():
    X, y = promotion_workforce()
    return PromotionReadinessModel().fit(X, y)


def test_promotion_reasons_add_up_from_a_typical_record(promotion):
    records = [{"rating_latest": r, "rating_change": c} for r in (55, 72, 91) for c in (-8, 3, 12)] + [{"rating_latest": 80}]
    for record, result in zip(records, promotion.assess(records), strict=True):
        hidden = sum(1 for name in record if name not in {f["feature"] for f in result["factors"]})
        assert abs(result["baseline"] + sum(f["impact"] for f in result["factors"]) - result["score"]) <= hidden + 0.15
        for factor in result["factors"]:
            assert factor["value"] == pytest.approx(record[factor["feature"]])
            assert factor["typical"] == pytest.approx(promotion.typical[factor["feature"]], abs=0.01)


def test_the_outlook_rises_with_the_next_rating_and_names_where_tiers_start(promotion):
    [result] = promotion.assess([{"rating_latest": 72.0, "rating_change": 1.0}])
    outlook = result["outlook"]
    scores = [point["score"] for point in outlook["points"]]

    assert outlook["points"][0]["rating"] == 40.0 and outlook["points"][-1]["rating"] == 100.0
    assert all(b >= a for a, b in zip(scores, scores[1:]))
    assert outlook["medium_from"] <= outlook["high_from"]
    [at_high] = promotion.assess([{"rating_latest": outlook["high_from"], "rating_change": outlook["high_from"] - 72.0}])
    assert at_high["tier"] == "high"

    [repeat] = promotion.assess([{"rating_latest": 72.0, "rating_change": 0.0}])
    assert outlook["same_again"] == repeat["score"]


def test_a_one_appraisal_record_gets_an_outlook_too(promotion):
    [result] = promotion.assess([{"rating_latest": 66.0}])
    assert result["basis"] == "latest_appraisal" and result["outlook"]["points"]


def test_the_promotion_reference_places_the_tiers_on_the_score(promotion):
    reference = promotion.reference()
    assert reference["base_rate"] == pytest.approx(promotion.base_rate, abs=1e-4)
    assert reference["tier_scores"]["medium"] < reference["tier_scores"]["high"]
    assert set(reference["typical"]) == {"rating_latest", "rating_change"}


# ---- performance: every band's chance, and the curve ------------------------------------


@pytest.fixture(scope="module")
def performance():
    rng = np.random.default_rng(0)
    latest = np.clip(rng.normal(68, 14, 40_000), 40, 100)
    nxt = np.clip(latest + 2 + rng.normal(0, 6, len(latest)), 40, 100)
    return PerformanceForecastModel().fit(pd.DataFrame({"rating_latest": latest}), pd.Series(nxt))


def test_band_chances_sum_to_one_and_name_the_confidence(performance):
    for result in performance.assess([{"rating_latest": r} for r in (45, 59, 61, 79, 95)]):
        assert sum(result["chances"].values()) == pytest.approx(1.0, abs=0.002)
        assert result["chances"][result["band"]] == pytest.approx(result["confidence"], abs=0.001)


def test_the_reference_curve_is_monotone_and_holds_its_range(performance):
    curve = performance.reference()["curve"]
    forecasts = [point["forecast"] for point in curve]

    assert all(b >= a for a, b in zip(forecasts, forecasts[1:]))
    assert all(point["low"] <= point["forecast"] <= point["high"] for point in curve)
    assert performance.reference()["bands"] == {"below": 60.0, "on_track": 80.0}
