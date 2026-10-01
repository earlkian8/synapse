"""Request/response contracts for the inference service."""

from __future__ import annotations

from pydantic import BaseModel, Field

FeatureValue = float | int | str | bool | None


class Instance(BaseModel):
    """One subject to score: a caller-chosen reference plus whatever features are
    known. Missing features are imputed by the model pipeline."""

    ref: str = Field(..., description="Caller reference echoed back in the result (e.g. an employee id).")
    features: dict[str, FeatureValue] = Field(default_factory=dict)


class Variant(BaseModel):
    """Score with an organisation's own model instead of the reference one."""

    tenant: str = Field(..., description="The organisation's key, e.g. 'org-12'.")
    version: str = Field(..., description="The version /train returned for it.")


class PredictRequest(BaseModel):
    instances: list[Instance]
    variant: Variant | None = None


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


class TrainRow(BaseModel):
    """One labelled example from the organisation's own records."""

    group: str = Field(..., description="The employee the example belongs to; never split across a test.")
    features: dict[str, FeatureValue] = Field(default_factory=dict)
    # 1/0 for promotion (promoted before the next appraisal) and attrition (resigned
    # within the year); the next rating, 0–100, for performance.
    outcome: float
    # Performance: the review cycle of the rating being predicted.
    cycle: str | None = None


class TrainRequest(BaseModel):
    tenant: str
    rows: list[TrainRow]


class TrainResponse(BaseModel):
    model: str
    tenant: str
    # "passed": a model was fitted and stored under ``version``; "failed": nothing stored.
    verdict: str
    version: str | None = None
    # Plain-language sentences, one per check, written for the HR reader.
    findings: list[str]
    # The measure it was judged on, for the local model, the reference and knowing
    # nothing, and how often it came out ahead across resamples of the people.
    comparison: dict[str, float | int | str | None]
    counts: dict[str, int]
