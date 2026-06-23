"""
Synapse ML Inference Service (FastAPI).

A thin, stateless HTTP layer over the trained scikit-learn pipelines. The Laravel
app calls it server-side to score employees for promotion readiness (and, in the
same shape, attrition risk and performance forecasting).

Run from the ``model/`` directory inside the venv::

    .venv/Scripts/python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 8001

or simply::

    .venv/Scripts/python.exe -m api

Endpoints:
    GET  /health                 — liveness + which models are loaded
    POST /predict/{model_name}   — score a batch of instances (promotion|attrition|performance)
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException

from .registry import Registry, tier_for
from .schemas import (
    Factor,
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
    "tuned_threshold", "positive_rate",
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
        raise HTTPException(status_code=404, detail=f"Unknown model '{model_name}'.")

    if not request.instances:
        raise HTTPException(status_code=422, detail="No instances supplied.")

    feature_dicts = [inst.features for inst in request.instances]
    probabilities, scores = registry.score(model_name, feature_dicts)
    factor_sets = registry.contributions(model_name, feature_dicts)

    results: list[Result] = []
    for inst, proba, score, factors in zip(request.instances, probabilities, scores, factor_sets):
        results.append(
            Result(
                ref=inst.ref,
                probability=proba,
                score=score,
                tier=tier_for(proba) if proba is not None else None,
                factors=[Factor(**f) for f in factors] if factors else None,
            )
        )

    log.info("scored %d instance(s) with '%s'", len(results), model_name)
    return PredictResponse(model=model_name, model_version=model.version, results=results)
