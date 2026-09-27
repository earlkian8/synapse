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
    POST /predict/{model_name}   — score a batch of instances (promotion|performance|attrition),
                                   with the reference model or — given a ``variant`` — an
                                   organisation's own
    POST /train/{model_name}     — fit a model on an organisation's own examples, judge it
                                   against the reference on those records, and store it
                                   when it passes (``synapse_ml.local``)
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException

import numpy as np

from synapse_ml.local import store as local_store
from synapse_ml.local import training as local_training

from .registry import Registry
from .schemas import (
    HealthResponse,
    ModelInfo,
    PredictRequest,
    PredictResponse,
    Result,
    TrainRequest,
    TrainResponse,
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

    if request.variant is not None:
        try:
            model = registry.local(model_name, request.variant.tenant, request.variant.version)
        except local_store.UnknownLocalModel as exc:
            # Never fall back to the reference silently: the organisation chose its own
            # model, and scoring it with another would misreport whose model spoke.
            raise HTTPException(status_code=404, detail=f"Unknown local model: {exc}.") from None

    if not request.instances:
        raise HTTPException(status_code=422, detail="No instances supplied.")

    feature_dicts = [inst.features for inst in request.instances]
    results = [
        Result(ref=inst.ref, **result)
        for inst, result in zip(request.instances, registry.predict_with(model, feature_dicts), strict=True)
    ]

    warnings = []
    unknown = registry.unknown_inputs(model, feature_dicts)
    if unknown:
        # The caller and the model disagree about the contract. Scoring carries on
        # with what the model reads; the mismatch is reported, not swallowed.
        warnings.append(f"Ignored inputs the '{model_name}' model does not read: {', '.join(unknown)}.")
        log.warning("'%s' request carried unknown inputs: %s", model_name, ", ".join(unknown))

    declined = sum(result.status != "scored" for result in results)
    log.info("scored %d instance(s) with '%s' (%d declined)", len(results) - declined, model_name, declined)
    return PredictResponse(model=model_name, model_version=model.version, results=results, warnings=warnings)


@app.post("/train/{model_name}", response_model=TrainResponse)
def train(model_name: str, request: TrainRequest) -> TrainResponse:
    """Fit ``model_name`` on one organisation's examples and judge it against the
    reference model on those same records. Stored only when it passes."""
    try:
        reference = registry.get(model_name)
    except KeyError:
        # The check needs the reference model to compare against.
        raise HTTPException(status_code=404, detail=f"Unknown model '{model_name}'.") from None
    if not local_store.TENANT.fullmatch(request.tenant):
        raise HTTPException(status_code=422, detail="Malformed tenant key.")

    def today(records: list[dict]) -> np.ndarray:
        results = registry.predict_with(reference, records)
        key = "score" if reference.kind == "regressor" else "probability"
        return np.array([np.nan if r[key] is None else r[key] for r in results], dtype=float)

    try:
        outcome = local_training.train(model_name, [row.model_dump() for row in request.rows], today)
    except local_training.TrainingRefused as exc:
        raise HTTPException(status_code=422, detail=f"Cannot train: {exc}.") from None

    version = None
    if outcome.verdict == "passed":
        version = local_store.save(
            request.tenant,
            model_name,
            outcome.fitted,
            {"algorithm": reference.metrics.get("algorithm"), "comparison": outcome.comparison,
             "counts": outcome.counts, "findings": outcome.findings},
        )
    log.info("trained '%s' for %s: %s (%s)", model_name, request.tenant, outcome.verdict, outcome.comparison)
    return TrainResponse(
        model=model_name,
        tenant=request.tenant,
        verdict=outcome.verdict,
        version=version,
        findings=outcome.findings,
        comparison=outcome.comparison,
        counts=outcome.counts,
    )
