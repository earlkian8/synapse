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
    impact: float  # promotion: readiness points; attrition: probability
    direction: str  # "up" | "down"


class Interval(BaseModel):
    low: float
    high: float
    coverage: float  # share of outcomes the range is built to hold


class Result(BaseModel):
    ref: str
    # "scored", or "insufficient" when a required input was absent: nothing is guessed.
    status: str = "scored"
    missing: list[str] | None = None
    probability: float | None = None
    score: float | None = None
    tier: str | None = None
    # Which history the result rests on (promotion: "latest_appraisal" | "two_appraisals").
    basis: str | None = None
    factors: list[Factor] | None = None
    # Regressors: the range the actual value is expected in, the band the point
    # forecast names, and the chance the actual lands in that band.
    interval: Interval | None = None
    band: str | None = None
    confidence: float | None = None
    # Anything done to this instance's inputs (e.g. a value held at the trained range).
    warnings: list[str] = Field(default_factory=list)


class PredictResponse(BaseModel):
    model: str
    model_version: str | None = None
    results: list[Result]
    # About the request as a whole — e.g. inputs the model does not read.
    warnings: list[str] = Field(default_factory=list)


class ModelInfo(BaseModel):
    kind: str
    version: str | None = None
    feature_count: int
    metrics: dict[str, float | int | str | None]


class HealthResponse(BaseModel):
    status: str
    service: str
    models: dict[str, ModelInfo]
