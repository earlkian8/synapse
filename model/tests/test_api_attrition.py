"""The attrition model as the ERP reaches it: through the inference service.

Runs against the trained artifact (``artifacts/attrition/``, produced by executing
``notebooks/01_attrition_model.ipynb``). The artifact is git-ignored, so on a fresh
checkout these are skipped with a pointer to the notebook rather than failing.
"""

from __future__ import annotations

import json

import joblib
import pytest
from fastapi.testclient import TestClient

from api.main import app
from api.main import registry as service_registry
from api.registry import OCCLUSION_FLOOR, _baseline
from synapse_ml.attrition import features, survey
from synapse_ml.paths import ARTIFACTS_DIR

ARTIFACT = ARTIFACTS_DIR / "attrition" / "attrition_model.joblib"

pytestmark = pytest.mark.skipif(
    not ARTIFACT.exists(),
    reason="no trained attrition model — execute notebooks/01_attrition_model.ipynb first",
)

# A typical employee as the Laravel AttritionFeatureMapper sends one: exact ERP
# figures, not survey bands.
ERP_EMPLOYEE = {
    "employment_type": "regular",
    "tenure_years": 4.2,
    "monthly_salary": 18_500.0,
    "ever_promoted": 0,
    "years_since_promotion": 4.2,
    "overtime_hours_90d": 7.25,
    "absences_90d": 3,
    "lates_90d": 2,
}

# The same person as the survey would have recorded them.
AS_SURVEY = {
    "employment_type": "regular",
    "tenure_years": 4.0,
    "monthly_salary": 20_000.0,
    "ever_promoted": 0,
    "years_since_promotion": 4.0,
    "overtime_hours_90d": 5.0,
    "absences_90d": 4.0,
    "lates_90d": 1.5,
}


@pytest.fixture(scope="module")
def client():
    with TestClient(app) as test_client:  # runs the lifespan, loading the registry
        yield test_client


def predict(client: TestClient, *feature_sets: dict) -> list[dict]:
    response = client.post(
        "/predict/attrition",
        json={"instances": [{"ref": str(i), "features": f} for i, f in enumerate(feature_sets)]},
    )
    assert response.status_code == 200, response.text
    body = response.json()
    assert body["model"] == "attrition"
    return body["results"]


# ── The artifact ─────────────────────────────────────────────────────────────


def test_the_artifact_is_trained_on_exactly_the_contract():
    pipeline = joblib.load(ARTIFACT)

    assert list(pipeline.named_steps["prep"].feature_names_in_) == features.FEATURES

    contract = json.loads((ARTIFACT.parent / "feature_contract.json").read_text(encoding="utf-8"))
    assert contract["features"] == features.FEATURES
    assert contract["band_edges"] == features.BAND_EDGES


def test_the_metrics_record_an_honest_evaluation():
    metrics = json.loads((ARTIFACT.parent / "metrics.json").read_text(encoding="utf-8"))

    assert metrics["algorithm"] == "RandomForestClassifier"
    assert metrics["n_rows"] == len(survey.load()[0])
    for key in ("cv_roc_auc", "cv_pr_auc", "permutation_p_value", "cross_source_roc_auc"):
        assert key in metrics
    # Better than the prior-only baseline, or it should not be served at all.
    assert metrics["cv_roc_auc"] > metrics["comparison"]["Baseline (prior)"]


# ── Serving ──────────────────────────────────────────────────────────────────


def test_health_lists_the_attrition_model(client):
    body = client.get("/health").json()

    assert "attrition" in body["models"]
    info = body["models"]["attrition"]
    assert info["kind"] == "classifier"
    assert info["feature_count"] == len(features.FEATURES)
    assert info["version"].startswith("RandomForestClassifier@")
    assert "cv_roc_auc" in info["metrics"]


def test_a_full_record_scores_with_a_tier(client):
    [result] = predict(client, ERP_EMPLOYEE)

    assert 0.0 <= result["probability"] <= 1.0
    assert result["score"] == pytest.approx(result["probability"] * 100, abs=0.05)
    assert result["tier"] in {"low", "medium", "high"}


def test_exact_erp_figures_score_exactly_like_the_matching_survey_answers(client):
    erp, surveyed = predict(client, ERP_EMPLOYEE, AS_SURVEY)

    assert erp["probability"] == pytest.approx(surveyed["probability"], abs=1e-12)


def test_a_partial_record_is_imputed_not_rejected(client):
    # An employee whose attendance is not tracked: the mapper sends five inputs.
    partial = {k: v for k, v in ERP_EMPLOYEE.items() if not k.endswith("_90d")}
    [result] = predict(client, partial)

    assert 0.0 <= result["probability"] <= 1.0


def test_an_empty_record_still_scores(client):
    [result] = predict(client, {})

    assert result["tier"] in {"low", "medium", "high"}


def test_an_unknown_employment_type_is_ignored_not_an_error(client):
    [result] = predict(client, {**ERP_EMPLOYEE, "employment_type": "intern"})

    assert 0.0 <= result["probability"] <= 1.0


def test_extreme_values_stay_in_the_outermost_band(client):
    # 400 overtime hours is still "more than 100", so it scores like 120.
    high, top_band = predict(client, {**ERP_EMPLOYEE, "overtime_hours_90d": 400},
                             {**ERP_EMPLOYEE, "overtime_hours_90d": 120})

    assert high["probability"] == pytest.approx(top_band["probability"], abs=1e-12)


def test_refs_are_echoed_in_order_for_a_batch(client):
    results = predict(client, ERP_EMPLOYEE, AS_SURVEY, {}, ERP_EMPLOYEE)

    assert [r["ref"] for r in results] == ["0", "1", "2", "3"]


# ── Explanations ─────────────────────────────────────────────────────────────


def test_factors_explain_only_what_was_recorded(client):
    # Only absences and lates are sent: everything else is imputed to its typical
    # value, so nothing else can be offered as a reason.
    [result] = predict(client, {"absences_90d": 12, "lates_90d": 12})

    features = {f["feature"] for f in result["factors"] or []}
    assert features <= {"absences_90d", "lates_90d"}


def test_factors_carry_labels_and_a_direction(client):
    [result] = predict(client, {**ERP_EMPLOYEE, "absences_90d": 12, "lates_90d": 12})

    for factor in result["factors"] or []:
        assert factor["feature"] in features.FEATURES
        assert factor["label"] == features.FEATURE_LABELS[factor["feature"]]
        assert factor["direction"] == ("up" if factor["impact"] >= 0 else "down")
        assert abs(factor["impact"]) >= OCCLUSION_FLOOR


def test_a_typical_employee_has_no_drivers(client):
    typical = _baseline(service_registry.get("attrition"))
    [result] = predict(client, {k: (v.item() if hasattr(v, "item") else v) for k, v in typical.items()})

    assert result["factors"] in (None, [])


# ── Failure modes ────────────────────────────────────────────────────────────


def test_an_empty_batch_is_rejected(client):
    assert client.post("/predict/attrition", json={"instances": []}).status_code == 422


def test_an_unknown_model_is_a_404(client):
    assert client.post("/predict/flight", json={"instances": [{"ref": "1", "features": {}}]}).status_code == 404
