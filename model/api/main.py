"""
Synapse ML Inference Service (FastAPI).

A thin, stateless HTTP layer over the trained scikit-learn pipelines. The Laravel
app calls it server-side to score employees for promotion readiness, attrition risk
and (in the same shape) performance forecasting.

Run from the ``model/`` directory inside the venv::

    .venv/Scripts/python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 8001

or simply::

    .venv/Scripts/python.exe -m api

Endpoints:
    GET  /health                 — liveness + which models are loaded
    POST /predict/{model_name}   — score a batch of instances (promotion|performance|attrition)
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException

from .registry import Registry
from .schemas import (
    HealthResponse,
    ModelInfo,
    PredictRequest,
    PredictResponse,
    Result,
)

logging.basicConfig(level=logging.INFO, format="%(asctime)s | %(levelname)s | %(message)s")
log = logging.getLogger("synapse.inference")

SERVICE_NAME = "synapse-ml-inference"

registry = Registry()

# Headline metrics surfaced on /health, per model.
_HEADLINE_METRICS = (
    "algorithm", "test_roc_auc", "test_pr_auc", "test_r2", "test_mae",
    # attrition is too small for a single held-out split; it reports repeated CV.
    "cv_roc_auc", "cv_pr_auc", "permutation_p_value",
    "tuned_threshold", "positive_rate",
    # promotion: calibration and the single-appraisal case; performance: the interval.
    "cv_ece", "cv_roc_auc_latest_only", "test_interval_coverage", "test_band_accuracy",
)


@asynccontextmanager
async def lifespan(_: FastAPI):
    registry.load()
    log.info("loaded models: %s", ", ".join(registry.models) or "(none)")
    yield


app = FastAPI(title="Synapse ML Inference", version="1.0.0", lifespan=lifespan)


@app.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    models = {
        name: ModelInfo(
            kind=model.kind,
            version=model.version,
            feature_count=len(model.features),
            metrics={k: model.metrics[k] for k in _HEADLINE_METRICS if k in model.metrics},
        )
        for name, model in registry.models.items()
    }
    return HealthResponse(
        status="ok" if registry.models else "degraded",
        service=SERVICE_NAME,
        models=models,
    )


@app.post("/predict/{model_name}", response_model=PredictResponse)
def predict(model_name: str, request: PredictRequest) -> PredictResponse:
    try:
        model = registry.get(model_name)
    except KeyError:
        raise HTTPException(status_code=404, detail=f"Unknown model '{model_name}'.") from None

    if not request.instances:
        raise HTTPException(status_code=422, detail="No instances supplied.")

    feature_dicts = [inst.features for inst in request.instances]
    results = [
        Result(ref=inst.ref, **result)
        for inst, result in zip(request.instances, registry.predict(model_name, feature_dicts), strict=True)
    ]

    warnings = []
    unknown = registry.unknown_inputs(model_name, feature_dicts)
    if unknown:
        # The caller and the model disagree about the contract. Scoring carries on
        # with what the model reads; the mismatch is reported, not swallowed.
        warnings.append(f"Ignored inputs the '{model_name}' model does not read: {', '.join(unknown)}.")
        log.warning("'%s' request carried unknown inputs: %s", model_name, ", ".join(unknown))

    declined = sum(result.status != "scored" for result in results)
    log.info("scored %d instance(s) with '%s' (%d declined)", len(results) - declined, model_name, declined)
    return PredictResponse(model=model_name, model_version=model.version, results=results, warnings=warnings)
