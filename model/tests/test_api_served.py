"""The promotion and performance models as the ERP reaches them: through the service.

Runs against the trained artifacts (``artifacts/{promotion,performance}/``, produced by
executing notebooks 02 and 03). They are git-ignored, so on a fresh checkout these are
skipped with a pointer to the notebooks rather than failing.
"""

from __future__ import annotations

import json

import joblib
import pytest
from fastapi.testclient import TestClient

from api.main import app
from synapse_ml.paths import ARTIFACTS_DIR
from synapse_ml.performance import evaluation as performance_evaluation
from synapse_ml.promotion import evaluation as promotion_evaluation

PROMOTION = ARTIFACTS_DIR / "promotion" / "promotion_model.joblib"
PERFORMANCE = ARTIFACTS_DIR / "performance" / "performance_model.joblib"

needs_promotion = pytest.mark.skipif(
    not PROMOTION.exists(), reason="no trained promotion model — execute notebooks/03_promotion_model.ipynb first"
)
needs_performance = pytest.mark.skipif(
    not PERFORMANCE.exists(), reason="no trained performance model — execute notebooks/02_performance_model.ipynb first"
)

# As the Laravel PromotionFeatureMapper sends them.
TWO_APPRAISALS = {"rating_latest": 75.29, "rating_change": 6.2}
ONE_APPRAISAL = {"rating_latest": 75.29}
NO_APPRAISAL: dict = {}


@pytest.fixture(scope="module")
def client():
    with TestClient(app) as test_client:
        yield test_client


def predict(client, model: str, *features: dict) -> dict:
    response = client.post(
        f"/predict/{model}", json={"instances": [{"ref": str(i), "features": f} for i, f in enumerate(features)]}
    )
    assert response.status_code == 200, response.text
    return response.json()


# ---- promotion --------------------------------------------------------------------------


@needs_promotion
def test_health_reports_promotion_with_its_calibration(client):
    info = client.get("/health").json()["models"]["promotion"]
    assert info["kind"] == "classifier"
    assert info["feature_count"] == 2
    assert info["metrics"]["cv_ece"] < 0.01
    assert info["metrics"]["cv_roc_auc_latest_only"] < info["metrics"]["cv_roc_auc"]


@needs_promotion
def test_promotion_scores_declines_and_says_on_what_basis(client):
    body = predict(client, "promotion", TWO_APPRAISALS, ONE_APPRAISAL, NO_APPRAISAL)
    two, one, none = body["results"]
    assert (two["status"], two["basis"]) == ("scored", "two_appraisals")
    assert (one["status"], one["basis"]) == ("scored", "latest_appraisal")
    assert none["status"] == "insufficient" and none["missing"] == ["rating_latest"]
    assert none["score"] is None and none["tier"] is None
    for result in (two, one):
        assert 0 <= result["score"] <= 100
        assert result["tier"] in {"low", "medium", "high"}
        assert 0 < result["probability"] < 1


@needs_promotion
def test_promotion_explains_in_readiness_points_with_recorded_facts_only(client):
    [one] = predict(client, "promotion", ONE_APPRAISAL)["results"]
    assert {f["feature"] for f in one["factors"]} <= {"rating_latest"}
    for factor in one["factors"]:
        assert abs(factor["impact"]) >= 1.0
        assert factor["direction"] == ("up" if factor["impact"] >= 0 else "down")


@needs_promotion
def test_old_contract_inputs_are_reported_not_silently_used(client):
    body = predict(client, "promotion", {**TWO_APPRAISALS, "department": "Engineering", "years_since_promotion": 3})
    assert body["warnings"] == ["Ignored inputs the 'promotion' model does not read: department, years_since_promotion."]
    [plain] = predict(client, "promotion", TWO_APPRAISALS)["results"]
    assert body["results"][0]["score"] == plain["score"]


@needs_promotion
def test_promotion_is_deterministic_and_order_free(client):
    records = [TWO_APPRAISALS, ONE_APPRAISAL, NO_APPRAISAL, {"rating_latest": 92, "rating_change": -3}]
    forward = predict(client, "promotion", *records)["results"]
    backward = predict(client, "promotion", *reversed(records))["results"]
    strip = lambda r: {k: v for k, v in r.items() if k != "ref"}
    assert [strip(r) for r in forward] == [strip(r) for r in reversed(backward)]
    assert forward == predict(client, "promotion", *records)["results"]


@needs_promotion
def test_the_served_promotion_model_is_monotone():
    model = joblib.load(PROMOTION)
    result = promotion_evaluation.monotonicity(model)
    assert sum(result["violations"].values()) == 0, result


@needs_promotion
def test_the_promotion_contract_on_disk_matches_the_model():
    model = joblib.load(PROMOTION)
    on_disk = json.loads((ARTIFACTS_DIR / "promotion" / "feature_contract.json").read_text(encoding="utf-8"))
    assert list(on_disk["inputs"]) == model.features
    assert on_disk["tiers"]["high_from"] == pytest.approx(model.tiers["high"])


# ---- performance ------------------------------------------------------------------------


@needs_performance
def test_health_reports_performance_with_its_interval_coverage(client):
    info = client.get("/health").json()["models"]["performance"]
    assert info["kind"] == "regressor"
    assert info["feature_count"] == 1
    assert info["metrics"]["test_interval_coverage"] == pytest.approx(0.8, abs=0.02)


@needs_performance
def test_performance_forecasts_with_interval_band_and_confidence(client):
    body = predict(client, "performance", {"rating_latest": 65.17}, {"rating_latest": 25}, {})
    ordinary, below_reference, none = body["results"]
    assert ordinary["status"] == "scored"
    assert ordinary["interval"]["low"] <= ordinary["score"] <= ordinary["interval"]["high"]
    assert ordinary["interval"]["coverage"] == 0.8
    assert ordinary["band"] in {"below", "on_track", "exceeds"}
    assert 0 <= ordinary["confidence"] <= 1
    assert ordinary["warnings"] == []
    assert below_reference["warnings"] and "read as 40%" in below_reference["warnings"][0]
    assert none["status"] == "insufficient" and none["score"] is None and none["interval"] is None


@needs_performance
def test_the_served_forecast_is_monotone():
    result = performance_evaluation.monotonicity(joblib.load(PERFORMANCE))
    assert result["point_decreases"] == 0 and result["interval_never_excludes_point"], result


@needs_performance
def test_an_unknown_model_is_a_404(client):
    assert client.post("/predict/salary", json={"instances": [{"ref": "1", "features": {}}]}).status_code == 404


@needs_performance
def test_an_empty_batch_is_refused(client):
    assert client.post("/predict/performance", json={"instances": []}).status_code == 422
