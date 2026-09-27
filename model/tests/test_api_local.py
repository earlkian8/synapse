"""An organisation's own model as the ERP reaches it: trained through ``/train``, then
served through ``/predict`` only when the request names it.

Needs the trained reference promotion model (the check compares against it); skipped
with a pointer on a fresh checkout. Local models are written to a temporary store.
"""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from api.main import app, registry
from synapse_ml.local import store
from synapse_ml.paths import ARTIFACTS_DIR

from test_local_training import promotion_rows

pytestmark = pytest.mark.skipif(
    not (ARTIFACTS_DIR / "promotion" / "promotion_model.joblib").exists(),
    reason="no trained promotion model — execute notebooks/03_promotion_model.ipynb first",
)


@pytest.fixture
def client(tmp_path, monkeypatch):
    monkeypatch.setattr(store, "LOCAL_DIR", tmp_path)
    registry.local_models.clear()
    with TestClient(app) as test_client:
        yield test_client
    registry.local_models.clear()


def test_a_model_trained_on_an_organisations_records_serves_only_that_organisation(client):
    trained = client.post("/train/promotion", json={"tenant": "org-7", "rows": promotion_rows()})
    assert trained.status_code == 200, trained.text
    body = trained.json()
    assert body["verdict"] == "passed", body["findings"]
    assert body["version"] and body["counts"]["people"] == 250
    assert body["comparison"]["local"] < body["comparison"]["reference"]

    instances = [{"ref": "1", "features": {"rating_latest": 80, "rating_change": 8}}]
    own = client.post("/predict/promotion", json={"instances": instances,
                                                  "variant": {"tenant": "org-7", "version": body["version"]}})
    assert own.status_code == 200, own.text
    assert own.json()["model_version"] == f"local:{body['version']}"

    general = client.post("/predict/promotion", json={"instances": instances}).json()
    assert general["model_version"] != own.json()["model_version"]
    assert general["results"][0]["probability"] != own.json()["results"][0]["probability"]

    # Another organisation cannot reach it.
    other = client.post("/predict/promotion", json={"instances": instances,
                                                    "variant": {"tenant": "org-8", "version": body["version"]}})
    assert other.status_code == 404


def test_a_failed_check_stores_nothing(client, tmp_path):
    body = client.post("/train/promotion", json={"tenant": "org-7", "rows": promotion_rows(signal=False)}).json()
    assert body["verdict"] == "failed" and body["version"] is None
    assert not any(tmp_path.rglob("model.joblib"))


def test_below_the_minimums_the_service_refuses_to_train(client):
    response = client.post("/train/promotion", json={"tenant": "org-7", "rows": promotion_rows(people=30)})
    assert response.status_code == 422
    assert "below the minimums" in response.json()["detail"]


def test_a_malformed_tenant_is_refused(client):
    assert client.post("/train/promotion", json={"tenant": "../x", "rows": promotion_rows()}).status_code == 422
