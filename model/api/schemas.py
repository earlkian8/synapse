"""Request/response contracts for the inference service."""

from __future__ import annotations

from pydantic import BaseModel, Field

FeatureValue = float | int | str | bool | None


class Instance(BaseModel):
    """One subject to score: a caller-chosen reference plus whatever features are
    known. Missing features are imputed by the model pipeline."""

    ref: str = Field(..., description="Caller reference echoed back in the result (e.g. an employee id).")
    features: dict[str, FeatureValue] = Field(default_factory=dict)


class PredictRequest(BaseModel):
    instances: list[Instance]


class Factor(BaseModel):
    feature: str
    label: str
    impact: float
    direction: str  # "up" | "down"


class Result(BaseModel):
    ref: str
    probability: float | None = None
    score: float
    tier: str | None = None
    factors: list[Factor] | None = None


class PredictResponse(BaseModel):
    model: str
    model_version: str | None = None
    results: list[Result]


class ModelInfo(BaseModel):
    kind: str
    version: str | None = None
    feature_count: int
    metrics: dict[str, float | int | str | None]


class HealthResponse(BaseModel):
    status: str
    service: str
    models: dict[str, ModelInfo]
